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

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0) {
    header('Location: ' . kasi_exchange_url('login.php') . '?return_to=' . urlencode('checkout.php'));
    exit;
}

require_once __DIR__ . '/db_connect.php';

if (!function_exists('kasi_exchange_checkout_image_url')) {
    function kasi_exchange_checkout_image_url(?string $imagePath): string
    {
        $safePath = trim((string) $imagePath);

        if ($safePath === '') {
            return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600"><rect width="800" height="600" fill="#f1f5f9"/><rect x="120" y="120" width="560" height="360" rx="28" fill="#cbd5e1"/><path d="M280 220h240l40 80v120H240V300l40-80z" fill="#94a3b8"/><circle cx="330" cy="280" r="26" fill="#e2e8f0"/><path d="M350 320h140" stroke="#e2e8f0" stroke-width="18" stroke-linecap="round"/></svg>');
        }

        return kasi_exchange_url(ltrim(str_replace('\\', '/', $safePath), '/'));
    }
}

$cart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? array_values(array_unique(array_map('intval', $_SESSION['cart']))) : [];
$cartQuantities = isset($_SESSION['cart_quantities']) && is_array($_SESSION['cart_quantities']) ? $_SESSION['cart_quantities'] : [];
$items = [];
$hubs = [];

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

            $quantity = max(1, min(5, (int) ($cartQuantities[$productId] ?? 1)));
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
    } catch (Throwable $throwable) {
        $items = [];
    }
}

try {
    $hubsStmt = $pdo->query('SELECT id, hub_name, address FROM hubs ORDER BY id ASC');
    $hubs = $hubsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $throwable) {
    $hubs = [
        ['id' => 1, 'hub_name' => 'Mofolo Spaza Hub', 'address' => '12 Mofolo Rd, Soweto'],
        ['id' => 2, 'hub_name' => 'Khayelitsha Central Hub', 'address' => 'Corner Main & Hope, Khayelitsha'],
    ];
}

if ($hubs === []) {
    $hubs = [
        ['id' => 1, 'hub_name' => 'Mofolo Spaza Hub', 'address' => '12 Mofolo Rd, Soweto'],
    ];
}

$subtotal = array_reduce(
    $items,
    static fn (float $carry, array $item): float => $carry + ((float) ($item['price'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1))),
    0.0
);
$delivery = $items !== [] ? 122.65 : 0.0;
$total = $subtotal + $delivery;
$buyerName = (string) ($_SESSION['user_name'] ?? 'Guest');
$buyerEmail = '';

try {
    $buyerStmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
    $buyerStmt->execute([':id' => (int) $_SESSION['user_id']]);
    $buyerEmail = (string) ($buyerStmt->fetchColumn() ?: '');
} catch (Throwable $throwable) {
    $buyerEmail = '';
}

