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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . kasi_exchange_url('index.php'));
    exit;
}

$productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$requestedQuantity = filter_var($_POST['quantity'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
$returnTo = trim((string) ($_POST['return_to'] ?? ''));
$submittedToken = (string) ($_POST['csrf_token'] ?? '');

if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $submittedToken)) {
    $_SESSION['flash_error'] = 'Invalid form submission.';
    header('Location: ' . kasi_exchange_url('index.php'));
    exit;
}

if ($productId === false || $productId === null) {
    $_SESSION['flash_error'] = 'Invalid product.';
    header('Location: ' . kasi_exchange_url('index.php'));
    exit;
}

if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if (empty($_SESSION['cart_quantities']) || !is_array($_SESSION['cart_quantities'])) {
    $_SESSION['cart_quantities'] = [];
}

$cart = array_values(array_filter(
    array_map('intval', $_SESSION['cart']),
    static fn (int $value): bool => $value > 0
));

$quantity = (int) ($_SESSION['cart_quantities'][$productId] ?? 0);

if ($requestedQuantity === false || $requestedQuantity === null) {
    $requestedQuantity = 1;
}

if ($quantity <= 0) {
    $quantity = (int) $requestedQuantity;
} elseif ($quantity < 5) {
    $quantity += (int) $requestedQuantity;
}

if (!in_array($productId, $cart, true)) {
    $cart[] = $productId;
}

$_SESSION['cart'] = array_values(array_unique($cart));
$_SESSION['cart_quantities'][$productId] = min(5, $quantity);

$cartCount = 0;
foreach ($_SESSION['cart_quantities'] as $cartQuantity) {
    $cartCount += max(1, (int) $cartQuantity);
}

$_SESSION['flash_success'] = 'Item added to your bag.';
$_SESSION['cart_count'] = $cartCount;

if ($returnTo !== '' && preg_match('/^(index|view_product)\.php(?:\?[A-Za-z0-9_=&%\-]*)?$/', $returnTo) === 1) {
    header('Location: ' . kasi_exchange_url($returnTo));
    exit;
}

header('Location: ' . kasi_exchange_url('index.php'));
exit;