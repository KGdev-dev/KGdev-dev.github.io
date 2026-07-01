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

if (!function_exists('kasi_exchange_csrf_token')) {
    function kasi_exchange_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('kasi_exchange_redirect_by_role')) {
    function kasi_exchange_redirect_by_role(string $role): void
    {
        if ($role === 'admin') {
            header('Location: ' . kasi_exchange_url('admin_dashboard.php'));
            exit;
        }

        if ($role === 'hub_agent') {
            header('Location: ' . kasi_exchange_url('hub_pending.php') . '?hub_id=1');
            exit;
        }

        if ($role === 'seller' || $role === 'buyer') {
            header('Location: ' . kasi_exchange_url('index.php'));
            exit;
        }

        header('Location: ' . kasi_exchange_url('login.php'));
        exit;
    }
}

if (!function_exists('kasi_exchange_login_return_target')) {
    function kasi_exchange_login_return_target(string $target): string
    {
        $allowedTargets = ['checkout.php'];
        $target = trim($target);

        return in_array($target, $allowedTargets, true) ? $target : '';
    }
}

$switchAccount = (string) ($_GET['switch'] ?? '') === '1';
$returnTo = kasi_exchange_login_return_target((string) ($_GET['return_to'] ?? $_POST['return_to'] ?? ''));

if ($switchAccount) {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_role'], $_SESSION['role']);
    session_regenerate_id(true);
}

if (!$switchAccount && !empty($_SESSION['user_id']) && !empty($_SESSION['user_role'])) {
    if ($returnTo !== '') {
        header('Location: ' . kasi_exchange_url($returnTo));
        exit;
    }

    kasi_exchange_redirect_by_role((string) $_SESSION['user_role']);
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Invalid form submission. Please try again.';
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '') {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if ($errors === []) {
        $stmt = $pdo->prepare('SELECT id, full_name, password_hash, role FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $errors[] = 'Invalid email or password.';
        } else {
            $previousSessionId = session_id();
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_name'] = (string) $user['full_name'];
            $_SESSION['user_role'] = (string) $user['role'];
            $_SESSION['role'] = (string) $user['role'];

            if ($previousSessionId !== '') {
                try {
                    $pdo->beginTransaction();

                    $legacyItemsStmt = $pdo->prepare(
                        'SELECT id, product_id
                         FROM saved_items
                         WHERE session_id = :session_id
                           AND user_id IS NULL
                         ORDER BY id ASC'
                    );
                    $legacyItemsStmt->execute([':session_id' => $previousSessionId]);
                    $legacyItems = $legacyItemsStmt->fetchAll();

                    $checkUserItemStmt = $pdo->prepare(
                        'SELECT id FROM saved_items
                         WHERE user_id = :user_id
                           AND product_id = :product_id
                         LIMIT 1'
                    );
                    $attachItemStmt = $pdo->prepare(
                        'UPDATE saved_items
                         SET user_id = :user_id
                         WHERE id = :id'
                    );
                    $deleteItemStmt = $pdo->prepare('DELETE FROM saved_items WHERE id = :id');

                    foreach ($legacyItems as $legacyItem) {
                        $checkUserItemStmt->execute([
                            ':user_id' => (int) $user['id'],
                            ':product_id' => (int) $legacyItem['product_id'],
                        ]);

                        if ($checkUserItemStmt->fetchColumn() !== false) {
                            $deleteItemStmt->execute([':id' => (int) $legacyItem['id']]);
                            continue;
                        }

                        $attachItemStmt->execute([
                            ':user_id' => (int) $user['id'],
                            ':id' => (int) $legacyItem['id'],
                        ]);
                    }

                    $pdo->commit();
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log('Login saved-item migration failed: ' . $throwable->getMessage());
                }
            }

            if ($returnTo !== '') {
                header('Location: ' . kasi_exchange_url($returnTo));
                exit;
            }

            kasi_exchange_redirect_by_role($_SESSION['user_role']);
        }
    }
}

