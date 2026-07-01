<?php

$required_roles = [];
require_once __DIR__ . '/check_session.php';

if (($_SESSION['role'] ?? '') !== 'seller') {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/path_helpers.php';

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

$sellerId = (int) ($_SESSION['user_id'] ?? 0);
$sellerName = (string) ($_SESSION['user_name'] ?? 'Seller');

$items = [];

try {
    $stmt = $pdo->prepare(
        'SELECT id, title, price, image_path, status
         FROM products
         WHERE seller_id = :seller_id
         ORDER BY id DESC'
    );
    $stmt->execute([':seller_id' => $sellerId]);
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
            max-width: 1180px;
        }

        .page-hero {
            background: linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
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

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="d-flex justify-content-between align-items-center px-4 pt-4 pb-3">
                <div>
                    <h2 class="h5 mb-1">Your Listings</h2>
                    <p class="text-muted mb-0">Items are ordered from newest to oldest.</p>
                </div>
                <span class="badge text-bg-light border"><?= htmlspecialchars((string) count($items), ENT_QUOTES, 'UTF-8') ?> items</span>
            </div>

            <?php if ($items === []): ?>
                <div class="px-4 pb-4">
                    <div class="alert alert-secondary mb-0" role="alert">No items have been listed by this seller yet.</div>
                </div>
            <?php else: ?>
                <div class="table-responsive d-none d-md-block">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" class="ps-4">Image</th>
                                <th scope="col">Title</th>
                                <th scope="col">Price</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <?php $status = (string) ($item['status'] ?? ''); ?>
                                <tr>
                                    <td class="ps-4">
                                        <img src="<?= htmlspecialchars(kasi_exchange_seller_dashboard_image_url((string) ($item['image_path'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($item['title'] ?? 'Item image'), ENT_QUOTES, 'UTF-8') ?>" class="thumb shadow-sm">
                                    </td>
                                    <td class="fw-medium"><?= htmlspecialchars((string) ($item['title'] ?? 'Untitled Item'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>R <?= htmlspecialchars(number_format((float) ($item['price'] ?? 0), 2, '.', ' '), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="badge rounded-pill <?= htmlspecialchars(kasi_exchange_seller_dashboard_status_badge($status), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(kasi_exchange_seller_dashboard_status_label($status), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-md-none px-4 pb-4">
                    <?php foreach ($items as $item): ?>
                        <?php $status = (string) ($item['status'] ?? ''); ?>
                        <div class="card border-0 shadow-sm item-card">
                            <div class="card-body p-3">
                                <div class="d-flex gap-3 align-items-start">
                                    <img src="<?= htmlspecialchars(kasi_exchange_seller_dashboard_image_url((string) ($item['image_path'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($item['title'] ?? 'Item image'), ENT_QUOTES, 'UTF-8') ?>" class="thumb shadow-sm flex-shrink-0">
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold mb-1"><?= htmlspecialchars((string) ($item['title'] ?? 'Untitled Item'), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="text-muted small mb-2">R <?= htmlspecialchars(number_format((float) ($item['price'] ?? 0), 2, '.', ' '), ENT_QUOTES, 'UTF-8') ?></div>
                                        <span class="badge rounded-pill <?= htmlspecialchars(kasi_exchange_seller_dashboard_status_badge($status), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(kasi_exchange_seller_dashboard_status_label($status), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>