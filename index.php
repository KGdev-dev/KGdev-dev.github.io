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

if (isset($_SESSION['user_role']) && (string) $_SESSION['user_role'] === 'seller') {
    header('Location: ' . kasi_exchange_url('seller_dashboard.php'));
    exit;
}

$logoutUrl = kasi_exchange_url('logout.php');
$uploadUrl = kasi_exchange_url('upload_product.php');
$verifyUrl = kasi_exchange_url('verify_upload.php');
$sellerDashboardUrl = kasi_exchange_url('seller_dashboard.php');

$isLoggedIn = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
$userName = $isLoggedIn ? (string) ($_SESSION['user_name'] ?? 'Guest') : 'Guest';
$userRole = $isLoggedIn ? (string) ($_SESSION['user_role'] ?? '') : '';
$dashboardUrl = $isLoggedIn
    ? match ($userRole) {
        'admin' => kasi_exchange_url('admin_dashboard.php'),
        'seller' => kasi_exchange_url('seller_dashboard.php'),
        'hub_agent' => kasi_exchange_url('hub_pending.php') . '?hub_id=1',
        default => kasi_exchange_url('index.php'),
    }
    : kasi_exchange_url('login.php');

$currentSessionId = session_id();
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$selectedSize = trim((string) ($_GET['size'] ?? ''));
$limit = 8;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = $page > 0 ? $page : 1;
$offset = ($page - 1) * $limit;
$allowedSizes = ['all', 'Age 4-5', 'Age 6-7', 'Age 8-9', 'Age 10-11', 'Age 11-12', 'Small', 'Medium', 'Large', 'XL'];
$selectedSize = in_array($selectedSize, $allowedSizes, true) ? $selectedSize : 'all';

$buyerProducts = [];
$productDetailsUrl = kasi_exchange_url('view_product.php');
$savedItemsUrl = kasi_exchange_url('saved_items.php');
$cartUrl = kasi_exchange_url('cart.php');
$accountUrl = kasi_exchange_url('account.php');
$bagCount = 0;
if (isset($_SESSION['cart_quantities']) && is_array($_SESSION['cart_quantities'])) {
    foreach ($_SESSION['cart_quantities'] as $cartQuantity) {
        $bagCount += max(1, (int) $cartQuantity);
    }
} elseif (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $bagCount = count($_SESSION['cart']);
}
$productReviewsByProduct = [];
$savedStateStmt = null;

// Load hubs for modal dropdown. If the `hubs` table doesn't exist yet, create and seed it.
$hubs = [];
try {
    $hubsStmt = $pdo->query('SELECT id, hub_name, address FROM hubs ORDER BY id ASC');
    $hubs = $hubsStmt->fetchAll();
} catch (Throwable $e) {
    // Attempt to create the hubs table and seed two mock hubs.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS hubs (id INT AUTO_INCREMENT PRIMARY KEY, hub_name VARCHAR(191) NOT NULL, address TEXT NOT NULL)");
        $insert = $pdo->prepare('INSERT INTO hubs (hub_name, address) VALUES (:name, :address)');
        $insert->execute([':name' => 'Mofolo Spaza Hub', ':address' => '12 Mofolo Rd, Soweto']);
        $insert->execute([':name' => 'Khayelitsha Central Hub', ':address' => 'Corner Main & Hope, Khayelitsha']);
        $hubsStmt = $pdo->query('SELECT id, hub_name, address FROM hubs ORDER BY id ASC');
        $hubs = $hubsStmt->fetchAll();
    } catch (Throwable $inner) {
        // Fall back to an in-memory seed if DB cannot be written.
        $hubs = [
            ['id' => 1, 'hub_name' => 'Mofolo Spaza Hub', 'address' => '12 Mofolo Rd, Soweto'],
            ['id' => 2, 'hub_name' => 'Khayelitsha Central Hub', 'address' => 'Corner Main & Hope, Khayelitsha'],
        ];
    }
}

