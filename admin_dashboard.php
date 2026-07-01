<?php

$required_roles = [];
require_once __DIR__ . '/check_session.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/path_helpers.php';

if (!function_exists('kasi_exchange_admin_csrf_token')) {
    function kasi_exchange_admin_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('kasi_exchange_admin_flash_success')) {
    function kasi_exchange_admin_flash_success(string $message): void
    {
        $_SESSION['flash_success'] = $message;
    }
}

if (!function_exists('kasi_exchange_admin_flash_error')) {
    function kasi_exchange_admin_flash_error(string $message): void
    {
        $_SESSION['flash_error'] = $message;
    }
}

if (!function_exists('kasi_exchange_admin_redirect')) {
    function kasi_exchange_admin_redirect(): void
    {
        header('Location: ' . kasi_exchange_url('admin_dashboard.php'));
        exit;
    }
}

if (!function_exists('kasi_exchange_admin_allowed_roles')) {
    function kasi_exchange_admin_allowed_roles(): array
    {
        return ['buyer', 'seller', 'hub_agent', 'admin'];
    }
}

if (!function_exists('kasi_exchange_admin_status_badge_class')) {
    function kasi_exchange_admin_status_badge_class(string $status): string
    {
        return match ($status) {
            'buyer' => 'bg-primary',
            'seller' => 'bg-success',
            'hub_agent' => 'bg-warning text-dark',
            'admin' => 'bg-dark',
            default => 'bg-secondary',
        };
    }
}

$csrfToken = kasi_exchange_admin_csrf_token();
$logoutUrl = kasi_exchange_url('logout.php');
$homeUrl = kasi_exchange_url('index.php');
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_success']);

$flashError = (string) ($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_error']);

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS hubs (id INT AUTO_INCREMENT PRIMARY KEY, hub_name VARCHAR(191) NOT NULL, address TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
} catch (Throwable $throwable) {
    // best effort only
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (id INT AUTO_INCREMENT PRIMARY KEY, user_role VARCHAR(50) NOT NULL, user_name VARCHAR(191) NOT NULL, subject VARCHAR(191) NOT NULL, message TEXT NOT NULL, status VARCHAR(50) NOT NULL DEFAULT 'open', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
} catch (Throwable $throwable) {
    // best effort only
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        kasi_exchange_admin_flash_error('Invalid form submission. Please try again.');
        kasi_exchange_admin_redirect();
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'update_role') {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $newRole = trim((string) ($_POST['role'] ?? ''));

            if ($userId === false || $userId === null || !in_array($newRole, kasi_exchange_admin_allowed_roles(), true)) {
                throw new RuntimeException('Invalid role update request.');
            }

            $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
            $stmt->execute([
                ':role' => $newRole,
                ':id' => (int) $userId,
            ]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('No user was updated.');
            }

            if ((int) $userId === $currentUserId) {
                $_SESSION['role'] = $newRole;
                $_SESSION['user_role'] = $newRole;
            }

            kasi_exchange_admin_flash_success('User role updated successfully.');
            kasi_exchange_admin_redirect();
        }

        if ($action === 'delete_user') {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($userId === false || $userId === null) {
                throw new RuntimeException('Invalid user delete request.');
            }

            if ((int) $userId === $currentUserId) {
                throw new RuntimeException('You cannot delete the currently signed-in admin account.');
            }

            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute([':id' => (int) $userId]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('No user was deleted.');
            }

            kasi_exchange_admin_flash_success('User deleted successfully.');
            kasi_exchange_admin_redirect();
        }

        if ($action === 'add_hub') {
            $hubName = trim((string) ($_POST['hub_name'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));

            if (mb_strlen($hubName) < 3 || mb_strlen($address) < 3) {
                throw new RuntimeException('Hub name and address must each be at least 3 characters long.');
            }

            if (mb_strlen($hubName) > 191) {
                throw new RuntimeException('Hub name must be 191 characters or fewer.');
            }

            if (mb_strlen($address) > 2000) {
                throw new RuntimeException('Address must be 2000 characters or fewer.');
            }

            $sql = 'INSERT INTO hubs (hub_name, address) VALUES (:hub_name, :address)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':hub_name' => $hubName,
                ':address' => $address,
            ]);

            kasi_exchange_admin_flash_success('Verified Spaza Hub added successfully.');
            kasi_exchange_admin_redirect();
        }

        if ($action === 'delete_hub') {
            $hubId = filter_var($_POST['hub_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($hubId === false || $hubId === null) {
                throw new RuntimeException('Invalid hub delete request.');
            }

            $stmt = $pdo->prepare('DELETE FROM hubs WHERE id = :id');
            $stmt->execute([':id' => (int) $hubId]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('No hub was deleted.');
            }

            kasi_exchange_admin_flash_success('Spaza Hub deleted successfully.');
            kasi_exchange_admin_redirect();
        }

        if ($action === 'resolve_ticket') {
            $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($ticketId === false || $ticketId === null) {
                throw new RuntimeException('Invalid ticket resolve request.');
            }

            $stmt = $pdo->prepare("UPDATE support_tickets SET status = 'resolved' WHERE id = :id AND status NOT IN ('resolved', 'closed')");
            $stmt->execute([':id' => (int) $ticketId]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('No ticket was updated.');
            }

            kasi_exchange_admin_flash_success('Support ticket resolved successfully.');
            kasi_exchange_admin_redirect();
        }

        throw new RuntimeException('Invalid admin action.');
    } catch (Throwable $throwable) {
        kasi_exchange_admin_flash_error($throwable->getMessage());
        kasi_exchange_admin_redirect();
    }
}

