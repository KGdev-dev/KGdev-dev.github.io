<?php

$required_roles = ['admin'];
require_once __DIR__ . '/../check_session.php';

$logoutUrl = kasi_exchange_url('logout.php');

$userName = (string) ($_SESSION['user_name'] ?? 'Admin');

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 860px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <p class="text-uppercase text-muted small mb-1">Admin Area</p>
            <h1 class="h4 mb-0">Dashboard</h1>
        </div>
        <a href="<?= htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark btn-sm">Log Out</a>
    </div>

    <div class="row g-3">
        <div class="col-12 col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-4">
                    <h2 class="h6">Signed in as</h2>
                    <p class="mb-0"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-4">
                    <h2 class="h6">Access scope</h2>
                    <p class="mb-0 text-muted">This is a protected admin scaffold for management tools, moderation, and reporting.</p>
                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
