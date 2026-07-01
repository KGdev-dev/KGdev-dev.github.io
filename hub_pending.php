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

if (!function_exists('kasi_exchange_hub_status_badge_class')) {
    function kasi_exchange_hub_status_badge_class(string $status): string
    {
        return match ($status) {
            'pending' => 'bg-warning text-dark',
            'at_hub' => 'bg-info text-dark',
            'collected' => 'bg-success',
            default => 'bg-secondary',
        };
    }
}

if (!function_exists('kasi_exchange_hub_status_label')) {
    function kasi_exchange_hub_status_label(string $status): string
    {
        return match ($status) {
            'pending' => 'Pending',
            'at_hub' => 'At Hub',
            'collected' => 'Collected',
            default => ucfirst($status),
        };
    }
}

$csrfToken = kasi_exchange_csrf_token();
$logoutUrl = kasi_exchange_url('logout.php');
$actionHandlerUrl = kasi_exchange_url('hub_action_handler.php');

$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_success']);

$flashError = (string) ($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_error']);

$hubId = filter_input(INPUT_GET, 'hub_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$hubId = $hubId === false || $hubId === null ? 0 : (int) $hubId;

$transactions = [];
$pendingCount = 0;
$atHubCount = 0;

if ($hubId > 0) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, buyer_id INT NOT NULL, hub_id INT NOT NULL, status ENUM('pending','at_hub','collected','cancelled') NOT NULL DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

        $stmt = $pdo->prepare(
            'SELECT t.id AS transaction_id, t.buyer_id, t.status, t.created_at, p.title
             FROM transactions t
             INNER JOIN products p ON p.id = t.product_id
             WHERE t.hub_id = :hub_id AND t.status IN (\'pending\', \'at_hub\')
             ORDER BY t.created_at DESC, t.id DESC'
        );
        $stmt->execute([':hub_id' => $hubId]);
        $transactions = $stmt->fetchAll();

        foreach ($transactions as $transaction) {
            if (($transaction['status'] ?? '') === 'pending') {
                $pendingCount++;
            }

            if (($transaction['status'] ?? '') === 'at_hub') {
                $atHubCount++;
            }
        }
    } catch (Throwable $throwable) {
        $flashError = 'Unable to load transactions for this hub.';
        $transactions = [];
    }
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Spaza Agent Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <style>
        body {
            background:
                radial-gradient(circle at top left, rgba(13, 110, 253, 0.08), transparent 28%),
                radial-gradient(circle at top right, rgba(25, 135, 84, 0.08), transparent 22%),
                #f8fafc;
        }

        .dashboard-shell {
            max-width: 1180px;
        }

        .page-hero {
            background: linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
        }

        .table thead th {
            font-size: 0.78rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #64748b;
        }

        .transaction-card + .transaction-card {
            margin-top: 0.75rem;
        }
    </style>
</head>
<body>
<a href="index.php" class="text-muted text-decoration-none small mb-4 d-inline-block">← Back to Marketplace</a>
<main class="container py-4 py-lg-5 dashboard-shell">
    <div class="card border-0 shadow-sm page-hero mb-4">
        <div class="card-body p-4 p-lg-5">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div>
                    <p class="text-uppercase text-muted small mb-1">Spaza Hub Operations</p>
                    <h1 class="display-6 fw-semibold mb-2">Spaza Agent Dashboard</h1>
                    <p class="text-muted mb-0">Manage uniform handovers for hub <?= htmlspecialchars($hubId > 0 ? (string) $hubId : 'not selected', ENT_QUOTES, 'UTF-8') ?> with a clean, transaction-first workflow.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= htmlspecialchars(kasi_exchange_url('hub_pending.php') . '?hub_id=' . $hubId, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">Refresh</a>
                    <a href="<?= htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">Log Out</a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success shadow-sm" role="alert"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="alert alert-danger shadow-sm" role="alert"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($hubId <= 0): ?>
        <div class="alert alert-warning shadow-sm" role="alert">Please open this dashboard with a valid hub_id parameter.</div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-4">
                        <p class="text-muted text-uppercase small mb-1">Hub ID</p>
                        <div class="fs-3 fw-semibold"><?= htmlspecialchars((string) $hubId, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-4">
                        <p class="text-muted text-uppercase small mb-1">Pending</p>
                        <div class="fs-3 fw-semibold text-warning"><?= htmlspecialchars((string) $pendingCount, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-4">
                        <p class="text-muted text-uppercase small mb-1">At Hub</p>
                        <div class="fs-3 fw-semibold text-info"><?= htmlspecialchars((string) $atHubCount, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="d-flex justify-content-between align-items-center px-4 pt-4 pb-3">
                    <div>
                        <h2 class="h5 mb-1">Active Transactions</h2>
                        <p class="text-muted mb-0">Only pending and at-hub records are shown here.</p>
                    </div>
                    <span class="badge text-bg-light border"><?= htmlspecialchars((string) count($transactions), ENT_QUOTES, 'UTF-8') ?> open</span>
                </div>

                <?php if ($transactions === []): ?>
                    <div class="px-4 pb-4">
                        <div class="alert alert-secondary mb-0" role="alert">No active transactions are assigned to this hub right now.</div>
                    </div>
                <?php else: ?>
                    <div class="table-responsive d-none d-md-block">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="ps-4">Item Title</th>
                                    <th scope="col">Buyer ID</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="pe-4 text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $transaction): ?>
                                    <?php
                                    $status = (string) ($transaction['status'] ?? '');
                                    $badgeClass = kasi_exchange_hub_status_badge_class($status);
                                    $badgeLabel = kasi_exchange_hub_status_label($status);
                                    $actionValue = $status === 'pending' ? 'received' : 'collected';
                                    $actionLabel = $status === 'pending' ? 'Item Received from Seller' : 'Item Collected by Buyer';
                                    $actionButtonClass = $status === 'pending' ? 'btn-warning' : 'btn-success';
                                    ?>
                                    <tr>
                                        <td class="ps-4 fw-medium"><?= htmlspecialchars((string) ($transaction['title'] ?? 'Untitled Item'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($transaction['buyer_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($transaction['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><span class="badge rounded-pill <?= htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td class="pe-4 text-end">
                                            <form method="post" action="<?= htmlspecialchars($actionHandlerUrl, ENT_QUOTES, 'UTF-8') ?>" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="hub_id" value="<?= htmlspecialchars((string) $hubId, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="transaction_id" value="<?= htmlspecialchars((string) ($transaction['transaction_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="<?= htmlspecialchars($actionValue, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="btn <?= htmlspecialchars($actionButtonClass, ENT_QUOTES, 'UTF-8') ?> btn-sm fw-semibold"><?= htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-md-none px-4 pb-4">
                        <?php foreach ($transactions as $transaction): ?>
                            <?php
                            $status = (string) ($transaction['status'] ?? '');
                            $badgeClass = kasi_exchange_hub_status_badge_class($status);
                            $badgeLabel = kasi_exchange_hub_status_label($status);
                            $actionValue = $status === 'pending' ? 'received' : 'collected';
                            $actionLabel = $status === 'pending' ? 'Item Received from Seller' : 'Item Collected by Buyer';
                            $actionButtonClass = $status === 'pending' ? 'btn-warning' : 'btn-success';
                            ?>
                            <div class="card border-0 shadow-sm transaction-card">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                        <div>
                                            <div class="fw-semibold"><?= htmlspecialchars((string) ($transaction['title'] ?? 'Untitled Item'), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="text-muted small">Buyer ID <?= htmlspecialchars((string) ($transaction['buyer_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <span class="badge rounded-pill <?= htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($badgeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="text-muted small mb-3"><?= htmlspecialchars((string) ($transaction['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <form method="post" action="<?= htmlspecialchars($actionHandlerUrl, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="hub_id" value="<?= htmlspecialchars((string) $hubId, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="transaction_id" value="<?= htmlspecialchars((string) ($transaction['transaction_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="<?= htmlspecialchars($actionValue, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn <?= htmlspecialchars($actionButtonClass, ENT_QUOTES, 'UTF-8') ?> btn-sm w-100 fw-semibold"><?= htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</main>
<?php include 'footer.php'; ?>
</body>
</html>
