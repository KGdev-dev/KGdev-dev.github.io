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
            background: linear-gradient(135deg, #fffaf0 0%, #ffffff 50%, #e8fff4 100%) !important;
        }

        .login-page {
            min-height: 100vh;
        }

        .login-panel {
            min-height: 100vh;
            padding: 2rem;
        }

        .login-panel-left {
            background: linear-gradient(160deg, rgba(255, 250, 240, 0.98), rgba(255, 255, 255, 0.94));
        }

        .login-panel-right {
            background: linear-gradient(160deg, rgba(232, 255, 244, 0.96), rgba(221, 250, 235, 0.98));
        }

        .login-card,
        .brand-card {
            width: min(100%, 460px);
        }

        .login-card {
            background: rgba(255, 255, 255, 0.86);
            border: 1px solid rgba(255, 218, 185, 0.55);
            border-radius: 28px;
            box-shadow: 0 22px 60px rgba(66, 46, 28, 0.12);
            backdrop-filter: blur(16px);
        }

        .brand-card {
            background: rgba(255, 255, 255, 0.58);
            border: 1px solid rgba(152, 251, 152, 0.28);
            border-radius: 28px;
            box-shadow: 0 18px 50px rgba(40, 88, 61, 0.12);
            backdrop-filter: blur(12px);
        }

        .login-kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 0.8rem;
            border-radius: 999px;
            background: rgba(255, 140, 0, 0.12);
            color: #9a5600;
            font-weight: 700;
        }

        .split-title {
            letter-spacing: -0.03em;
        }

        .qa-pill {
            border-radius: 999px !important;
            border-color: rgba(255, 140, 0, 0.24) !important;
            color: #6b5748 !important;
        }

        .qa-pill:hover,
        .qa-pill:focus {
            background: rgba(255, 250, 240, 0.95) !important;
            color: #3e352d !important;
            border-color: rgba(255, 140, 0, 0.42) !important;
        }

        .step-chip {
            width: 2rem;
            height: 2rem;
            border-radius: 999px;
            background: rgba(255, 140, 0, 0.16);
            color: #9a5600;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }

        .brand-copy {
            color: #5f5146;
        }

        @media (max-width: 767.98px) {
            .login-panel {
                min-height: auto;
                padding: 1.25rem;
            }

            .login-card,
            .brand-card {
                width: 100%;
            }
        }
    </style>
</head>
<body class="login-shell">
<main class="login-page container-fluid p-0">
    <div class="row g-0 min-vh-100">
        <div class="col-md-6 login-panel login-panel-left d-flex align-items-center justify-content-center">
            <div class="login-card p-4 p-lg-5">
                <div class="mb-4">
                    <div class="login-kicker mb-3">Welcome back to Kasi Exchange</div>
                    <h1 class="display-6 fw-bold split-title mb-2">Log in</h1>
                    <p class="mb-0 text-muted">Sign in to continue browsing, saving items, and checking out.</p>
                </div>

                <?php if ($flashSuccess !== ''): ?>
                    <div class="alert alert-success" role="alert"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if ($errors !== []): ?>
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="login.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-floating mb-3">
                        <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="name@example.com" required autocomplete="email">
                        <label for="email">Email</label>
                    </div>
                    <div class="form-floating mb-4">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Password" required autocomplete="current-password">
                        <label for="password">Password</label>
                    </div>
                    <button type="submit" class="btn btn-cta w-100 py-3">Log in</button>
                </form>

                <div class="mt-3">
                    <a href="register.php" class="link-secondary text-decoration-none fw-semibold">Need an account? Register</a>
                </div>

                <div class="mt-4 pt-4 border-top">
                    <div class="small text-uppercase fw-semibold text-muted mb-3">QA Test Accounts</div>
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-secondary qa-pill text-start" data-qa-email="sipho@kasi.com" data-qa-password="password123">Buyer - Sipho</button>
                        <button type="button" class="btn btn-outline-secondary qa-pill text-start" data-qa-email="thabo@kasi.com" data-qa-password="password123">Seller - Thabo</button>
                        <button type="button" class="btn btn-outline-secondary qa-pill text-start" data-qa-email="vusi@kasi.com" data-qa-password="password123">Hub Agent - Vusi</button>
                        <button type="button" class="btn btn-outline-secondary qa-pill text-start" data-qa-email="admin@kasi.com" data-qa-password="password123">Admin - God Mode</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 login-panel login-panel-right d-flex align-items-center justify-content-center">
            <div class="brand-card p-4 p-lg-5">
                <div class="mb-4">
                    <p class="kasi-eyebrow mb-2">Kasi Exchange</p>
                    <h2 class="display-6 fw-bold split-title mb-3">About Us</h2>
                    <p class="brand-copy mb-0">A simple place to buy, sell, and discover local finds with a clean shopping experience built for the Kasi community.</p>
                </div>

                <div>
                    <h3 class="h5 fw-bold mb-3">How it works</h3>
                    <div class="d-grid gap-3">
                        <div class="d-flex gap-3 align-items-start">
                            <span class="step-chip">1</span>
                            <div>
                                <div class="fw-semibold">Create an account</div>
                                <div class="text-muted">Sign up or use a test account to get started fast.</div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 align-items-start">
                            <span class="step-chip">2</span>
                            <div>
                                <div class="fw-semibold">Browse products</div>
                                <div class="text-muted">Explore items from local sellers in one clean catalog.</div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 align-items-start">
                            <span class="step-chip">3</span>
                            <div>
                                <div class="fw-semibold">Add to bag</div>
                                <div class="text-muted">Save items, choose a quantity, and keep shopping.</div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 align-items-start">
                            <span class="step-chip">4</span>
                            <div>
                                <div class="fw-semibold">Checkout securely</div>
                                <div class="text-muted">Finish your order with a smooth checkout flow.</div>
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
