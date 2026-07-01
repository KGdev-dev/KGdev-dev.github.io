<?php

require_once __DIR__ . '/path_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['csrf_token'];

$sessionId = session_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$userName = (string) ($_SESSION['user_name'] ?? 'Guest');

$items = [];

try {
    $statement = $pdo->prepare(
           'SELECT si.id AS saved_item_id, si.created_at, p.id AS product_id, p.seller_id, p.title, p.description, p.price, p.size, p.image_path, u.full_name AS seller_name
         FROM saved_items si
         INNER JOIN products p ON p.id = si.product_id
            LEFT JOIN users u ON u.id = p.seller_id
         WHERE si.session_id = :session_id OR si.user_id = :user_id
         ORDER BY si.created_at DESC, si.id DESC'
    );
    $statement->execute([
        ':session_id' => $sessionId,
        ':user_id' => $userId,
    ]);
    $items = $statement->fetchAll();
} catch (Throwable $e) {
    $items = [];
}

function kasi_exchange_saved_image_url(?string $imagePath): string
{
    $safePath = trim((string) $imagePath);

    if ($safePath === '') {
        return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600"><rect width="800" height="600" fill="#f8f1e3"/><text x="400" y="300" text-anchor="middle" font-family="Arial, sans-serif" font-size="28" fill="#85776a">No image available</text></svg>');
    }

    return kasi_exchange_url(ltrim(str_replace('\\', '/', $safePath), '/'));
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Saved Items</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5 kasi-home-shell">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <p class="kasi-eyebrow mb-2">Saved Items</p>
            <h1 class="kasi-home-title mb-0">Your saved list</h1>
        </div>
        <a href="<?= htmlspecialchars(kasi_exchange_url('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="kasi-inline-link">Back to Shop</a>
    </div>

    <?php if ($items === []): ?>
        <div class="kasi-empty-state py-5">
            <div>
                <h2 class="h5 mb-2">Nothing has been saved yet.</h2>
                <p class="text-muted small mb-3">Tap the heart link from the home page to build a wishlist for later.</p>
                <a href="<?= htmlspecialchars(kasi_exchange_url('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-cta">Browse products</a>
            </div>
        </div>
    <?php else: ?>
        <div class="d-grid gap-2">
            <?php foreach ($items as $item): ?>
                <?php
                    $savedProductId = (int) ($item['product_id'] ?? 0);
                    $sellerId = (int) ($item['seller_id'] ?? 0);
                    $sellerName = trim((string) ($item['seller_name'] ?? ''));
                    if ($sellerName === '') {
                        $sellerName = 'Unknown seller';
                    }
                    $detailsUrl = kasi_exchange_url('product_details.php') . '?id=' . urlencode((string) $savedProductId);
                ?>
                <article class="d-flex align-items-center justify-content-between p-3 mb-2" style="background: #ffffff; border: 1px solid rgba(15, 23, 42, 0.08); border-radius: 16px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06); gap: 1rem;">
                    <div class="d-flex align-items-center gap-3 flex-grow-1 min-w-0">
                        <a href="<?= htmlspecialchars($detailsUrl, ENT_QUOTES, 'UTF-8') ?>" class="flex-shrink-0 d-inline-block">
                            <img src="<?= htmlspecialchars(kasi_exchange_saved_image_url((string) ($item['image_path'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($item['title'] ?? 'Saved product image'), ENT_QUOTES, 'UTF-8') ?>" style="width: 65px; height: 65px; object-fit: cover; border-radius: 10px; display: block;">
                        </a>

                        <div class="min-w-0">
                            <a href="<?= htmlspecialchars($detailsUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-dark d-block text-truncate" title="<?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <h2 class="h6 mb-1 fw-semibold text-truncate"><?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
                            </a>
                            <div class="fw-semibold text-dark mb-1">R <?= htmlspecialchars(number_format((float) ($item['price'] ?? 0), 2, '.', ' '), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-muted small text-truncate">Seller: <?= htmlspecialchars($sellerName, ENT_QUOTES, 'UTF-8') ?> (ID: <?= htmlspecialchars((string) $sellerId, ENT_QUOTES, 'UTF-8') ?>)</div>
                        </div>
                    </div>

                    <form action="<?= htmlspecialchars(kasi_exchange_url('toggle_save.php'), ENT_QUOTES, 'UTF-8') ?>" method="POST" class="flex-shrink-0 ms-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) $savedProductId, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-link p-0 text-decoration-none fw-semibold" style="color: #c43c4c; font-size: 0.92rem;">Remove</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>