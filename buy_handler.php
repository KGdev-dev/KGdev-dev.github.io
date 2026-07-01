<?php
require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/db_connect.php';

// Only allow buyers
if (!kasi_exchange_is_authenticated() || ($_SESSION['user_role'] ?? '') !== 'buyer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$productId = isset($input['product_id']) ? (int)$input['product_id'] : 0;
$hubId = isset($input['hub_id']) ? (int)$input['hub_id'] : 0;
$csrf = $input['csrf_token'] ?? '';

if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$csrf)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

if ($productId <= 0 || $hubId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters']);
    exit;
}

try {
    // Ensure transactions table exists (defensive)
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, buyer_id INT NOT NULL, hub_id INT NOT NULL, quantity INT NOT NULL DEFAULT 1, status ENUM('pending','at_hub','collected','cancelled') NOT NULL DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

    $pdo->beginTransaction();

    // Attempt to reserve the product by moving it from 'available' to 'escrow'
    $update = $pdo->prepare("UPDATE products SET status = 'escrow' WHERE id = :id AND status = 'available'");
    $update->execute([':id' => $productId]);

    if ($update->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Product no longer available']);
        exit;
    }

    // Insert transaction record
    $insert = $pdo->prepare('INSERT INTO transactions (product_id, buyer_id, hub_id, status) VALUES (:product_id, :buyer_id, :hub_id, :status)');
    $insert->execute([':product_id' => $productId, ':buyer_id' => (int)$_SESSION['user_id'], ':hub_id' => $hubId, ':status' => 'pending']);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Escrow transaction created — collect at selected hub']);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
