<?php

try {
    require_once __DIR__ . '/db_connect.php';

    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('buyer','seller','hub_agent','admin') NOT NULL DEFAULT 'buyer'");

    $email = 'admin@kasi.com';
    $passwordHash = password_hash('password123', PASSWORD_DEFAULT);
    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $check->execute([':email' => $email]);

    $adminInserted = false;

    if ($check->fetch() === false) {
        $insert = $pdo->prepare(
            'INSERT INTO users (full_name, email, password_hash, role) VALUES (:full_name, :email, :password_hash, :role)'
        );
        $insert->execute([
            ':full_name' => 'System Admin',
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':role' => 'admin',
        ]);

        $adminInserted = true;
    } else {
        $update = $pdo->prepare(
            'UPDATE users SET full_name = :full_name, password_hash = :password_hash, role = :role WHERE email = :email'
        );
        $update->execute([
            ':full_name' => 'System Admin',
            ':password_hash' => $passwordHash,
            ':role' => 'admin',
            ':email' => $email,
        ]);
    }
} catch (PDOException $exception) {
    $errorMessage = $exception->getMessage();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Kasi Exchange | Seed Admin</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
    <main class="container py-5" style="max-width: 760px;">
        <div class="alert alert-danger shadow-sm" role="alert">
            <h1 class="h5 mb-2">Seed failed</h1>
            <div><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </main>
    </body>
    </html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Seed Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 760px;">
    <div class="alert alert-success shadow-sm" role="alert">
        <h1 class="h5 mb-2">Table altered and Admin Superuser added safely!</h1>
        <p class="mb-0">
            <?= htmlspecialchars($adminInserted ? 'System Admin was inserted as a new admin account.' : 'System Admin already existed, so no duplicate record was created.', ENT_QUOTES, 'UTF-8') ?>
        </p>
    </div>
</main>
</body>
</html>