$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
$flashError = (string) ($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Checkout</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <style>
        body.kasi-checkout-page {
            background:
                radial-gradient(circle at top left, rgba(255, 140, 0, 0.16), transparent 28%),
                radial-gradient(circle at top right, rgba(152, 251, 152, 0.12), transparent 24%),
                linear-gradient(135deg, #f7f1e7 0%, #fbf8f1 100%) !important;
            color: #201913;
        }

        .kasi-checkout-shell {
            max-width: 1300px;
        }

        .kasi-checkout-brand {
            font-size: 0.78rem;
            letter-spacing: 0.35em;
            text-transform: uppercase;
            color: #7f7066;
        }

        .kasi-checkout-title {
            font-family: 'Cormorant Garamond', Georgia, serif;
            font-size: clamp(2.4rem, 4vw, 4.2rem);
            line-height: 0.94;
            letter-spacing: -0.03em;
            color: #372e28;
        }

        .kasi-checkout-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(340px, 0.8fr);
            gap: 2rem;
            align-items: start;
        }

        .kasi-checkout-card,
        .kasi-summary-card {
            background: rgba(255, 255, 255, 0.86);
            border: 1px solid rgba(220, 207, 194, 0.9);
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(66, 46, 28, 0.08);
            backdrop-filter: blur(16px);
        }

        .kasi-checkout-card .card-body,
        .kasi-summary-card .card-body {
            padding: 1.5rem;
        }

        .kasi-form-label {
            font-size: 0.76rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: #8d7d70;
            font-weight: 700;
        }

        .kasi-checkout-input,
        .kasi-checkout-select {
            min-height: 50px;
            border-radius: 14px !important;
            border-color: rgba(220, 207, 194, 0.95) !important;
            background: rgba(255, 255, 255, 0.92);
        }

        .kasi-checkout-input:focus,
        .kasi-checkout-select:focus {
            box-shadow: 0 0 0 0.2rem rgba(255, 140, 0, 0.16) !important;
            border-color: rgba(255, 140, 0, 0.65) !important;
        }

        .kasi-summary-item {
            padding: 0.85rem 0;
            border-top: 1px solid rgba(220, 207, 194, 0.5);
        }

        .kasi-summary-item:first-child {
            border-top: 0;
            padding-top: 0;
        }

        .kasi-summary-thumb {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: 14px;
            background: #f3f5f7;
        }

        .kasi-summary-title-small {
            font-family: 'Cormorant Garamond', Georgia, serif;
            font-size: 1.25rem;
            line-height: 1;
            color: #342b25;
        }

        .kasi-summary-price {
            font-weight: 800;
            white-space: nowrap;
        }

        .kasi-summary-total {
            font-size: 1.1rem;
        }

        .kasi-pay-button {
            min-height: 64px;
            border-radius: 999px !important;
            font-size: 1.05rem;
            box-shadow: 0 18px 30px rgba(255, 140, 0, 0.22) !important;
        }

        .kasi-checkout-note {
            font-size: 0.9rem;
            color: #6d5c50;
        }

        @media (max-width: 991.98px) {
            .kasi-checkout-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="kasi-checkout-page">
<main class="container-fluid px-4 px-lg-5 py-4 py-lg-5 kasi-checkout-shell">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4 mb-lg-5">
        <div>
            <p class="kasi-checkout-brand mb-2">Payment</p>
            <h1 class="kasi-checkout-title mb-0">Complete your secure checkout</h1>
        </div>
        <a href="<?= htmlspecialchars(kasi_exchange_url('cart.php'), ENT_QUOTES, 'UTF-8') ?>" class="kasi-inline-link">Back to bag</a>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success kasi-cart-notice mb-4" role="alert"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="alert alert-danger kasi-cart-notice mb-4" role="alert"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($items === []): ?>
        <div class="card kasi-checkout-card">
            <div class="card-body p-5 text-center">
                <h2 class="h4 mb-2">Your bag is empty.</h2>
                <p class="text-muted mb-4">Add items to your bag before you pay.</p>
                <a href="<?= htmlspecialchars(kasi_exchange_url('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-cta">Browse products</a>
            </div>
        </div>
    <?php else: ?>
        <div class="kasi-checkout-layout">
            <section class="card kasi-checkout-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 class="h4 mb-1">Billing details</h2>
                            <p class="kasi-checkout-note mb-0">Mock card details are accepted for the escrow test flow.</p>
                        </div>
                        <span class="badge text-bg-light border">Secure</span>
                    </div>

                    <form method="post" action="<?= htmlspecialchars(kasi_exchange_url('checkout_handler.php'), ENT_QUOTES, 'UTF-8') ?>" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                        <div class="col-12">
                            <label for="buyer_name" class="form-label kasi-form-label">Name on card</label>
                            <input type="text" id="buyer_name" name="buyer_name" class="form-control kasi-checkout-input" value="<?= htmlspecialchars($buyerName, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>

                        <div class="col-12">
                            <label for="buyer_email" class="form-label kasi-form-label">Email address</label>
                            <input type="email" id="buyer_email" name="buyer_email" class="form-control kasi-checkout-input" value="<?= htmlspecialchars($buyerEmail, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>

                        <div class="col-12">
                            <label for="card_number" class="form-label kasi-form-label">Card number</label>
                            <input type="text" id="card_number" name="card_number" class="form-control kasi-checkout-input" placeholder="4242 4242 4242 4242" inputmode="numeric" autocomplete="cc-number" required>
                        </div>

                        <div class="col-md-6">
                            <label for="card_expiry" class="form-label kasi-form-label">Expiry</label>
                            <input type="text" id="card_expiry" name="card_expiry" class="form-control kasi-checkout-input" placeholder="MM / YY" autocomplete="cc-exp" required>
                        </div>

                        <div class="col-md-6">
                            <label for="card_cvc" class="form-label kasi-form-label">CVC</label>
                            <input type="text" id="card_cvc" name="card_cvc" class="form-control kasi-checkout-input" placeholder="123" inputmode="numeric" autocomplete="cc-csc" required>
                        </div>

                        <div class="col-12">
                            <label for="hub_id" class="form-label kasi-form-label">Select pickup hub</label>
                            <select id="hub_id" name="hub_id" class="form-select kasi-checkout-select" required>
                                <?php foreach ($hubs as $hub): ?>
                                    <option value="<?= htmlspecialchars((string) ($hub['id'] ?? 0), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($hub['hub_name'] ?? 'Hub'), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) ($hub['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 pt-2">
                            <button type="submit" class="btn btn-cta kasi-pay-button w-100">Pay and place in escrow</button>
                        </div>
                    </form>
                </div>
            </section>

            <aside class="card kasi-summary-card position-sticky" style="top: 1.5rem;">
                <div class="card-body">
                    <h2 class="h4 mb-3">Order summary</h2>

                    <?php foreach ($items as $item): ?>
                        <?php $lineTotal = (float) ($item['price'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1)); ?>
                        <div class="kasi-summary-item d-flex gap-3 align-items-start">
                            <img src="<?= htmlspecialchars(kasi_exchange_checkout_image_url((string) ($item['image_path'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" class="kasi-summary-thumb" alt="<?= htmlspecialchars((string) ($item['title'] ?? 'Item'), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between gap-3">
                                    <div>
                                        <div class="kasi-summary-title-small"><?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="text-muted small">Seller #<?= htmlspecialchars((string) ($item['seller_id'] ?? 0), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($item['seller_name'] ?? 'Unknown seller'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <strong class="kasi-summary-price"><?= htmlspecialchars('R' . number_format($lineTotal, 2, ',', ''), ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                                <div class="text-muted small mt-1">Qty <?= htmlspecialchars((string) max(1, (int) ($item['quantity'] ?? 1)), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($item['size'] ?? 'No size'), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="kasi-summary-item d-flex justify-content-between align-items-center">
                        <span>Item(s) total</span>
                        <strong><?= htmlspecialchars('R' . number_format($subtotal, 2, ',', ''), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="kasi-summary-item d-flex justify-content-between align-items-center">
                        <span>Delivery</span>
                        <strong><?= htmlspecialchars('R' . number_format($delivery, 2, ',', ''), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="kasi-summary-item d-flex justify-content-between align-items-center kasi-summary-total">
                        <span><strong>Total</strong></span>
                        <strong><?= htmlspecialchars('R' . number_format($total, 2, ',', ''), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>

                    <div class="mt-4 small text-muted">
                        Payment is mocked here, but submission creates the escrow transactions.
                    </div>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
