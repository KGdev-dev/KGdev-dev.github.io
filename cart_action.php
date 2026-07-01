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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . kasi_exchange_url('cart.php'));
    exit;
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $submittedToken)) {
    $_SESSION['flash_error'] = 'Invalid form submission.';
    header('Location: ' . kasi_exchange_url('cart.php'));
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));
$productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);

if ($productId === false || $productId === null) {
    $_SESSION['flash_error'] = 'Invalid product.';
    header('Location: ' . kasi_exchange_url('cart.php'));
    exit;
}

if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if (empty($_SESSION['cart_quantities']) || !is_array($_SESSION['cart_quantities'])) {
    $_SESSION['cart_quantities'] = [];
}

$cart = array_values(array_unique(array_map('intval', $_SESSION['cart'])));
$cartQuantities = $_SESSION['cart_quantities'];
$userId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$sessionId = session_id();

$removeFromCart = static function (int $id) use (&$cart, &$cartQuantities): void {
    $cart = array_values(array_filter($cart, static fn (int $cartId): bool => $cartId !== $id));
    unset($cartQuantities[$id]);
};

switch ($action) {
    case 'update_quantity':
        if ($quantity === false || $quantity === null) {
            $_SESSION['flash_error'] = 'Choose a quantity between 1 and 5.';
            header('Location: ' . kasi_exchange_url('cart.php'));
            exit;
        }

        if (!in_array($productId, $cart, true)) {
            $cart[] = $productId;
        }

        $cartQuantities[$productId] = (int) $quantity;
        $_SESSION['flash_success'] = 'Quantity updated.';
        break;

    case 'remove':
        $removeFromCart($productId);
        $_SESSION['flash_success'] = 'Item removed from your bag.';
        break;

    case 'save_later':
        try {
            $select = $pdo->prepare(
                'SELECT id FROM saved_items
                 WHERE product_id = :product_id
                   AND (session_id = :session_id OR (:user_id IS NOT NULL AND user_id = :user_id))
                 LIMIT 1'
            );
            $select->execute([
                ':product_id' => $productId,
                ':session_id' => $sessionId,
                ':user_id' => $userId,
            ]);

            if ($select->fetchColumn() === false) {
                $insert = $pdo->prepare(
                    'INSERT INTO saved_items (session_id, user_id, product_id)
                     VALUES (:session_id, :user_id, :product_id)'
                );
                $insert->execute([
                    ':session_id' => $sessionId,
                    ':user_id' => $userId,
                    ':product_id' => $productId,
                ]);
            }

            $removeFromCart($productId);
            $_SESSION['flash_success'] = 'Item saved for later.';
        } catch (Throwable $throwable) {
            $_SESSION['flash_error'] = 'Unable to save item right now.';
        }
        break;

    default:
        $_SESSION['flash_error'] = 'Unsupported cart action.';
        header('Location: ' . kasi_exchange_url('cart.php'));
        exit;
}

$_SESSION['cart'] = array_values($cart);
$_SESSION['cart_quantities'] = $cartQuantities;

$cartCount = 0;
foreach ($cartQuantities as $cartQuantity) {
    $cartCount += max(1, (int) $cartQuantity);
}

$_SESSION['cart_count'] = $cartCount;

header('Location: ' . kasi_exchange_url('cart.php'));
exit;
