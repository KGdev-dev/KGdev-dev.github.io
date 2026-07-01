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

if (!function_exists('kasi_exchange_toggle_save_wants_json')) {
    function kasi_exchange_toggle_save_wants_json(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
    }
}

if (!function_exists('kasi_exchange_toggle_save_reply')) {
    function kasi_exchange_toggle_save_reply(bool $success, string $message, bool $wantsJson, array $extra = [], int $statusCode = 200): void
    {
        if ($wantsJson) {
            http_response_code($statusCode);
            header('Content-Type: application/json');

            echo json_encode(array_merge([
                'success' => $success,
                'message' => $message,
            ], $extra));
            exit;
        }

        if ($success) {
            $_SESSION['flash_success'] = $message;
        } else {
            $_SESSION['flash_error'] = $message;
        }

        header('Location: ' . kasi_exchange_url('index.php'));
        exit;
    }
}

$wantsJson = kasi_exchange_toggle_save_wants_json();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    kasi_exchange_toggle_save_reply(false, 'Method not allowed.', $wantsJson, [], 405);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = [];
}

$productId = filter_var($payload['product_id'] ?? ($_POST['product_id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$csrfToken = (string) ($payload['csrf_token'] ?? ($_POST['csrf_token'] ?? ''));

if ($productId === false || $productId === null) {
    kasi_exchange_toggle_save_reply(false, 'Invalid product selected.', $wantsJson, [], 400);
}

if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
    kasi_exchange_toggle_save_reply(false, 'Invalid form submission. Please try again.', $wantsJson, [], 400);
}

$sessionId = session_id();
$userId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

try {
    $pdo->beginTransaction();

    if ($userId !== null) {
        $select = $pdo->prepare(
            'SELECT id FROM saved_items
             WHERE product_id = :product_id
               AND (user_id = :user_id OR session_id = :session_id)
             LIMIT 1'
        );
        $select->execute([
            ':product_id' => (int) $productId,
            ':user_id' => $userId,
            ':session_id' => $sessionId,
        ]);
    } else {
        $select = $pdo->prepare(
            'SELECT id FROM saved_items
             WHERE product_id = :product_id
               AND session_id = :session_id
               AND user_id IS NULL
             LIMIT 1'
        );
        $select->execute([
            ':product_id' => (int) $productId,
            ':session_id' => $sessionId,
        ]);
    }

    $existingRowId = $select->fetchColumn();

    if ($existingRowId !== false) {
        if ($userId !== null) {
            $delete = $pdo->prepare(
                'DELETE FROM saved_items
                 WHERE product_id = :product_id
                   AND (user_id = :user_id OR session_id = :session_id)'
            );
            $delete->execute([
                ':product_id' => (int) $productId,
                ':user_id' => $userId,
                ':session_id' => $sessionId,
            ]);
        } else {
            $delete = $pdo->prepare('DELETE FROM saved_items WHERE id = :id');
            $delete->execute([':id' => (int) $existingRowId]);
        }

        $saved = false;
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO saved_items (session_id, user_id, product_id)
             VALUES (:session_id, :user_id, :product_id)'
        );
        $insert->execute([
            ':session_id' => $sessionId,
            ':user_id' => $userId,
            ':product_id' => (int) $productId,
        ]);
        $saved = true;
    }

    if ($userId !== null) {
        $countStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT product_id)
             FROM saved_items
             WHERE user_id = :user_id OR session_id = :session_id'
        );
        $countStmt->execute([
            ':user_id' => $userId,
            ':session_id' => $sessionId,
        ]);
    } else {
        $countStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT product_id)
             FROM saved_items
             WHERE session_id = :session_id
               AND user_id IS NULL'
        );
        $countStmt->execute([':session_id' => $sessionId]);
    }

    $pdo->commit();

    kasi_exchange_toggle_save_reply(
        true,
        $saved ? 'Item saved for later.' : 'Item removed from saved items.',
        $wantsJson,
        [
            'saved' => $saved,
            'saved_count' => (int) $countStmt->fetchColumn(),
        ]
    );
} catch (Throwable $throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Heart Button Database Failure: ' . $throwable->getMessage());
    kasi_exchange_toggle_save_reply(false, 'Unable to save item right now.', $wantsJson, [], 500);
}