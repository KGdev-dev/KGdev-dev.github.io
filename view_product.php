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

if (!function_exists('kasi_exchange_view_product_image_url')) {
	function kasi_exchange_view_product_image_url(?string $imagePath): string
	{
		$safePath = trim((string) $imagePath);

		if ($safePath === '') {
			return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="800" viewBox="0 0 1000 800"><rect width="1000" height="800" fill="#f8fafc"/><rect x="180" y="140" width="640" height="520" rx="36" fill="#e2e8f0"/><path d="M344 290h312l52 108v164H292V398l52-108z" fill="#94a3b8"/><circle cx="442" cy="392" r="34" fill="#f8fafc"/><path d="M464 446h188" stroke="#f8fafc" stroke-width="24" stroke-linecap="round"/></svg>');
		}

		return kasi_exchange_url(ltrim(str_replace('\\', '/', $safePath), '/'));
	}
}

if (!function_exists('kasi_exchange_view_product_title')) {
	function kasi_exchange_view_product_title(string $title): string
	{
		return trim($title) !== '' ? $title : 'Untitled product';
	}
}

$productId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$product = false;
$isSaved = false;

if ($productId !== false && $productId !== null) {
	try {
		$statement = $pdo->prepare(
			'SELECT p.id, p.seller_id, p.title, p.description, p.price, p.size, p.image_path, p.status, u.full_name AS seller_name
			 FROM products p
			 LEFT JOIN users u ON u.id = p.seller_id
			 WHERE p.id = :id
			 LIMIT 1'
		);
		$statement->execute([':id' => (int) $productId]);
		$product = $statement->fetch(PDO::FETCH_ASSOC) ?: false;
	} catch (Throwable $throwable) {
		$product = false;
	}
}

if ($product !== false) {
	try {
		$savedStateStmt = $pdo->prepare(
			'SELECT 1 FROM saved_items WHERE product_id = :p_id AND (session_id = :s_id OR user_id = :u_id) LIMIT 1'
		);
		$savedStateStmt->execute([
			':p_id' => (int) $product['id'],
			':s_id' => session_id(),
			':u_id' => (int) ($_SESSION['user_id'] ?? 0),
		]);
		$isSaved = $savedStateStmt->fetchColumn() !== false;
		$savedStateStmt->closeCursor();
	} catch (Throwable $throwable) {
		$isSaved = false;
	}
}

