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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrfToken = (string) $_SESSION['csrf_token'];

$cart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? array_values(array_unique(array_map('intval', $_SESSION['cart']))) : [];
$cartQuantities = isset($_SESSION['cart_quantities']) && is_array($_SESSION['cart_quantities']) ? $_SESSION['cart_quantities'] : [];
$items = [];

if ($cart !== []) {
    try {
        $placeholders = implode(',', array_fill(0, count($cart), '?'));
        $statement = $pdo->prepare(
            "SELECT p.id, p.seller_id, p.title, p.description, p.price, p.size, p.image_path, u.full_name AS seller_name
             FROM products p
             LEFT JOIN users u ON u.id = p.seller_id
             WHERE p.id IN ($placeholders)
             ORDER BY p.id DESC"
        );
        $statement->execute($cart);
        $productMap = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $productMap[(int) $product['id']] = $product;
        }

        foreach ($cart as $productId) {
            if (!isset($productMap[$productId])) {
                continue;
            }

            $quantity = (int) ($cartQuantities[$productId] ?? 1);
            $quantity = max(1, min(5, $quantity));

            $items[] = [
                'id' => (int) $productMap[$productId]['id'],
                'seller_id' => (int) ($productMap[$productId]['seller_id'] ?? 0),
                'seller_name' => (string) ($productMap[$productId]['seller_name'] ?? 'Unknown seller'),
                'title' => (string) ($productMap[$productId]['title'] ?? ''),
                'description' => (string) ($productMap[$productId]['description'] ?? ''),
                'price' => (float) ($productMap[$productId]['price'] ?? 0),
                'size' => (string) ($productMap[$productId]['size'] ?? ''),
                'image_path' => (string) ($productMap[$productId]['image_path'] ?? ''),
                'quantity' => $quantity,
            ];
        }

        $_SESSION['cart'] = $cart;
    } catch (Throwable $e) {
        $items = [];
    }
}

function kasi_exchange_cart_image_url(?string $imagePath): string
{
    $safePath = trim((string) $imagePath);

    if ($safePath === '') {
        return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600"><rect width="800" height="600" fill="#f8f1e3"/><text x="400" y="300" text-anchor="middle" font-family="Arial, sans-serif" font-size="28" fill="#85776a">No image available</text></svg>');
    }

    return kasi_exchange_url(ltrim(str_replace('\\', '/', $safePath), '/'));
}

function kasi_exchange_cart_snippet(string $text, int $length = 132): string
{
    $cleanText = trim(preg_replace('/\s+/', ' ', $text) ?? '');

    if ($cleanText === '') {
        return 'No description available.';
    }

    if (mb_strlen($cleanText) <= $length) {
        return $cleanText;
    }

    return mb_substr($cleanText, 0, $length - 1) . '…';
}

function kasi_exchange_cart_currency(float $amount): string
{
    return 'R' . number_format($amount, 2, ',', '');
}

function kasi_exchange_cart_count_items(array $items): int
{
    $count = 0;

    foreach ($items as $item) {
        $count += max(1, (int) ($item['quantity'] ?? 1));
    }

    return $count;
}