// Ensure transactions table exists (lightweight migration)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, buyer_id INT NOT NULL, hub_id INT NOT NULL, quantity INT NOT NULL DEFAULT 1, status ENUM('pending','at_hub','collected','cancelled') NOT NULL DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
} catch (Throwable $e) {
    // ignore - best effort
}

// CSRF token for AJAX requests
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrfToken = $_SESSION['csrf_token'];

$total_stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'available'");
$total_products = $total_stmt->fetchColumn();
$total_pages = ceil($total_products / $limit);
$totalPages = max(1, (int) $total_pages);
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

try {
    $query = "SELECT p.*, u.full_name AS seller_name
              FROM products p
              LEFT JOIN users u ON u.id = p.seller_id
              WHERE p.status = 'available'
              ORDER BY p.id DESC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($query);

    // Force PHP to treat these values as strict numbers, not text strings
    $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $buyerProducts = $products;

    if ($buyerProducts !== []) {
        try {
            $productIds = array_map('intval', array_column($buyerProducts, 'id'));

            if ($productIds !== []) {
                $reviewPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
                $reviewStmt = $pdo->prepare(
                    "SELECT product_id, reviewer_name, rating, review_text
                     FROM reviews
                     WHERE product_id IN ($reviewPlaceholders)
                     ORDER BY created_at DESC, id DESC"
                );
                $reviewStmt->execute($productIds);
                while ($review = $reviewStmt->fetch()) {
                    $productReviewsByProduct[(int) $review['product_id']][] = $review;
                }
            }
        } catch (Throwable $e) {
            $productReviewsByProduct = [];
        }
    }
} catch (Throwable $e) {
    $buyerProducts = [];
}

try {
    if (is_array($_SESSION['cart_quantities'] ?? null)) {
        $bagCount = 0;
        foreach ($_SESSION['cart_quantities'] as $cartQuantity) {
            $bagCount += max(1, (int) $cartQuantity);
        }
    } elseif (is_array($_SESSION['cart'] ?? null)) {
        $bagCount = count($_SESSION['cart']);
    } else {
        $bagCount = 0;
    }
} catch (Throwable $e) {
    $bagCount = 0;
}

try {
    $savedStateStmt = $pdo->prepare(
        'SELECT 1 FROM saved_items WHERE product_id = :p_id AND (session_id = :s_id OR user_id = :u_id) LIMIT 1'
    );
} catch (Throwable $e) {
    $savedStateStmt = null;
}

function kasi_exchange_product_image_url(?string $imagePath): string
{
    $safePath = trim((string) $imagePath);

    if ($safePath === '') {
        return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600"><rect width="800" height="600" fill="#e2e8f0"/><rect x="120" y="120" width="560" height="360" rx="28" fill="#cbd5e1"/><path d="M280 220h240l40 80v120H240V300l40-80z" fill="#94a3b8"/><circle cx="330" cy="280" r="26" fill="#e2e8f0"/><path d="M350 320h140" stroke="#e2e8f0" stroke-width="18" stroke-linecap="round"/></svg>');
    }

    return kasi_exchange_url(ltrim(str_replace('\\', '/', $safePath), '/'));
}

function kasi_exchange_product_snippet(string $text, int $length = 96): string
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

function kasi_exchange_product_title(string $title): string
{
    return trim($title) !== '' ? $title : 'Untitled Uniform';
}

function kasi_exchange_review_stars(int $rating): string
{
    $safeRating = max(1, min(5, $rating));

    return str_repeat('★', $safeRating) . str_repeat('☆', 5 - $safeRating);
}

