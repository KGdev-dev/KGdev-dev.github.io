<?php

require_once __DIR__ . '/path_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

require_once __DIR__ . '/db_connect.php';

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0) {
    header('Location: ' . kasi_exchange_url('login.php') . '?return_to=' . urlencode('checkout.php'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . kasi_exchange_url('checkout.php'));
    exit;
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $submittedToken)) {
    $_SESSION['flash_error'] = 'Invalid form submission.';
    header('Location: ' . kasi_exchange_url('checkout.php'));
    exit;
}

$buyerName = trim((string) ($_POST['buyer_name'] ?? ''));
$buyerEmail = trim((string) ($_POST['buyer_email'] ?? ''));
$cardNumber = preg_replace('/\D+/', '', (string) ($_POST['card_number'] ?? ''));
$cardExpiry = trim((string) ($_POST['card_expiry'] ?? ''));
$cardCvc = preg_replace('/\D+/', '', (string) ($_POST['card_cvc'] ?? ''));
$hubId = filter_var($_POST['hub_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($buyerName === '' || $buyerEmail === '' || !filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['flash_error'] = 'Enter a valid name and email.';
    header('Location: ' . kasi_exchange_url('checkout.php'));
    exit;
}

if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
    $_SESSION['flash_error'] = 'Enter a valid mock card number.';
    header('Location: ' . kasi_exchange_url('checkout.php'));
    exit;
}

if ($cardExpiry === '' || !preg_match('/^\d{2}\s*\/\s*\d{2}$/', $cardExpiry)) {
    $_SESSION['flash_error'] = 'Enter a valid expiry date.';
    header('Location: ' . kasi_exchange_url('checkout.php'));
    exit;
}

if (strlen($cardCvc) < 3 || strlen($cardCvc) > 4) {
    $_SESSION['flash_error'] = 'Enter a valid CVC.';
    header('Location: ' . kasi_exchange_url('checkout.php'));
    exit;
}

if ($hubId === false || $hubId === null) {
    $_SESSION['flash_error'] = 'Select a pickup hub.';
    header('Location: ' . kasi_exchange_url('checkout.php'));
    exit;
}

$cart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? array_values(array_unique(array_map('intval', $_SESSION['cart']))) : [];
$cartQuantities = isset($_SESSION['cart_quantities']) && is_array($_SESSION['cart_quantities']) ? $_SESSION['cart_quantities'] : [];

if ($cart === []) {
    $_SESSION['flash_error'] = 'Your bag is empty.';
    header('Location: ' . kasi_exchange_url('cart.php'));
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, buyer_id INT NOT NULL, hub_id INT NOT NULL, quantity INT NOT NULL DEFAULT 1, status ENUM('pending','at_hub','collected','cancelled') NOT NULL DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

    try {
        $pdo->exec('ALTER TABLE transactions ADD COLUMN quantity INT NOT NULL DEFAULT 1');
    } catch (Throwable $ignored) {
        // Column already exists or database does not permit the change yet.
    }

    $placeholders = implode(',', array_fill(0, count($cart), '?'));
    $statement = $pdo->prepare(
        "SELECT id, seller_id, title, description, price, size, image_path
         FROM products
         WHERE id IN ($placeholders)
         ORDER BY id DESC"
    );
    $statement->execute($cart);

    $productMap = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $product) {
        $productMap[(int) $product['id']] = $product;
    }

    $items = [];
    foreach ($cart as $productId) {
        if (!isset($productMap[$productId])) {
            continue;
        }

        $quantity = max(1, min(5, (int) ($cartQuantities[$productId] ?? 1)));
        $items[] = [
            'id' => (int) $productMap[$productId]['id'],
            'quantity' => $quantity,
        ];
    }

    if ($items === []) {
        $_SESSION['flash_error'] = 'Your bag no longer has any available items.';
        header('Location: ' . kasi_exchange_url('cart.php'));
        exit;
    }

    $pdo->beginTransaction();

    foreach ($items as $item) {
        $update = $pdo->prepare("UPDATE products SET status = 'escrow' WHERE id = :id AND status = 'available'");
        $update->execute([':id' => $item['id']]);

        if ($update->rowCount() === 0) {
            throw new RuntimeException('One or more items are no longer available.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO transactions (product_id, buyer_id, hub_id, quantity, status) VALUES (:product_id, :buyer_id, :hub_id, :quantity, :status)'
        );
        $insert->execute([
            ':product_id' => $item['id'],
            ':buyer_id' => (int) $_SESSION['user_id'],
            ':hub_id' => $hubId,
            ':quantity' => $item['quantity'],
            ':status' => 'pending',
        ]);
    }

    $pdo->commit();

    $_SESSION['cart'] = [];
    $_SESSION['cart_quantities'] = [];
    $_SESSION['cart_count'] = 0;
    $_SESSION['flash_success'] = 'Payment received. Your items are now in escrow.';

    header('Location: ' . kasi_exchange_url('cart.php'));
    exit;
} catch (Throwable $throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['flash_error'] = 'Unable to complete checkout right now.';
    header('Location: ' . kasi_exchange_url('checkout.php'));
    exit;
}
