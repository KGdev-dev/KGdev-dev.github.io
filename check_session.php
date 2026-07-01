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

if (!function_exists('kasi_exchange_is_authenticated')) {
    function kasi_exchange_is_authenticated(): bool
    {
        return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
    }
}

if (!function_exists('kasi_exchange_require_auth')) {
    function kasi_exchange_require_auth(array $allowedRoles = []): void
    {
        if (!kasi_exchange_is_authenticated()) {
            header('Location: ' . kasi_exchange_url('login.php'));
            exit;
        }

        if ($allowedRoles !== []) {
            $currentRole = $_SESSION['user_role'] ?? '';

            if (!in_array($currentRole, $allowedRoles, true)) {
                header('Location: ' . kasi_exchange_url('login.php'));
                exit;
            }
        }
    }
}

$required_roles = $required_roles ?? [];
kasi_exchange_require_auth(is_array($required_roles) ? $required_roles : []);
