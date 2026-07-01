<?php

try {
    require_once __DIR__ . '/db_connect.php';

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS hubs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hub_name VARCHAR(255) NOT NULL,
            address VARCHAR(255) NOT NULL,
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
    <title>Kasi Exchange | Seed Hubs Table</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 760px;">
    <?php if (!empty($successMessage)): ?>
        <div class="alert alert-success shadow-sm" role="alert">
            <h1 class="h5 mb-2">Success</h1>
            <p class="mb-0"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php else: ?>
        <div class="alert alert-danger shadow-sm" role="alert">
            <h1 class="h5 mb-2">Seed failed</h1>
            <p class="mb-0"><?= htmlspecialchars($errorMessage ?? 'Error: Unknown error', ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php endif; ?>
</main>
</body>
</html>