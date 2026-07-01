<?php

$required_roles = [];
require_once __DIR__ . '/check_session.php';

if (($_SESSION['role'] ?? $_SESSION['user_role'] ?? '') !== 'hub_agent') {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/path_helpers.php';

if (!function_exists('kasi_exchange_csrf_token')) {
    function kasi_exchange_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

function kasi_exchange_hub_redirect(int $hubId): void
{
    header('Location: ' . kasi_exchange_url('hub_pending.php') . '?hub_id=' . $hubId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$transactionId = filter_var($_POST['transaction_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$hubId = filter_var($_POST['hub_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));

if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    $_SESSION['flash_error'] = 'Invalid CSRF token. Please try again.';
    kasi_exchange_hub_redirect((int) ($hubId === false || $hubId === null ? 0 : $hubId));
}

if ($transactionId === false || $transactionId === null || $hubId === false || $hubId === null || !in_array($action, ['received', 'collected'], true)) {
    $_SESSION['flash_error'] = 'Invalid request parameters.';
    kasi_exchange_hub_redirect((int) ($hubId === false || $hubId === null ? 0 : $hubId));
}

$transactionId = (int) $transactionId;
$hubId = (int) $hubId;

try {
    $pdo->beginTransaction();

    $lookup = $pdo->prepare(
        'SELECT t.id, t.product_id, t.hub_id, t.status, p.status AS product_status
         FROM transactions t
         INNER JOIN products p ON p.id = t.product_id
         WHERE t.id = :transaction_id AND t.hub_id = :hub_id
         LIMIT 1
         FOR UPDATE'
    );
    $lookup->execute([
        ':transaction_id' => $transactionId,
        ':hub_id' => $hubId,
    ]);

    $transaction = $lookup->fetch();

    if ($transaction === false) {
        throw new RuntimeException('Transaction not found for this hub.');
    }

    if ($action === 'received') {
        if ((string) $transaction['status'] !== 'pending') {
            throw new RuntimeException('Only pending transactions can be marked as received.');
        }

        $update = $pdo->prepare(
            'UPDATE transactions
             SET status = :new_status
             WHERE id = :transaction_id AND hub_id = :hub_id AND status = :current_status'
        );
        $update->execute([
            ':new_status' => 'at_hub',
            ':transaction_id' => $transactionId,
            ':hub_id' => $hubId,
            ':current_status' => 'pending',
        ]);

        if ($update->rowCount() === 0) {
            throw new RuntimeException('The transaction state changed before it could be updated.');
        }

        $pdo->commit();
        $_SESSION['flash_success'] = 'Transaction marked as received at the hub.';
        kasi_exchange_hub_redirect($hubId);
    }

    if ((string) $transaction['status'] !== 'at_hub') {
        throw new RuntimeException('Only transactions that are at the hub can be collected.');
    }

    if ((string) $transaction['product_status'] !== 'escrow') {
        throw new RuntimeException('The linked product is not in escrow anymore.');
    }

    $updateTransaction = $pdo->prepare(
        'UPDATE transactions
         SET status = :new_status
         WHERE id = :transaction_id AND hub_id = :hub_id AND status = :current_status'
    );
    $updateTransaction->execute([
        ':new_status' => 'collected',
        ':transaction_id' => $transactionId,
        ':hub_id' => $hubId,
        ':current_status' => 'at_hub',
    ]);

    if ($updateTransaction->rowCount() === 0) {
        throw new RuntimeException('The transaction state changed before collection could be completed.');
    }

    $updateProduct = $pdo->prepare(
        'UPDATE products
         SET status = :new_status
         WHERE id = :product_id AND status = :current_status'
    );
    $updateProduct->execute([
        ':new_status' => 'sold',
        ':product_id' => (int) $transaction['product_id'],
        ':current_status' => 'escrow',
    ]);

    if ($updateProduct->rowCount() === 0) {
        throw new RuntimeException('The product state changed before collection could be completed.');
    }

    $pdo->commit();
    $_SESSION['flash_success'] = 'Transaction completed. The item is now marked as sold.';
    kasi_exchange_hub_redirect($hubId);
} catch (Throwable $throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['flash_error'] = $throwable->getMessage();
    kasi_exchange_hub_redirect($hubId);
}