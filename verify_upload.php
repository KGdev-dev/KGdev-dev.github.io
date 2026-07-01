<?php

$required_roles = ['seller'];
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/path_helpers.php';

$logoutUrl = kasi_exchange_url('logout.php');
$sellerId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    'SELECT id, seller_id, title, description, price, size, image_path, status FROM products WHERE seller_id = :seller_id ORDER BY id DESC LIMIT 1'
);
$stmt->execute([':seller_id' => $sellerId]);
$product = $stmt->fetch();

$fileExists = false;
$fileSizeKb = '';

if ($product !== false) {
    $storedPath = (string) ($product['image_path'] ?? '');
    $relativePath = ltrim(str_replace('\\', '/', $storedPath), '/');
    $imagePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);

    $fileExists = is_file($imagePath);

    if ($fileExists) {
        $fileSizeKb = number_format(filesize($imagePath) / 1024, 1);
    }
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Verify Upload</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 860px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <p class="text-uppercase text-muted small mb-1">Upload Verification</p>
            <h1 class="h4 mb-0">Latest Product Check</h1>
        </div>
        <a href="<?= htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark btn-sm">Log Out</a>
    </div>

    <?php if ($product === false): ?>
        <div class="alert alert-warning" role="alert">No products were found for this seller yet.</div>
    <?php else: ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">Database record found</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-3">ID</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars((string) $product['id'], ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt class="col-sm-3">Seller ID</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars((string) $product['seller_id'], ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt class="col-sm-3">Title</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars((string) $product['title'], ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt class="col-sm-3">Description</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars((string) $product['description'], ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt class="col-sm-3">Price</dt>
                    <dd class="col-sm-9">R<?= htmlspecialchars(number_format((float) $product['price'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt class="col-sm-3">Size</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars((string) $product['size'], ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt class="col-sm-3">Image Path</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars((string) $product['image_path'], ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt class="col-sm-3">Status</dt>
                    <dd class="col-sm-9"><?= htmlspecialchars((string) $product['status'], ENT_QUOTES, 'UTF-8') ?></dd>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">File check</h2>

                <?php if ($fileExists): ?>
                    <div class="alert alert-success mb-3" role="alert">Image file exists in the uploads directory.</div>
                    <p class="mb-0">Optimized file size: <?= htmlspecialchars($fileSizeKb, ENT_QUOTES, 'UTF-8') ?> KB</p>
                <?php else: ?>
                    <div class="alert alert-danger mb-0" role="alert">The image file referenced in the database was not found in /uploads.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
