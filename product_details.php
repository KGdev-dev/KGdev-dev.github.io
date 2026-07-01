<?php

$required_roles = ['buyer', 'seller'];
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_connect.php';

$logoutUrl = kasi_exchange_url('logout.php');
$backUrl = kasi_exchange_url('index.php');
$productId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT id, title, description, price, size, image_path, status FROM products WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $productId]);
$product = $stmt->fetch();

function kasi_exchange_details_image_url(?string $imagePath): string
{
    $safePath = trim((string) $imagePath);

    if ($safePath === '') {
        return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600"><rect width="800" height="600" fill="#e2e8f0"/><text x="400" y="300" text-anchor="middle" font-family="Arial, sans-serif" font-size="28" fill="#64748b">No image available</text></svg>');
    }

    return kasi_exchange_url(ltrim(str_replace('\\', '/', $safePath), '/'));
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Product Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 760px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark btn-sm">Back</a>
        <a href="<?= htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark btn-sm">Log Out</a>
    </div>

    <?php if ($product === false): ?>
        <div class="alert alert-warning" role="alert">Product not found.</div>
    <?php else: ?>
        <div class="card shadow-sm border-0 overflow-hidden">
            <img src="<?= htmlspecialchars(kasi_exchange_details_image_url((string) ($product['image_path'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" class="img-fluid" alt="<?= htmlspecialchars((string) ($product['title'] ?? 'Product image'), ENT_QUOTES, 'UTF-8') ?>">
            <div class="card-body p-4 p-md-5">
                <span class="badge text-bg-primary mb-3"><?= htmlspecialchars((string) ($product['size'] ?? 'Size not listed'), ENT_QUOTES, 'UTF-8') ?></span>
                <h1 class="h4 mb-2"><?= htmlspecialchars((string) ($product['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="text-muted mb-3"><?= htmlspecialchars((string) ($product['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                <div class="d-flex justify-content-between align-items-center">
                    <strong class="fs-4">R <?= htmlspecialchars(number_format((float) ($product['price'] ?? 0), 0, '.', ' '), ENT_QUOTES, 'UTF-8') ?></strong>
                    <span class="text-muted small"><?= htmlspecialchars((string) ($product['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>
</body>
</html>