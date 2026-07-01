<?php

$required_roles = [];
require_once __DIR__ . '/check_session.php';

if (($_SESSION['role'] ?? '') !== 'seller') {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/path_helpers.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('kasi_exchange_seller_dashboard_image_url')) {
    function kasi_exchange_seller_dashboard_image_url(?string $imagePath): string
    {
        $safePath = trim((string) $imagePath);

        if ($safePath === '') {
            return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600"><rect width="800" height="600" fill="#e2e8f0"/><rect x="120" y="120" width="560" height="360" rx="28" fill="#cbd5e1"/><path d="M280 220h240l40 80v120H240V300l40-80z" fill="#94a3b8"/><circle cx="330" cy="280" r="26" fill="#e2e8f0"/><path d="M350 320h140" stroke="#e2e8f0" stroke-width="18" stroke-linecap="round"/></svg>');
        }

        return kasi_exchange_url(ltrim(str_replace('\\', '/', $safePath), '/'));
    }
}

if (!function_exists('kasi_exchange_seller_dashboard_status_badge')) {
    function kasi_exchange_seller_dashboard_status_badge(string $status): string
    {
        return match ($status) {
            'available' => 'bg-success',
            'escrow' => 'bg-warning text-dark',
            'sold' => 'bg-secondary',
            default => 'bg-info text-dark',
        };
    }
}

if (!function_exists('kasi_exchange_seller_dashboard_status_label')) {
    function kasi_exchange_seller_dashboard_status_label(string $status): string
    {
        return match ($status) {
            'available' => 'Available',
            'escrow' => 'Escrow',
            'sold' => 'Sold',
            default => ucfirst($status),
        };
    }
}

$csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
$sellerActionUrl = kasi_exchange_url('seller_listing_action.php');
$sellerId = (int) ($_SESSION['user_id'] ?? 0);
$sellerName = (string) ($_SESSION['user_name'] ?? 'Seller');

$items = [];

try {
    $stmt = $pdo->prepare(
        'SELECT p.id, p.title, p.price, p.image_path, p.status, buyer.full_name AS buyer_name
         FROM products p
         LEFT JOIN (
             SELECT o.product_id, o.buyer_id
             FROM transactions o
             INNER JOIN (
                 SELECT product_id, MAX(id) AS latest_id
                 FROM transactions
                 GROUP BY product_id
             ) latest ON latest.product_id = o.product_id AND latest.latest_id = o.id
         ) latest_order ON latest_order.product_id = p.id
         LEFT JOIN users buyer ON buyer.id = latest_order.buyer_id
         WHERE p.seller_id = :seller_id
           AND p.status <> :hidden_status
         ORDER BY p.id DESC'
    );
    $stmt->execute([
        ':seller_id' => $sellerId,
        ':hidden_status' => 'archived',
    ]);
    $items = $stmt->fetchAll();
} catch (Throwable $throwable) {
    $items = [];
}

$logoutUrl = kasi_exchange_url('logout.php');
$homeUrl = kasi_exchange_url('index.php');

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | My Items</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <style>
        body {
            background:
                radial-gradient(circle at top left, rgba(13, 110, 253, 0.08), transparent 28%),
                radial-gradient(circle at top right, rgba(25, 135, 84, 0.08), transparent 22%),
                #f8fafc;
        }

        .dashboard-shell {
            max-width: 1320px;
        }

        .page-hero {
            background: linear-gradient(135deg, rgba(255, 250, 240, 0.9) 0%, rgba(255, 255, 255, 0.94) 100%);
        }

        .table thead th {
            font-size: 0.78rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #64748b;
        }

        .item-card + .item-card {
            margin-top: 0.75rem;
        }

        .thumb {
            width: 72px;
            height: 72px;
            object-fit: cover;
            border-radius: 0.75rem;
        }

        .inventory-panel {
            background: rgba(255, 255, 255, 0.72);
        }

        .buyer-details {
            color: #5f5146;
            font-size: 0.95rem;
        }

        .buyer-muted {
            color: #8a7c70;
        }

        .seller-remove-btn {
            min-width: 0;
            padding: 0.45rem 0.8rem;
            border-radius: 999px;
        }

        .seller-action-empty {
            color: #a8a29e;
            font-size: 1.1rem;
            line-height: 1;
        }
    </style>
</head>
<body>
<a href="index.php" class="text-muted text-decoration-none small mb-4 d-inline-block">← Back to Marketplace</a>
<main class="container py-4 py-lg-5 dashboard-shell">
    <div class="card border-0 shadow-sm page-hero mb-4">
        <div class="card-body p-4 p-lg-5">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div>
                    <p class="text-uppercase text-muted small mb-1">Seller Inventory</p>
                    <h1 class="display-6 fw-semibold mb-2">My Items</h1>
                    <p class="text-muted mb-0">A clean inventory view for <?= htmlspecialchars($sellerName, ENT_QUOTES, 'UTF-8') ?>.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">Back to Home</a>
                    <a href="<?= htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">Log Out</a>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm inventory-panel">
        <div class="card-body p-0">
            <div class="d-flex justify-content-between align-items-center px-4 pt-4 pb-3">
                <div>
                    <h2 class="h5 mb-1">Your Listings</h2>
                    <p class="text-muted mb-0">Items are ordered from newest to oldest.</p>
                </div>
                <span class="badge text-bg-light border"><?= htmlspecialchars((string) count($items), ENT_QUOTES, 'UTF-8') ?> items</span>
            </div>

            <div class="table-responsive px-4 pb-4">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="ps-0">Image</th>
                            <th scope="col">Title</th>
                            <th scope="col">Price</th>
                            <th scope="col">Status</th>
                            <th scope="col">Buyer/Seller Details</th>
                            <th scope="col" class="text-end pe-0">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($items === []): ?>
                            <tr>
                                <td colspan="6" class="py-4 text-center text-muted">No items have been listed by this seller yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $status = strtolower(trim((string) ($item['status'] ?? '')));
                                $buyerName = trim((string) ($item['buyer_name'] ?? ''));
                                $buyerDetails = '—';

                                if (in_array($status, ['escrow', 'sold'], true)) {
                                    $buyerDetails = $buyerName !== '' ? 'Bought by: ' . $buyerName : 'Bought by: Pending buyer';
                                }
                                ?>
                                <tr>
                                    <td class="ps-0">
                                        <img src="<?= htmlspecialchars(kasi_exchange_seller_dashboard_image_url((string) ($item['image_path'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($item['title'] ?? 'Item image'), ENT_QUOTES, 'UTF-8') ?>" class="thumb shadow-sm">
                                    </td>
                                    <td class="fw-medium"><?= htmlspecialchars((string) ($item['title'] ?? 'Untitled Item'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>R <?= htmlspecialchars(number_format((float) ($item['price'] ?? 0), 2, '.', ' '), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="badge rounded-pill <?= htmlspecialchars(kasi_exchange_seller_dashboard_status_badge($status), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(kasi_exchange_seller_dashboard_status_label($status), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="buyer-details <?= $buyerDetails === '—' ? 'buyer-muted' : '' ?>">
                                        <?= htmlspecialchars($buyerDetails, ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="text-end pe-0">
                                        <?php if ($status === 'sold'): ?>
                                            <form method="post" action="<?= htmlspecialchars($sellerActionUrl, ENT_QUOTES, 'UTF-8') ?>" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) $item['id'], ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm seller-remove-btn">Remove</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="seller-action-empty" aria-hidden="true">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>