function kasi_exchange_dashboard_link_label(bool $isLoggedIn): string
{
    return $isLoggedIn ? 'Dashboard' : 'Sign In';
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Home</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <style>
        .catalog-shell {
            position: relative;
                        <article class="d-flex align-items-center justify-content-between p-3 mb-2" style="background: #ffffff; border: 1px solid rgba(15, 23, 42, 0.08); border-radius: 16px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06); gap: 1rem;">
                            <?php
                                $sellerId = (int) ($product['seller_id'] ?? 0);
                                $sellerName = trim((string) ($product['seller_name'] ?? ''));
                                if ($sellerName === '') {
                                    $sellerName = 'Unknown seller';
                                }
                            ?>
                            <div class="d-flex align-items-center gap-3 flex-grow-1 min-w-0">
                                <a href="<?= htmlspecialchars($detailsUrl, ENT_QUOTES, 'UTF-8') ?>" class="flex-shrink-0 d-inline-block">
                                    <img src="<?= htmlspecialchars(kasi_exchange_product_image_url((string) ($product['image_path'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars(kasi_exchange_product_title((string) ($product['title'] ?? 'Uniform image')), ENT_QUOTES, 'UTF-8') ?>" style="width: 65px; height: 65px; object-fit: cover; border-radius: 10px; display: block;">
                                </a>

                                <div class="min-w-0">
                                    <a href="<?= htmlspecialchars($detailsUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-dark d-block text-truncate" title="<?= htmlspecialchars(kasi_exchange_product_title((string) ($product['title'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                                        <h2 class="h6 mb-1 fw-semibold text-truncate"><?= htmlspecialchars(kasi_exchange_product_title((string) ($product['title'] ?? '')), ENT_QUOTES, 'UTF-8') ?></h2>
                                    </a>
                                    <div class="fw-semibold text-dark mb-1">R <?= htmlspecialchars(number_format((float) ($product['price'] ?? 0), 2, '.', ' '), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-muted small text-truncate">Seller: <?= htmlspecialchars($sellerName, ENT_QUOTES, 'UTF-8') ?> (ID: <?= htmlspecialchars((string) $sellerId, ENT_QUOTES, 'UTF-8') ?>)</div>
                                </div>
                            </div>

                            <form action="<?= htmlspecialchars(kasi_exchange_url('toggle_save.php'), ENT_QUOTES, 'UTF-8') ?>" method="POST" class="flex-shrink-0 ms-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) $productId, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-link p-0 text-decoration-none fw-semibold" style="color: <?= $is_saved ? '#c43c4c' : '#4a7c59' ?>; font-size: 0.92rem;"> <?= $is_saved ? 'Remove' : 'Save' ?> </button>
                            </form>
        }

        .catalog-gesture-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            z-index: 5;
            letter-spacing: 0.12em;
        }

        .catalog-stack-stage {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 520px;
        }

        .catalog-card-shell {
            position: relative;
            width: 100%;
            height: 100%;
        }

        .catalog-card-shell .catalog-card-action {
            top: 0.85rem;
        }

        .catalog-card-shell .catalog-gesture-badge {
            top: 0.85rem;
        }

        .catalog-card .card-body {
            padding: 1rem !important;
        }

        .catalog-card .card-body h2 {
            font-size: 1.05rem;
        }

        .catalog-card .card-body .text-muted.small {
            font-size: 0.78rem;
        }

        .catalog-card .fs-5 {
            font-size: 0.95rem !important;
        }

        .catalog-card .catalog-card-action.btn-sm {
            padding: 0.32rem 0.7rem;
            font-size: 0.76rem;
        }

        .catalog-title-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            flex-wrap: wrap;
            text-align: center;
        }

        .catalog-title-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--kasi-orange);
            box-shadow: 0 0 0 4px rgba(255, 140, 0, 0.12);
            flex: 0 0 auto;
        }

        .catalog-size-text {
            font-size: 0.72rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--kasi-muted);
            white-space: nowrap;
        }

        .catalog-action-hint {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            flex-wrap: wrap;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--kasi-muted);
        }

        .catalog-action-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--kasi-orange);
            box-shadow: 0 0 0 4px rgba(255, 140, 0, 0.12);
            flex: 0 0 auto;
        }

        .empty-refresh {
            min-height: 500px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .empty-refresh-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            background: rgba(255, 140, 0, 0.12);
            color: #c86800;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            box-shadow: 0 10px 18px rgba(255, 140, 0, 0.12);
        }

        .empty-refresh-action {
            margin-top: 0.65rem;
            min-width: 120px;
            min-height: 38px;
            padding: 0.45rem 0.85rem;
            font-size: 0.72rem;
            letter-spacing: 0.08em;
        }

        .catalog-card.is-dragging {
            transition: none;
            cursor: grabbing;
        }

        .catalog-card.is-swiping-out {
            transition: transform 260ms ease, opacity 260ms ease;
        }

        .catalog-card-action {
            position: absolute;
            top: 1rem;
            right: 1rem;
            z-index: 5;
        }

        .status-circle {
            width: 104px;
            height: 104px;
            margin: 0 0 1rem auto;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            background: rgba(255, 250, 240, 0.9);
            border: 1px solid rgba(255, 218, 185, 0.38);
            box-shadow: 0 12px 26px rgba(66, 46, 28, 0.08);
        }

        .status-circle .status-label {
            font-size: 0.62rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--kasi-muted);
            margin-bottom: 0.2rem;
        }

        .status-circle .status-role {
            font-size: 0.88rem;
            font-weight: 800;
            line-height: 1.1;
            text-transform: capitalize;
        }

        .action-cluster {
            max-width: 520px;
            margin: 0 auto 1rem;
        }

        .action-short {
            width: 100%;
            max-width: 220px;
            min-height: 48px;
            padding-inline: 1rem;
        }

        .kasi-home-shell {
            max-width: 1180px;
        }

        .kasi-topbar {
            padding-top: 0.25rem;
        }

        .kasi-eyebrow {
            font-size: 0.72rem;
            letter-spacing: 0.32em;
            text-transform: uppercase;
            color: var(--kasi-muted);
        }

        .kasi-home-title {
            font-size: clamp(2rem, 3vw, 3.2rem);
            line-height: 0.96;
            letter-spacing: -0.03em;
        }

        .kasi-search-inline {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            flex: 1 1 560px;
            min-width: 0;
        }

        .kasi-search-input {
            border: 0 !important;
            border-bottom: 1px solid rgba(133, 119, 106, 0.28) !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            background: transparent !important;
            padding-left: 0;
            padding-right: 0;
            min-height: 44px;
            font-size: 0.96rem;
            letter-spacing: 0.02em;
        }

        .kasi-search-input::placeholder {
            color: rgba(133, 119, 106, 0.72);
        }

        .kasi-search-input:focus,
        .kasi-filter-select:focus {
            box-shadow: none !important;
            border-color: rgba(255, 140, 0, 0.65) !important;
        }

        .kasi-filter-select {
            min-height: 44px;
            border: 0 !important;
            border-bottom: 1px solid rgba(133, 119, 106, 0.28) !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            background: transparent !important;
            color: var(--kasi-text);
            font-size: 0.9rem;
        }

        .kasi-action-links {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 1.15rem;
            flex-wrap: wrap;
        }

        .kasi-inline-link {
            color: var(--kasi-text);
            text-decoration: none;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            transition: color 160ms ease, opacity 160ms ease;
        }

        .kasi-inline-link:hover,
        .kasi-inline-link:focus {
            color: var(--kasi-orange);
            opacity: 1;
        }

        .kasi-bag-link {
            white-space: nowrap;
        }

        .kasi-nav-link-primary {
            letter-spacing: 0.22em;
        }

        .kasi-product-media {
            position: relative;
        }

        .kasi-product-grid {
            padding-top: 1.75rem;
        }

        .kasi-product-card {
            border: 0;
            border-radius: 1.5rem;
            overflow: hidden;
            background: rgba(255, 250, 240, 0.86);
            box-shadow: 0 14px 34px rgba(66, 46, 28, 0.1);
            height: 100%;
        }

        .kasi-product-image {
            width: 100%;
            aspect-ratio: 4 / 3;
            object-fit: cover;
            background: rgba(241, 245, 249, 0.9);
        }

        .kasi-product-meta {
            font-size: 0.7rem;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            color: var(--kasi-muted);
        }

        .kasi-review-list {
            display: grid;
            gap: 0.75rem;
        }

        .kasi-review-item {
            padding-top: 0.7rem;
            border-top: 1px solid rgba(255, 218, 185, 0.55);
        }

        .kasi-review-stars {
            font-size: 0.72rem;
            letter-spacing: 0.14em;
            color: #b77a12;
        }

        .kasi-review-name {
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--kasi-muted);
        }

        .kasi-review-text {
            margin: 0;
            font-size: 0.88rem;
            line-height: 1.48;
            color: var(--kasi-text);
        }

        .kasi-empty-state {
            min-height: 280px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        @media (min-width: 768px) {
            .catalog-stack-stage {
                min-height: 560px;
            }

            .catalog-shell {
                width: min(100%, 340px);
                height: 500px;
            }
        }

        @media (min-width: 992px) {
            .catalog-stack-stage {
                min-height: 620px;
            }
        }
    </style>
</head>
<body class="bg-light">
<main class="container py-5 kasi-home-shell">
    <header class="kasi-topbar mb-4 mb-lg-5">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3 gap-lg-4">
            <div class="flex-shrink-0">
                <p class="kasi-eyebrow mb-2">Kasi Exchange</p>
            </div>

            <form method="get" action="<?= htmlspecialchars(kasi_exchange_url('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="kasi-search-inline flex-grow-1">
                <div class="flex-grow-1">
                    <label for="q" class="visually-hidden">Search school uniforms</label>
                    <input type="text" class="form-control kasi-search-input" id="q" name="q" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search school uniforms...">
                </div>
                <div class="flex-shrink-0" style="min-width: 138px;">
                    <label for="size" class="visually-hidden">Filter by size</label>
                    <select class="form-select kasi-filter-select" id="size" name="size">
                        <option value="all" <?= $selectedSize === 'all' ? 'selected' : '' ?>>All Sizes</option>
                        <?php foreach (array_slice($allowedSizes, 1) as $sizeOption): ?>
                            <option value="<?= htmlspecialchars($sizeOption, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedSize === $sizeOption ? 'selected' : '' ?>><?= htmlspecialchars($sizeOption, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <div class="kasi-action-links ms-lg-auto">
                <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="kasi-inline-link kasi-nav-link-primary"><?= htmlspecialchars(kasi_exchange_dashboard_link_label($isLoggedIn), ENT_QUOTES, 'UTF-8') ?></a>
                <a href="<?= htmlspecialchars($cartUrl, ENT_QUOTES, 'UTF-8') ?>" class="kasi-inline-link kasi-bag-link">Bag (<?= htmlspecialchars((string) $bagCount, ENT_QUOTES, 'UTF-8') ?>)</a>
                <a href="<?= htmlspecialchars($savedItemsUrl, ENT_QUOTES, 'UTF-8') ?>" class="kasi-inline-link">Saved Items</a>
                <a href="<?= htmlspecialchars(kasi_exchange_url('about.php'), ENT_QUOTES, 'UTF-8') ?>" class="kasi-inline-link">ABOUT US</a>
            </div>
        </div>
    </header>

    <?php if ($userRole === 'seller'): ?>
        <div class="d-flex flex-wrap gap-2 mb-4 mb-lg-5">
            <a href="<?= htmlspecialchars($sellerDashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark btn-sm">My Inventory</a>
            <a href="<?= htmlspecialchars($uploadUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-cta btn-sm">List a New Uniform</a>
            <a href="<?= htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark btn-sm">Verify Latest Upload</a>
            <a href="<?= htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-dark btn-sm">Log Out</a>
        </div>
    <?php endif; ?>

    <section class="kasi-product-grid pb-4 pb-lg-5">
        <?php if ($buyerProducts === []): ?>
            <div class="kasi-empty-state py-5">
                <div>
                    <div class="empty-refresh-pill mb-2">Refresh</div>
                    <h2 class="h5 mb-2">No products match this view yet.</h2>
                    <p class="text-muted small mb-3">Try clearing your search or size filter to see the full marketplace.</p>
                    <a href="<?= htmlspecialchars(kasi_exchange_url('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-cta empty-refresh-action">Refresh</a>
                </div>
            </div>
        <?php else: ?>
            <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3">
                <?php foreach ($buyerProducts as $product) { ?>
                    <?php
                        $productId = (int) ($product['id'] ?? 0);
                        $detailsUrl = $productDetailsUrl . '?id=' . urlencode((string) $productId);
                        $reviewsForProduct = array_slice($productReviewsByProduct[$productId] ?? [], 0, 2);
                        $is_saved = false;

                        if ($savedStateStmt instanceof PDOStatement) {
                            $savedStateStmt->execute([
                                ':p_id' => $productId,
                                ':s_id' => $currentSessionId,
                                ':u_id' => $currentUserId,
                            ]);
                            $is_saved = $savedStateStmt->fetchColumn() !== false;
                            $savedStateStmt->closeCursor();
                        }
                    ?>
                    <div class="col">
                        <article class="kasi-product-card">
                            <div class="kasi-product-media">
                                <a href="<?= htmlspecialchars($detailsUrl, ENT_QUOTES, 'UTF-8') ?>" class="d-block">
                                    <img src="<?= htmlspecialchars(kasi_exchange_product_image_url((string) ($product['image_path'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" class="kasi-product-image" alt="<?= htmlspecialchars(kasi_exchange_product_title((string) ($product['title'] ?? 'Uniform image')), ENT_QUOTES, 'UTF-8') ?>">
                                </a>
                                <form action="toggle_save.php" method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) $productId, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="kasi-save-btn<?= $is_saved ? ' is-saved' : '' ?>" style="background: none; border: none; padding: 0; line-height: 1;" aria-pressed="<?= $is_saved ? 'true' : 'false' ?>" aria-label="Save for later">
                                        <span aria-hidden="true" style="font-size: 1.35rem; color: <?= $is_saved ? '#ff7a00' : 'rgba(255, 122, 0, 0.38)' ?>; text-shadow: <?= $is_saved ? '0 0 0 transparent' : 'none' ?>; font-weight: 800;"><?= $is_saved ? '♥' : '♡' ?></span>
                                    </button>
                                </form>
                            </div>
                            <div class="p-4 d-flex flex-column h-100">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                    <div>
                                        <div class="kasi-product-meta mb-1"><?= htmlspecialchars((string) ($product['size'] ?? 'Size not listed'), ENT_QUOTES, 'UTF-8') ?></div>
                                        <h2 class="h5 mb-1"><?= htmlspecialchars(kasi_exchange_product_title((string) ($product['title'] ?? '')), ENT_QUOTES, 'UTF-8') ?></h2>
                                    </div>
                                    <strong class="fs-5 text-dark">R <?= htmlspecialchars(number_format((float) ($product['price'] ?? 0), 2, '.', ' '), ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>

                                <p class="text-muted small mb-3"><?= htmlspecialchars(kasi_exchange_product_snippet((string) ($product['description'] ?? '')), ENT_QUOTES, 'UTF-8') ?></p>

                                <div class="d-grid gap-2 d-sm-flex mb-3">
                                    <form method="post" action="<?= htmlspecialchars(kasi_exchange_url('cart_handler.php'), ENT_QUOTES, 'UTF-8') ?>" class="flex-grow-1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) $productId, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn btn-cta btn-sm w-100">Add to Bag</button>
                                    </form>

                                    <a href="<?= htmlspecialchars($detailsUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm flex-grow-1">View</a>
                                </div>

                                <span class="text-muted small text-uppercase letter-spaced mt-auto">Premium school resale</span>
                            </div>
                        </article>
                    </div>
                <?php } ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($totalPages > 1): ?>
        <?php
            $paginationQuery = $_GET;
            unset($paginationQuery['page']);
            $pageUrl = static function (int $targetPage) use ($paginationQuery): string {
                return kasi_exchange_url('index.php') . '?' . http_build_query(array_merge($paginationQuery, ['page' => $targetPage]));
            };
        ?>
        <nav aria-label="Product pages" class="d-flex justify-content-center pb-5">
            <ul class="pagination pagination-sm kasi-pagination mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars($pageUrl(max(1, $page - 1)), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous">&laquo;</a>
                </li>
                <?php for ($pageIndex = 1; $pageIndex <= $totalPages; $pageIndex++): ?>
                    <li class="page-item <?= $pageIndex === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars($pageUrl($pageIndex), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $pageIndex, ENT_QUOTES, 'UTF-8') ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars($pageUrl(min($totalPages, $page + 1)), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next">&raquo;</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
        // Hubs data + CSRF token from server
        const KASI_HUBS = <?= json_encode(array_values($hubs), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const KASI_CSRF = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        const escapeHtml = (str) => String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        // Create modal markup and append to body (Bootstrap 5)
        const createBuyModal = () => {
                const modal = document.createElement('div');
                modal.innerHTML = `
                <div class="modal fade" id="kasiBuyModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Confirm Secure Escrow Purchase</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Select Spaza Hub</label>
                                    <select id="kasiHubSelect" class="form-select">
                                        ${KASI_HUBS.map(h => `<option value="${h.id}">${escapeHtml(h.hub_name)} — ${escapeHtml(h.address)}</option>`).join('')}
                                    </select>
                                </div>
                                <input type="hidden" id="kasiSelectedProductId" value="">
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-cta" id="kasiConfirmBuy">Confirm Secure Escrow Purchase</button>
                            </div>
                        </div>
                    </div>
                </div>`;

                document.body.appendChild(modal);
        };

        createBuyModal();

    const bagLink = document.querySelector('.kasi-bag-link');
    if (bagLink) {
        bagLink.textContent = `Bag (<?= htmlspecialchars((string) $bagCount, ENT_QUOTES, 'UTF-8') ?>)`;
    }

    const stack = document.querySelector('[data-catalog-stack]');
    if (!stack) {
        return;
    }

    const cards = Array.from(stack.querySelectorAll('.catalog-item'));
    if (!cards.length) {
        return;
    }

    let activeCard = null;
    let startX = 0;
    let startY = 0;
    let currentX = 0;
    let currentY = 0;
    let dragging = false;
    let rafPending = false;

    const updateDeck = () => {
        const visibleCards = cards.filter((card) => !card.classList.contains('is-hidden'));
        visibleCards.forEach((card, index) => {
            card.dataset.depth = String(index > 3 ? 3 : index);
            card.style.pointerEvents = index === 0 ? 'auto' : 'none';
        });
    };

    const hideCard = (card) => {
        card.classList.add('is-hidden');
        card.style.display = 'none';
        activeCard = null;
        updateDeck();
    };

    const animateOut = (card, direction, navigateUrl) => {
        card.classList.add('is-swiping-out');
        card.style.transform = `translate3d(${direction * (window.innerWidth + 320)}px, 0, 0) rotate(${direction * 18}deg)`;
        card.style.opacity = '0';

        window.setTimeout(() => {
            if (navigateUrl) {
                window.location.href = navigateUrl;
                return;
            }

            hideCard(card);
        }, 220);
    };

    const onMove = (clientX, clientY) => {
        if (!dragging || !activeCard) {
            return;
        }

        currentX = clientX - startX;
        currentY = clientY - startY;

        if (rafPending) {
            return;
        }

        rafPending = true;
        window.requestAnimationFrame(() => {
            rafPending = false;
            if (activeCard) {
                setTransform(activeCard, currentX, currentY * 0.18);
            }
        });
    };

    const endDrag = () => {
        if (!dragging || !activeCard) {
            return;
        }

        const threshold = Math.max(120, window.innerWidth * 0.18);
        const productUrl = activeCard.dataset.detailsUrl || '';
        const direction = currentX >= 0 ? 1 : -1;

        dragging = false;
        activeCard.classList.remove('is-dragging');

        if (Math.abs(currentX) >= threshold) {
            if (currentX > 0) {
                // Right swipe -> open buy confirmation modal
                const pid = activeCard.dataset.productId || '';
                showConfirmModalForProduct(pid, activeCard);
                return;
            }

            // Left swipe -> simply remove card
            animateOut(activeCard, direction, '');
            return;
        }

        activeCard.style.transition = 'transform 220ms ease, opacity 220ms ease';
        activeCard.style.transform = '';
        activeCard.style.opacity = '';
        window.setTimeout(() => {
            if (activeCard) {
                activeCard.style.transition = '';
            }
        }, 240);
    };

    const showConfirmModalForProduct = (productId, cardElement) => {
        const modalEl = document.getElementById('kasiBuyModal');
        if (!modalEl) return;

        const selectedInput = document.getElementById('kasiSelectedProductId');
        selectedInput.value = String(productId);

        const bsModal = new bootstrap.Modal(modalEl);
        bsModal.show();

        const confirmBtn = document.getElementById('kasiConfirmBuy');
        const hubSelect = document.getElementById('kasiHubSelect');

        const onConfirm = async () => {
            const hubId = hubSelect.value;
            try {
                confirmBtn.disabled = true;
                const res = await fetch('buy_handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ product_id: productId, hub_id: hubId, csrf_token: KASI_CSRF })
                });

                const data = await res.json();
                if (data && data.success) {
                    bsModal.hide();
                    animateOut(cardElement, 1, '');
                    // show flash banner
                    showFlash(data.message || 'Purchase committed via escrow');
                } else {
                    alert((data && data.message) || 'Failed to create escrow transaction');
                }
            } catch (err) {
                alert('Network error: ' + String(err));
            } finally {
                confirmBtn.disabled = false;
                confirmBtn.removeEventListener('click', onConfirm);
            }
        };

        confirmBtn.removeEventListener('click', onConfirm);
        confirmBtn.addEventListener('click', onConfirm);
    };

    const showFlash = (msg) => {
        const container = document.querySelector('main.container');
        if (!container) return;
        const el = document.createElement('div');
        el.className = 'alert alert-success';
        el.textContent = msg;
        container.insertBefore(el, container.firstChild);
        setTimeout(() => el.remove(), 6000);
    };

    const bindCard = (card) => {
        card.addEventListener('mousedown', (event) => {
            if (card.classList.contains('is-hidden')) {
                return;
            }

            activeCard = card;
            dragging = true;
            startX = event.clientX;
            startY = event.clientY;
            currentX = 0;
            currentY = 0;
            card.classList.add('is-dragging');
            card.style.zIndex = '10';
            event.preventDefault();
        });

        card.addEventListener('touchstart', (event) => {
            if (card.classList.contains('is-hidden')) {
                return;
            }

            const touch = event.changedTouches[0];
            activeCard = card;
            dragging = true;
            startX = touch.clientX;
            startY = touch.clientY;
            currentX = 0;
            currentY = 0;
            card.classList.add('is-dragging');
            card.style.zIndex = '10';
            event.preventDefault();
        }, { passive: true });
    };

    document.addEventListener('mousemove', (event) => {
        if (!dragging) {
            return;
        }

        onMove(event.clientX, event.clientY);
    });

    document.addEventListener('touchmove', (event) => {
        if (!dragging) {
            return;
        }

        const touch = event.touches[0];
        onMove(touch.clientX, touch.clientY);
        event.preventDefault();
    }, { passive: false });

    document.addEventListener('mouseup', endDrag);
    document.addEventListener('touchend', endDrag);

    cards.forEach((card) => bindCard(card));
    // Attach Buy button handlers
    document.querySelectorAll('.btn-buy').forEach(btn => {
        btn.addEventListener('click', (ev) => {
            const pid = btn.dataset.productId || '';
            const card = btn.closest('.catalog-item');
            showConfirmModalForProduct(pid, card);
        });
    });

    updateDeck();
})();
</script>
<?php include 'footer.php'; ?>
</body>
</html>

