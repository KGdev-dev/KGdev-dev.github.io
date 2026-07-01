<?php

require_once __DIR__ . '/path_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userName = (string) ($_SESSION['user_name'] ?? 'Guest');
$userRole = (string) ($_SESSION['user_role'] ?? 'visitor');
$userEmail = (string) ($_SESSION['user_email'] ?? '');

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | My Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5 kasi-home-shell" style="max-width: 820px;">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <p class="kasi-eyebrow mb-2">Account</p>
            <h1 class="kasi-home-title mb-0">My profile</h1>
        </div>
        <a href="<?= htmlspecialchars(kasi_exchange_url('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="kasi-inline-link">Back to Shop</a>
    </div>

    <section class="kasi-product-card p-4 p-md-5">
        <div class="row g-4 align-items-center">
            <div class="col-md-8">
                <div class="kasi-product-meta mb-2">Signed in</div>
                <h2 class="h3 mb-2"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="text-muted mb-0">Role: <?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?></p>
                <?php if ($userEmail !== ''): ?>
                    <p class="text-muted mb-0 mt-2"><?= htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-md-end">
                <a href="<?= htmlspecialchars(kasi_exchange_url('logout.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark">Log Out</a>
            </div>
        </div>
    </section>
</main>
</body>
</html>