$users = [];
$hubs = [];
$supportTickets = [];

try {
    $stmt = $pdo->query('SELECT id, full_name AS name, email, role, created_at FROM users ORDER BY id DESC');
    $users = $stmt->fetchAll();
} catch (Throwable $throwable) {
    $users = [];
    $flashError = $flashError !== '' ? $flashError : 'Unable to load users.';
}

try {
    $stmt = $pdo->query('SELECT id, hub_name, address, created_at FROM hubs ORDER BY id DESC');
    $hubs = $stmt->fetchAll();
} catch (Throwable $throwable) {
    $hubs = [];
}

try {
    $stmt = $pdo->query('SELECT * FROM support_tickets ORDER BY created_at DESC');
    $supportTickets = $stmt->fetchAll();
} catch (Throwable $throwable) {
    $supportTickets = [];
}

$userCount = count($users);
$hubCount = count($hubs);
$ticketCount = count($supportTickets);

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(kasi_exchange_url('custom_theme.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <style>
        body {
            background:
                radial-gradient(circle at top left, rgba(13, 110, 253, 0.08), transparent 28%),
                radial-gradient(circle at top right, rgba(220, 53, 69, 0.06), transparent 22%),
                #f8fafc;
        }

        .dashboard-shell {
            max-width: 1540px;
        }

        .page-hero {
            background: linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
        }

        .table thead th {
            font-size: 0.76rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #64748b;
        }

        .soft-card {
            border: 0;
            box-shadow: 0 0.75rem 1.75rem rgba(15, 23, 42, 0.08);
        }

        .support-panel {
            background: transparent;
        }

        .support-table thead th {
            border-bottom-color: rgba(100, 116, 139, 0.18) !important;
        }

        .support-table tbody td {
            border-top-color: rgba(100, 116, 139, 0.12) !important;
        }

        .ticket-message {
            max-width: 340px;
            white-space: normal;
        }

        .ticket-action-btn {
            border-radius: 999px;
        }

        .ticket-muted {
            color: #94a3b8;
        }
    </style>
</head>
<body>
<a href="index.php" class="text-muted text-decoration-none small mb-4 d-inline-block">← Back to Marketplace</a>
<main class="container-fluid py-4 py-lg-5 dashboard-shell">
    <div class="card page-hero border-0 soft-card mb-4">
        <div class="card-body p-4 p-lg-5">
            <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
                <div>
                    <p class="text-uppercase text-muted small mb-1">Superuser Control Center</p>
                    <h1 class="display-6 fw-semibold mb-2">Admin Dashboard</h1>
                    <p class="text-muted mb-0">User management, role control, and hub expansion in one secure workspace.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">Back to Home</a>
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

    <div class="row g-4 mb-4">
        <div class="col-12 col-md-4">
            <div class="card soft-card h-100">
                <div class="card-body p-4">
                    <p class="text-muted text-uppercase small mb-1">Users</p>
                    <div class="fs-2 fw-semibold"><?= htmlspecialchars((string) $userCount, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card soft-card h-100">
                <div class="card-body p-4">
                    <p class="text-muted text-uppercase small mb-1">Active Hubs</p>
                    <div class="fs-2 fw-semibold"><?= htmlspecialchars((string) $hubCount, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card soft-card h-100">
                <div class="card-body p-4">
                    <p class="text-muted text-uppercase small mb-1">Signed in as</p>
                    <div class="fs-2 fw-semibold text-dark">Admin</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-8">
            <div class="card soft-card h-100">
                <div class="card-body p-0">
                    <div class="d-flex justify-content-between align-items-center px-4 pt-4 pb-3">
                        <div>
                            <h2 class="h4 mb-1">User Management</h2>
                            <p class="text-muted mb-0">Edit roles or remove accounts with secure server-side handling.</p>
                        </div>
                        <span class="badge text-bg-light border"><?= htmlspecialchars((string) $userCount, ENT_QUOTES, 'UTF-8') ?> records</span>
                    </div>

                    <?php if ($users === []): ?>
                        <div class="px-4 pb-4">
                            <div class="alert alert-secondary mb-0" role="alert">No users found.</div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col" class="ps-4">ID</th>
                                        <th scope="col">Name</th>
                                        <th scope="col">Email</th>
                                        <th scope="col">Role</th>
                                        <th scope="col">Created</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <?php $currentRole = (string) ($user['role'] ?? ''); ?>
                                        <tr>
                                            <td class="ps-4"><?= htmlspecialchars((string) ($user['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="fw-medium"><?= htmlspecialchars((string) ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><span class="badge rounded-pill <?= htmlspecialchars(kasi_exchange_admin_status_badge_class($currentRole), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td><?= htmlspecialchars((string) ($user['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                                    <form method="post" action="<?= htmlspecialchars(kasi_exchange_url('admin_dashboard.php'), ENT_QUOTES, 'UTF-8') ?>" class="d-flex gap-2 align-items-center flex-wrap">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="action" value="update_role">
                                                        <input type="hidden" name="user_id" value="<?= htmlspecialchars((string) ($user['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                        <select name="role" class="form-select form-select-sm">
                                                            <?php foreach (kasi_exchange_admin_allowed_roles() as $roleOption): ?>
                                                                <option value="<?= htmlspecialchars($roleOption, ENT_QUOTES, 'UTF-8') ?>" <?= $currentRole === $roleOption ? 'selected' : '' ?>><?= htmlspecialchars($roleOption, ENT_QUOTES, 'UTF-8') ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Edit Role</button>
                                                    </form>

                                                    <form method="post" action="<?= htmlspecialchars(kasi_exchange_url('admin_dashboard.php'), ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm('Delete this user?');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?= htmlspecialchars((string) ($user['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card soft-card mb-4">
                <div class="card-body p-4">
                    <h2 class="h4 mb-2">Add New Verified Spaza Hub</h2>
                    <p class="text-muted mb-4">Register an operational hub for escrow collection and handover.</p>

                    <form method="post" action="<?= htmlspecialchars(kasi_exchange_url('admin_dashboard.php'), ENT_QUOTES, 'UTF-8') ?>" class="vstack gap-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="add_hub">
                        <div>
                            <label for="hub_name" class="form-label">Hub Name</label>
                            <input type="text" class="form-control" id="hub_name" name="hub_name" maxlength="191" required>
                        </div>
                        <div>
                            <label for="address" class="form-label">Address</label>
                            <textarea class="form-control" id="address" name="address" rows="3" maxlength="2000" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-cta w-100">Add Hub</button>
                    </form>
                </div>
            </div>

            <div class="card soft-card">
                <div class="card-body p-0">
                    <div class="px-4 pt-4 pb-3">
                        <h2 class="h4 mb-1">Current Active Hubs</h2>
                        <p class="text-muted mb-0">Operational handover points currently in the system.</p>
                    </div>

                    <?php if ($hubs === []): ?>
                        <div class="px-4 pb-4">
                            <div class="alert alert-secondary mb-0" role="alert">No hubs have been added yet.</div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col" class="ps-4">ID</th>
                                        <th scope="col">Hub Name</th>
                                        <th scope="col">Address</th>
                                        <th scope="col">Created</th>
                                        <th scope="col">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($hubs as $hub): ?>
                                        <tr>
                                            <td class="ps-4"><?= htmlspecialchars((string) ($hub['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="fw-medium"><?= htmlspecialchars((string) ($hub['hub_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($hub['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($hub['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <form method="post" action="<?= htmlspecialchars(kasi_exchange_url('admin_dashboard.php'), ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm('Delete this hub?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="delete_hub">
                                                    <input type="hidden" name="hub_id" value="<?= htmlspecialchars((string) ($hub['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-12">
            <section class="support-panel">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 px-1 mb-3">
                    <div>
                        <p class="text-uppercase text-muted small mb-1">Operations</p>
                        <h2 class="h4 mb-1">Support Ticket Management</h2>
                        <p class="text-muted mb-0">Track escalations from buyers, sellers, and agents in one place.</p>
                    </div>
                    <span class="badge text-bg-light border"><?= htmlspecialchars((string) $ticketCount, ENT_QUOTES, 'UTF-8') ?> tickets</span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0 support-table">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" class="ps-3">Ticket ID</th>
                                <th scope="col">User Role</th>
                                <th scope="col">User Name</th>
                                <th scope="col">Subject</th>
                                <th scope="col">Message</th>
                                <th scope="col">Date Submitted</th>
                                <th scope="col" class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($supportTickets === []): ?>
                                <tr>
                                    <td colspan="7" class="py-4 text-center text-muted">No support tickets have been submitted yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($supportTickets as $ticket): ?>
                                    <?php
                                    $ticketStatus = strtolower(trim((string) ($ticket['status'] ?? 'open')));
                                    $isClosed = in_array($ticketStatus, ['resolved', 'closed'], true);
                                    ?>
                                    <tr>
                                        <td class="ps-3 fw-medium">#<?= htmlspecialchars((string) ($ticket['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars(ucfirst((string) ($ticket['user_role'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($ticket['user_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="fw-medium"><?= htmlspecialchars((string) ($ticket['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="ticket-message text-muted"><?= htmlspecialchars((string) ($ticket['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($ticket['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="text-end pe-3">
                                            <?php if ($isClosed): ?>
                                                <span class="ticket-muted small">Closed</span>
                                            <?php else: ?>
                                                <form method="post" action="<?= htmlspecialchars(kasi_exchange_url('admin_dashboard.php'), ENT_QUOTES, 'UTF-8') ?>" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="resolve_ticket">
                                                    <input type="hidden" name="ticket_id" value="<?= htmlspecialchars((string) ($ticket['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary ticket-action-btn">Resolve</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>