$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
$flashError = (string) ($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
$productTitle = $product !== false ? kasi_exchange_view_product_title((string) ($product['title'] ?? '')) : 'Product not found';
$productImage = $product !== false ? kasi_exchange_view_product_image_url((string) ($product['image_path'] ?? '')) : kasi_exchange_view_product_image_url('');
$productDescription = $product !== false ? trim((string) ($product['description'] ?? '')) : '';
$productSize = $product !== false ? trim((string) ($product['size'] ?? '')) : '';
$sellerName = $product !== false ? trim((string) ($product['seller_name'] ?? '')) : '';
$sellerDisplayName = $sellerName !== '' ? $sellerName : 'Seller name placeholder';
$productCondition = 'Excellent condition';

if ($product !== false && $productDescription !== '') {
	$lowerDescription = mb_strtolower($productDescription);
	if (str_contains($lowerDescription, 'good condition')) {
		$productCondition = 'Good condition';
	}
	if (str_contains($lowerDescription, 'excellent condition')) {
		$productCondition = 'Excellent condition';
	}
}

if ($product === false) {
	http_response_code(404);
}

?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Kasi Exchange | <?= htmlspecialchars($productTitle, ENT_QUOTES, 'UTF-8') ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
</head>
<body class="kasi-view-page">
<main class="container-fluid px-3 px-lg-4 py-4 py-lg-5 kasi-view-shell">
	<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
		<div>
			<p class="kasi-view-eyebrow mb-2">Product details</p>
			<h1 class="h3 mb-0">View item</h1>
		</div>
		<a href="<?= htmlspecialchars(kasi_exchange_url('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">Back to listings</a>
	</div>

	<?php if ($flashSuccess !== ''): ?>
		<div class="alert alert-success mb-4" role="alert"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
	<?php endif; ?>

	<?php if ($flashError !== ''): ?>
		<div class="alert alert-danger mb-4" role="alert"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
	<?php endif; ?>

	<?php if ($product === false): ?>
		<div class="card kasi-view-panel">
			<div class="card-body p-5 text-center">
				<h2 class="h4 mb-2">Product not found</h2>
				<p class="text-muted mb-4">The item you requested is no longer available or the link is invalid.</p>
				<a href="<?= htmlspecialchars(kasi_exchange_url('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">Browse products</a>
			</div>
		</div>
	<?php else: ?>
		<div class="kasi-view-grid">
			<section class="kasi-view-panel kasi-view-image-panel">
				<img src="<?= htmlspecialchars($productImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($productTitle, ENT_QUOTES, 'UTF-8') ?>" class="kasi-view-image img-fluid">
			</section>

			<aside class="kasi-view-panel kasi-view-body position-sticky" style="top: 1.5rem;">
				<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
					<div class="flex-grow-1 min-w-0">
						<p class="kasi-view-eyebrow mb-2">Listed by <?= htmlspecialchars($sellerDisplayName, ENT_QUOTES, 'UTF-8') ?></p>
						<h2 class="kasi-view-title mb-3"><?= htmlspecialchars($productTitle, ENT_QUOTES, 'UTF-8') ?></h2>
					</div>
					<div class="text-end">
						<div class="kasi-view-price">R <?= htmlspecialchars(number_format((float) ($product['price'] ?? 0), 2, '.', ' '), ENT_QUOTES, 'UTF-8') ?></div>
						<div class="kasi-view-meta">Price</div>
					</div>
				</div>

				<div class="d-flex flex-wrap gap-2 mt-3">
					<span class="badge text-bg-light border">Condition: <?= htmlspecialchars($productCondition, ENT_QUOTES, 'UTF-8') ?></span>
					<?php if ($productSize !== ''): ?>
						<span class="badge text-bg-light border">Size: <?= htmlspecialchars($productSize, ENT_QUOTES, 'UTF-8') ?></span>
					<?php endif; ?>
					<?php if (!empty($product['status'])): ?>
						<span class="badge text-bg-light border text-capitalize"><?= htmlspecialchars((string) $product['status'], ENT_QUOTES, 'UTF-8') ?></span>
					<?php endif; ?>
				</div>

				<?php if ($productDescription !== ''): ?>
					<p class="mt-4 mb-0 text-muted lh-lg"><?= htmlspecialchars($productDescription, ENT_QUOTES, 'UTF-8') ?></p>
				<?php endif; ?>

				<div class="kasi-view-divider"></div>

				<form method="post" action="<?= htmlspecialchars(kasi_exchange_url('cart_handler.php'), ENT_QUOTES, 'UTF-8') ?>">
					<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
					<input type="hidden" name="product_id" value="<?= htmlspecialchars((string) $productId, ENT_QUOTES, 'UTF-8') ?>">
					<input type="hidden" name="return_to" value="<?= htmlspecialchars('view_product.php?id=' . (string) $productId, ENT_QUOTES, 'UTF-8') ?>">

					<div class="mb-3">
						<label for="quantity" class="form-label fw-semibold mb-2">Quantity</label>
						<select id="quantity" name="quantity" class="form-select kasi-view-qty">
							<?php for ($quantity = 1; $quantity <= 5; $quantity++): ?>
								<option value="<?= $quantity ?>" <?= $quantity === 1 ? 'selected' : '' ?>><?= $quantity ?></option>
							<?php endfor; ?>
						</select>
					</div>

					<div class="kasi-view-actions d-grid gap-2">
						<button type="submit" class="btn btn-primary btn-lg" formaction="<?= htmlspecialchars(kasi_exchange_url('checkout.php'), ENT_QUOTES, 'UTF-8') ?>" formmethod="get">Buy It Now</button>
						<button type="submit" class="btn btn-outline-secondary btn-lg">Add to Bag</button>
					</div>
				</form>

				<form action="<?= htmlspecialchars(kasi_exchange_url('toggle_save.php'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="mt-3">
					<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
					<input type="hidden" name="product_id" value="<?= htmlspecialchars((string) $productId, ENT_QUOTES, 'UTF-8') ?>">
					<button type="submit" class="btn btn-link p-0 text-decoration-none kasi-view-wishlist w-100 text-start">
						<span aria-hidden="true"><?= $isSaved ? '♥' : '♡' ?></span>
						<span class="ms-1">Add to Wishlist</span>
					</button>
				</form>

				<div class="kasi-view-divider"></div>

				<div>
					<h3 class="h5 fw-bold mb-2">Meet your seller</h3>
					<p class="mb-1 kasi-view-seller-name"><?= htmlspecialchars($sellerDisplayName, ENT_QUOTES, 'UTF-8') ?></p>
					<a href="#" class="link-secondary text-decoration-underline kasi-view-seller-link">View shop registration details</a>
				</div>
			</aside>
		</div>
	<?php endif; ?>
</main>
</body>
</html>