$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_success']);
$csrfToken = kasi_exchange_csrf_token();
$loginBackgroundUrl = kasi_exchange_url('uploads/uniform_6a0b814de9b746.89990398.jpg');

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <style>
        body.login-shell {
            background:
                linear-gradient(135deg, rgba(255, 250, 240, 0.74), rgba(253, 245, 230, 0.84)),
                url('<?= htmlspecialchars($loginBackgroundUrl, ENT_QUOTES, 'UTF-8') ?>') center/cover fixed no-repeat !important;
        }

        .login-page {
            min-height: 100vh;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.8rem;
        }

        .login-page::before,
        .login-page::after {
            content: '';
            position: absolute;
            border-radius: 999px;
            filter: blur(6px);
            pointer-events: none;
        }

        .login-page::before {
            width: 320px;
            height: 320px;
            top: -80px;
            right: -90px;
            background: rgba(255, 140, 0, 0.14);
        }

        .login-page::after {
            width: 260px;
            height: 260px;
            left: -90px;
            bottom: -70px;
            background: rgba(152, 251, 152, 0.14);
        }

        .login-stage {
            position: relative;
            z-index: 1;
            width: min(100%, 520px);
        }

        .login-shell-card {
            border: 1px solid rgba(255, 218, 185, 0.36);
            border-radius: 26px;
            overflow: hidden;
            background: rgba(255, 250, 240, 0.72);
            backdrop-filter: blur(16px);
            box-shadow: 0 22px 52px rgba(66, 46, 28, 0.14);
        }

        .login-form-card {
            background: rgba(255, 255, 255, 0.82);
        }

        .floating-field .form-control {
            padding-top: 1.5rem;
            padding-bottom: 0.55rem;
        }

        .floating-field .form-label {
            color: #8b7767;
        }

        .login-focus-card {
        .welcome-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            background: rgba(255, 140, 0, 0.12);
            color: #9a5600;
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        .login-center {
            text-align: center;
        }

        .login-center .form-floating > .form-control,
        .login-center .form-floating > label {
            text-align: left;
        }

        .qa-pill {
            border-radius: 999px !important;
            padding-inline: 0.8rem;
            border-color: rgba(255, 140, 0, 0.22) !important;
        }

        .btn-compact {
            padding: 0.6rem 1rem !important;
            min-height: 42px;
            font-size: 0.94rem;
            max-width: 160px;
            margin-inline: auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .login-compact {
            max-width: 320px;
            margin: 0 auto;
        }

        @media (max-width: 991.98px) {
            .login-focus-card {
                min-height: auto;
                max-width: 100%;
            }

            .login-page {
                padding: 0.75rem;
            }
        }
    </style>
</head>
<body class="login-shell">
<main class="login-page">
    <div class="login-stage">
        <div class="login-focus-card">
            <div class="login-shell-card h-100">
                <div class="login-form-card p-3 p-md-4 h-100 d-flex align-items-center">
                    <div class="w-100 login-compact login-center">
                        <div class="mb-3">
                            <h2 class="h4 mb-2">Log in</h2>
                            <div class="welcome-tag mb-2">Welcome back to Kasi Exchange</div>
                        </div>

            <?php if ($flashSuccess !== ''): ?>
                <div class="alert alert-success" role="alert"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

                        <form method="post" action="login.php" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="floating-field form-floating mb-3">
                                <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="name@example.com" required autocomplete="email">
                                <label for="email" class="form-label">Email</label>
                            </div>
                            <div class="floating-field form-floating mb-3">
                                <input type="password" class="form-control" id="password" name="password" placeholder="Password" required autocomplete="current-password">
                                <label for="password" class="form-label">Password</label>
                            </div>
                            <div class="text-center">
                                <button type="submit" class="btn btn-cta btn-compact">Log in</button>
                            </div>
                        </form>

                        <div class="mt-3">
                            <a href="register.php" class="link-secondary text-decoration-none fw-semibold">Need an account? Register</a>
                        </div>

                        <div class="border-top mt-3 pt-3 small text-muted">
                            <div class="text-uppercase mb-2 fw-semibold text-center">QA Test Accounts</div>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm qa-pill w-100" data-qa-email="sipho@kasi.com" data-qa-password="password123">Autofill Buyer (Sipho)</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm qa-pill w-100" data-qa-email="thabo@kasi.com" data-qa-password="password123">Autofill Seller (Thabo)</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm qa-pill w-100" data-qa-email="vusi@kasi.com" data-qa-password="password123">Autofill Hub Agent (Vusi)</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm qa-pill w-100" data-qa-email="admin@kasi.com" data-qa-password="password123">Autofill Admin (God Mode)</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
(function () {
    const emailField = document.getElementById('email');
    const passwordField = document.getElementById('password');
    const qaButtons = document.querySelectorAll('[data-qa-email][data-qa-password]');

    qaButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            emailField.value = button.getAttribute('data-qa-email') || '';
            passwordField.value = button.getAttribute('data-qa-password') || '';
            emailField.focus();
            emailField.select();
        });
    });
})();
</script>
</body>
</html>
