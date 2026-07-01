<?php

require_once __DIR__ . '/db_connect.php';

$successMessage = '';
$errorMessage = '';

try {
	$pdo->exec('DROP TABLE IF EXISTS hubs');

	$pdo->exec(
		"CREATE TABLE hubs (
			id INT AUTO_INCREMENT PRIMARY KEY,
			hub_name VARCHAR(255) NOT NULL,
			address TEXT NOT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		)"
	);

	$successMessage = "Table 'hubs' created successfully!";
} catch (PDOException $exception) {
	$errorMessage = 'Error: ' . $exception->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Kasi Exchange | Nuclear Hub Fix</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 760px;">
	<div class="card shadow-sm border-0">
		<div class="card-body p-4 p-md-5">
			<div class="text-uppercase text-muted small mb-2">Database Reset Utility</div>
			<h1 class="h4 mb-3">Nuclear Hub Reset</h1>

			<?php if ($successMessage !== ''): ?>
				<div class="alert alert-success" role="alert">
					<strong>Success:</strong> <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?><br>
					<span>The old table was dropped and recreated with clean column names.</span>
				</div>
			<?php else: ?>
				<div class="alert alert-danger" role="alert">
					<strong><?= htmlspecialchars($errorMessage !== '' ? $errorMessage : 'Error: Unknown error', ENT_QUOTES, 'UTF-8') ?></strong>
				</div>
			<?php endif; ?>

			<p class="text-muted mb-0">If the reset succeeded, remove this file after confirming the dashboard can create new hubs normally.</p>
		</div>
	</div>
</main>
</body>
</html>
