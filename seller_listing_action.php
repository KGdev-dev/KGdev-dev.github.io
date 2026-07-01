<?php

require_once __DIR__ . '/check_session.php';

if (($_SESSION['role'] ?? $_SESSION['user_role'] ?? '') !== 'seller') {
    header('Location: ' . kasi_exchange_url('index.php'));
    exit;
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/path_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . kasi_exchange_url('seller_dashboard.php'));
    exit;
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sellerId = (int) ($_SESSION['user_id'] ?? 0);

if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    $_SESSION['flash_error'] = 'Invalid CSRF token.';
    header('Location: ' . kasi_exchange_url('seller_dashboard.php'));
    exit;
}

if ($productId === false || $productId === null || $sellerId <= 0) {
    $_SESSION['flash_error'] = 'Invalid product selection.';
    header('Location: ' . kasi_exchange_url('seller_dashboard.php'));
    exit;
}

try {
    $lookup = $pdo->prepare(
        'SELECT id, status
         FROM products
         WHERE id = :product_id AND seller_id = :seller_id
         LIMIT 1'
    );
    $lookup->execute([
        ':product_id' => (int) $productId,
        ':seller_id' => $sellerId,
    ]);

    $product = $lookup->fetch();

    if ($product === false) {
        throw new RuntimeException('Listing not found.');
    }

    if (strtolower(trim((string) $product['status'])) !== 'sold') {
        throw new RuntimeException('Only sold listings can be removed.');
    }

    $update = $pdo->prepare(
        'UPDATE products
         SET status = :new_status
         WHERE id = :product_id AND seller_id = :seller_id AND status = :current_status'
    );
    $update->execute([
        ':new_status' => 'archived',
        ':product_id' => (int) $productId,
        ':seller_id' => $sellerId,
        ':current_status' => 'sold',
    ]);

    if ($update->rowCount() === 0) {
        throw new RuntimeException('Listing could not be removed.');
    }

    $_SESSION['flash_success'] = 'Listing removed from the dashboard.';
} catch (Throwable $throwable) {
    $_SESSION['flash_error'] = $throwable->getMessage();
}

header('Location: ' . kasi_exchange_url('seller_dashboard.php'));
exit;