$subtotal = array_reduce(
    $items,
    static fn (float $carry, array $item): float => $carry + ((float) ($item['price'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1))),
    0.0
);
$delivery = $items !== [] ? 122.65 : 0.0;
$total = $subtotal + $delivery;
$totalItems = kasi_exchange_cart_count_items($items);
$checkoutUrl = kasi_exchange_url('checkout.php');
$loginCheckoutUrl = kasi_exchange_url('login.php') . '?return_to=' . urlencode('checkout.php');
$proceedUrl = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0 ? $checkoutUrl : $loginCheckoutUrl;
$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
$flashError = (string) ($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Bag</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <style>
        body.kasi-bag-page {
            background: #f7f2e8 !important;
            color: #29231f;
        }

        .kasi-bag-shell {
            max-width: 1500px;
        }

        .kasi-bag-topbar {
            margin-bottom: 2.25rem;
        }

        .kasi-bag-eyebrow {
            font-size: 0.82rem;
            letter-spacing: 0.34em;
            text-transform: uppercase;
            color: #8d7d70;
        }

        .kasi-bag-title {
            font-family: 'Cormorant Garamond', Georgia, serif;
            font-size: clamp(2.5rem, 4vw, 4.1rem);
            line-height: 0.94;
            letter-spacing: -0.03em;
            color: #44392f;
        }

        .kasi-bag-continue {
            padding-top: 0.45rem;
            white-space: nowrap;
            letter-spacing: 0.22em;
            font-size: 0.82rem;
        }

        .kasi-basket-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 360px;
            gap: 3rem;
            align-items: start;
        }

        .kasi-basket-stack {
            display: grid;
            gap: 1rem;
        }

        .kasi-basket-card {
            background: #fff;
            border: 1px solid rgba(220, 207, 194, 0.85);
            border-radius: 18px;
            box-shadow: 0 14px 34px rgba(66, 46, 28, 0.06);
            overflow: hidden;
        }

        .kasi-basket-card-row {
            display: grid;
            grid-template-columns: 120px minmax(0, 1fr) auto;
            gap: 1.15rem;
            padding: 1.35rem;
            align-items: start;
        }

        .kasi-basket-image-link {
            display: block;
        }

        .kasi-basket-image {
            width: 120px;
            height: 120px;
            border-radius: 14px;
            object-fit: cover;
            background: rgba(241, 245, 249, 0.92);
        }

        .kasi-basket-meta {
            font-size: 0.7rem;
            letter-spacing: 0.28em;
            text-transform: uppercase;
            color: #9d8473;
        }

        .kasi-basket-seller {
            margin-bottom: 0.25rem;
            font-size: 0.85rem;
            font-weight: 700;
            color: #4e4238;
        }

        .kasi-basket-title {
            font-family: 'Cormorant Garamond', Georgia, serif;
            font-size: clamp(1.4rem, 2vw, 1.9rem);
            line-height: 1;
            margin-bottom: 0.55rem;
            color: #322924;
        }

        .kasi-basket-description {
            margin-bottom: 0.95rem;
            color: #5f5046;
            line-height: 1.5;
        }

        .kasi-basket-price {
            font-size: 1.55rem;
            font-weight: 800;
            color: #1f1f1f;
            white-space: nowrap;
        }

        .kasi-basket-size {
            font-size: 1rem;
            color: #6a5b50;
            white-space: nowrap;
            padding-top: 0.45rem;
        }

        .kasi-basket-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .kasi-basket-qty-wrap {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
        }

        .kasi-basket-qty-label {
            font-size: 0.92rem;
            color: #5d4f44;
            font-weight: 600;
            white-space: nowrap;
        }

        .kasi-basket-qty-select {
            min-width: 110px;
            min-height: 44px;
            border-radius: 12px !important;
            border-color: rgba(220, 207, 194, 0.95) !important;
            background: #fff !important;
        }

        .kasi-basket-action-btn {
            min-height: 44px;
            border-radius: 999px !important;
            padding-inline: 1rem;
        }

        .kasi-basket-save-btn {
            border-color: rgba(220, 207, 194, 0.95) !important;
            color: #4e4238 !important;
            background: #fff !important;
        }

        .kasi-basket-remove-btn {
            border-color: rgba(133, 119, 106, 0.32) !important;
            color: #5f5146 !important;
            background: rgba(255, 255, 255, 0.7) !important;
        }

        .kasi-cart-notice {
            border-radius: 16px;
            border: 1px solid rgba(220, 207, 194, 0.85);
            background: rgba(255, 255, 255, 0.8);
        }

        .kasi-basket-summary {
            position: sticky;
            top: 1.5rem;
            background: #fff;
            border: 1px solid rgba(220, 207, 194, 0.85);
            border-radius: 18px;
            box-shadow: 0 14px 34px rgba(66, 46, 28, 0.06);
            padding: 1.5rem;
        }

        .kasi-summary-title {
            font-size: 1.9rem;
            line-height: 1;
            margin-bottom: 1.25rem;
            color: #2d2621;
        }

        .kasi-payment-heading {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.9rem;
            color: #2f2722;
        }

        .kasi-payment-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 1.35rem;
        }

        .kasi-payment-radio {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            border: 2px solid rgba(65, 53, 46, 0.24);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            flex: 0 0 auto;
        }

        .kasi-payment-radio.is-active {
            background: #3b3341;
            border-color: #3b3341;
        }

        .kasi-payment-chip {
            min-width: 68px;
            min-height: 46px;
            padding: 0.35rem 0.65rem;
            border: 1px solid rgba(220, 207, 194, 0.95);
            border-radius: 4px;
            background: #fff;
            color: #4d4239;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.88rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .kasi-payment-chip.paypal {
            opacity: 0.4;
            text-transform: none;
        }

        .kasi-payment-chip.gpay {
            text-transform: none;
            font-weight: 700;
            letter-spacing: 0;
        }

        .kasi-summary-line {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: baseline;
            font-size: 1.05rem;
            margin-bottom: 0.85rem;
            color: #2c2520;
        }

        .kasi-summary-line.small {
            color: #5b4d43;
        }

        .kasi-summary-rule {
            border-top: 1px solid rgba(220, 207, 194, 0.9);
            margin: 1rem 0;
        }

        .kasi-summary-protection {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            font-size: 1rem;
            color: #3c342d;
            margin-bottom: 1.1rem;
        }

        .kasi-summary-check {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #3b3341;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            font-weight: 800;
            flex: 0 0 auto;
        }

        .kasi-summary-cta {
            width: 100%;
            margin-top: 1rem;
            min-height: 64px;
            font-size: 1.1rem;
            border-radius: 999px !important;
        }

        .kasi-basket-empty {
            min-height: 420px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        @media (max-width: 991.98px) {
            .kasi-basket-layout {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }

            .kasi-basket-summary {
                position: static;
            }
        }

        @media (max-width: 767.98px) {
            .kasi-basket-card-row {
                grid-template-columns: 1fr;
            }

            .kasi-basket-image {
                width: 100%;
                height: 220px;
            }

            .kasi-basket-size {
                padding-top: 0;
            }
        }
    </style>
</head>
<body class="kasi-bag-page">
<main class="container-fluid px-4 px-lg-5 py-4 py-lg-5 kasi-bag-shell">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 kasi-bag-topbar">
        <div>
            <p class="kasi-bag-eyebrow mb-2">Bag</p>
            <h1 class="kasi-bag-title mb-0">Your current bag</h1>
        </div>
        <a href="<?= htmlspecialchars(kasi_exchange_url('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="kasi-inline-link kasi-bag-continue">Continue shopping</a>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success kasi-cart-notice mb-4" role="alert"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="alert alert-danger kasi-cart-notice mb-4" role="alert"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($items === []): ?>
        <div class="kasi-basket-empty py-5">
            <div>
                <h2 class="h5 mb-2">Your bag is empty.</h2>
                <p class="text-muted small mb-3">Use Add to Bag on the home page to cache items here for later.</p>
                <a href="<?= htmlspecialchars(kasi_exchange_url('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-cta">Browse products</a>
            </div>
        </div>
    <?php else: ?>
        <div class="kasi-basket-layout">
            <section class="kasi-basket-stack">
                <?php foreach ($items as $item) { ?>
                    <?php
                        $productId = (int) ($item['id'] ?? 0);
                        $quantity = max(1, min(5, (int) ($item['quantity'] ?? 1)));
                        $detailsUrl = kasi_exchange_url('product_details.php') . '?id=' . urlencode((string) $productId);
                    ?>
                    <article class="kasi-basket-card">
                        <div class="kasi-basket-card-row">
                            <a href="<?= htmlspecialchars($detailsUrl, ENT_QUOTES, 'UTF-8') ?>" class="kasi-basket-image-link">
                                <img src="<?= htmlspecialchars(kasi_exchange_cart_image_url((string) ($item['image_path'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" class="kasi-basket-image" alt="<?= htmlspecialchars((string) ($item['title'] ?? 'Bag item image'), ENT_QUOTES, 'UTF-8') ?>">
                            </a>

                            <div>
                                <div class="kasi-basket-meta mb-2">Cached in bag</div>
                                <div class="kasi-basket-seller">Seller #<?= htmlspecialchars((string) ($item['seller_id'] ?? 0), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($item['seller_name'] ?? 'Unknown seller'), ENT_QUOTES, 'UTF-8') ?></div>
                                <h2 class="kasi-basket-title"><?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
                                <p class="kasi-basket-description mb-3"><?= htmlspecialchars(kasi_exchange_cart_snippet((string) ($item['description'] ?? '')), ENT_QUOTES, 'UTF-8') ?></p>
                                <div class="d-flex flex-wrap gap-3 align-items-center">
                                    <strong class="kasi-basket-price"><?= htmlspecialchars(kasi_exchange_cart_currency((float) ($item['price'] ?? 0) * $quantity), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span class="kasi-basket-size"><?= htmlspecialchars((string) ($item['size'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>

                                <div class="kasi-basket-actions">
                                    <form method="post" action="<?= htmlspecialchars(kasi_exchange_url('cart_action.php'), ENT_QUOTES, 'UTF-8') ?>" class="kasi-basket-qty-wrap">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="update_quantity">
                                        <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) $productId, ENT_QUOTES, 'UTF-8') ?>">
                                        <label class="kasi-basket-qty-label" for="qty-<?= htmlspecialchars((string) $productId, ENT_QUOTES, 'UTF-8') ?>">How many do you want</label>
                                        <select id="qty-<?= htmlspecialchars((string) $productId, ENT_QUOTES, 'UTF-8') ?>" name="quantity" class="form-select kasi-basket-qty-select" onchange="this.form.submit()">
                                            <?php for ($quantityOption = 1; $quantityOption <= 5; $quantityOption++): ?>
                                                <option value="<?= $quantityOption ?>" <?= $quantityOption === $quantity ? 'selected' : '' ?>><?= $quantityOption ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </form>

                                    <form method="post" action="<?= htmlspecialchars(kasi_exchange_url('cart_action.php'), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="save_later">
                                        <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) $productId, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn btn-outline-dark kasi-basket-action-btn kasi-basket-save-btn">Save for later</button>
                                    </form>

                                    <form method="post" action="<?= htmlspecialchars(kasi_exchange_url('cart_action.php'), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) $productId, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn btn-outline-secondary kasi-basket-action-btn kasi-basket-remove-btn">Remove</button>
                                    </form>
                                </div>
                            </div>

                            <div class="text-end">
                                <span class="kasi-basket-size">Item</span>
                            </div>
                        </div>
                    </article>
                <?php } ?>
            </section>

            <aside class="kasi-basket-summary">
                <div class="kasi-payment-heading">How you'll pay</div>
                <div class="kasi-payment-grid" aria-label="Payment options">
                    <span class="kasi-payment-radio"></span>
                    <span class="kasi-payment-chip">Visa</span>
                    <span class="kasi-payment-chip">Mastercard</span>
                    <span class="kasi-payment-chip">AmEx</span>
                    <span class="kasi-payment-chip">Diners</span>
                    <span class="kasi-payment-radio"></span>
                    <span class="kasi-payment-chip paypal">PayPal</span>
                    <span class="kasi-payment-radio is-active"></span>
                    <span class="kasi-payment-chip gpay">G Pay</span>
                </div>

                <div class="kasi-summary-line">
                    <span>Item(s) total</span>
                    <strong><?= htmlspecialchars(kasi_exchange_cart_currency($subtotal), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>

                <div class="kasi-summary-protection">
                    <span class="kasi-summary-check">✓</span>
                    <span>You're covered with Kasi Purchase Protection</span>
                </div>

                <div class="kasi-summary-line small">
                    <span>Delivery<br><small>(To South Africa)</small></span>
                    <strong><?= htmlspecialchars(kasi_exchange_cart_currency($delivery), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>

                <div class="kasi-summary-rule"></div>

                <div class="kasi-summary-line">
                    <span><strong>Total (<?= (int) $totalItems ?> item<?= $totalItems === 1 ? '' : 's' ?>)</strong></span>
                    <strong><?= htmlspecialchars(kasi_exchange_cart_currency($total), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>

                <a href="<?= htmlspecialchars($proceedUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-cta kasi-summary-cta d-flex align-items-center justify-content-center">Proceed to checkout</a>
            </aside>
        </div>
    <?php endif; ?>
</main>
</body>
</html>