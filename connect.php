<?php

require_once __DIR__ . '/path_helpers.php';
require_once __DIR__ . '/db_connect.php';

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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (id INT AUTO_INCREMENT PRIMARY KEY, user_role VARCHAR(50) NOT NULL, user_name VARCHAR(191) NOT NULL, subject VARCHAR(191) NOT NULL, message TEXT NOT NULL, status VARCHAR(50) NOT NULL DEFAULT 'open', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
} catch (Throwable $throwable) {
    // best effort only
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    if ($sessionToken !== '' && hash_equals($sessionToken, $submittedToken)) {
        $role = strtolower(trim((string) ($_POST['role'] ?? '')));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        $userName = trim((string) ($_SESSION['user_name'] ?? $_SESSION['user_name'] ?? 'Guest'));

        $allowedRoles = ['buyer', 'seller', 'hub_agent'];

        if (in_array($role, $allowedRoles, true) && $subject !== '' && $message !== '') {
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO support_tickets (user_role, user_name, subject, message, status) VALUES (:user_role, :user_name, :subject, :message, :status)'
                );
                $insert->execute([
                    ':user_role' => $role,
                    ':user_name' => $userName !== '' ? $userName : 'Guest',
                    ':subject' => $subject,
                    ':message' => $message,
                    ':status' => 'open',
                ]);

                $_SESSION['ticket_success'] = true;
            } catch (Throwable $throwable) {
                $_SESSION['ticket_success'] = false;
            }
        }
    }

    header('Location: ' . kasi_exchange_url('connect.php'));
    exit;
}

$adminOverviewUrl = kasi_exchange_url('admin_dashboard.php');
$homeUrl = kasi_exchange_url('index.php');

$supportTickets = [];

try {
    $ticketStmt = $pdo->query('SELECT id, user_role, user_name, subject, message, created_at FROM support_tickets ORDER BY created_at DESC');
    $supportTickets = $ticketStmt->fetchAll();
} catch (Throwable $throwable) {
    $supportTickets = [];
}

$ticketSuccess = (bool) ($_SESSION['ticket_success'] ?? false);
unset($_SESSION['ticket_success']);

$reviews = [
    ['rating' => 5, 'name' => 'Buyer - Amina', 'text' => 'Clean handoff and fast hub verification.'],
    ['rating' => 4, 'name' => 'Seller - Kabelo', 'text' => 'The escrow flow felt secure and easy to follow.'],
    ['rating' => 5, 'name' => 'Hub Agent - Vusi', 'text' => 'Smooth partner workflow and clear status updates.'],
    ['rating' => 4, 'name' => 'Buyer - Sipho', 'text' => 'Good experience collecting the uniform from the hub.'],
];

if (!function_exists('kasi_exchange_connect_stars')) {
    function kasi_exchange_connect_stars(int $rating): string
    {
        $rating = max(1, min(5, $rating));
        return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
    }
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | Connect</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --mint-bg: #dff6e9;
            --mint-ink: #23493b;
            --mint-muted: rgba(35, 73, 59, 0.74);
            --orange: #ff8c00;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            background: linear-gradient(135deg, #dff6e9 0%, #d4f1e1 100%);
            color: var(--mint-ink);
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        .connect-shell {
            max-width: 1200px;
        }

        .flat-block {
            background: transparent;
            border: 0;
            box-shadow: none;
        }

        .accent-kicker {
            color: var(--orange);
            text-transform: uppercase;
            letter-spacing: 0.22em;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .connect-title {
            letter-spacing: -0.04em;
            line-height: 0.95;
        }

        .muted-copy {
            color: var(--mint-muted);
        }

        .contact-line {
            color: var(--orange);
            font-weight: 700;
            word-break: break-word;
        }

        .flat-input,
        .flat-select,
        .flat-textarea {
            border: 1px solid rgba(255, 140, 0, 0.16) !important;
            border-radius: 0.9rem !important;
            background: rgba(255, 255, 255, 0.55) !important;
            box-shadow: none !important;
            color: var(--mint-ink) !important;
        }

        .flat-input:focus,
        .flat-select:focus,
        .flat-textarea:focus {
            border-color: var(--orange) !important;
            box-shadow: 0 0 0 0.2rem rgba(255, 140, 0, 0.16) !important;
        }

        .flat-btn {
            background: linear-gradient(135deg, var(--orange) 0%, #ff9800 100%);
            border: 0;
            color: #fff;
            box-shadow: none;
            border-radius: 999px;
            font-weight: 700;
        }

        .flat-btn:hover,
        .flat-btn:focus {
            color: #fff;
            background: linear-gradient(135deg, #ff9a1f 0%, #ff8c00 100%);
        }

        .ticket-row,
        .review-row {
            border-bottom: 1px solid rgba(35, 73, 59, 0.1);
            padding: 0.85rem 0;
        }

        .ticket-row:last-child,
        .review-row:last-child {
            border-bottom: 0;
        }

        .review-stars {
            color: var(--orange);
            letter-spacing: 0.12em;
            font-size: 0.95rem;
        }

        .admin-link {
            color: var(--orange);
            text-decoration: none;
            font-weight: 700;
        }

        .admin-link:hover {
            text-decoration: underline;
        }

        .section-gap {
            margin-top: 1.5rem;
        }
    </style>
</head>
<body>
<main class="container py-4 py-lg-5 connect-shell min-vh-100 d-flex flex-column justify-content-center">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none muted-copy">← Back to Marketplace</a>
        <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
            <a href="<?= htmlspecialchars($adminOverviewUrl, ENT_QUOTES, 'UTF-8') ?>" class="admin-link">Admin Overview</a>
        <?php endif; ?>
    </div>

    <section class="flat-block mb-4">
        <div class="accent-kicker mb-2">Support Hub</div>
        <h1 class="display-5 fw-bold connect-title mb-2">Support Hub managed by Kgaogelo</h1>
        <p class="lead muted-copy mb-2">Need help with escrow, hubs, orders, or verification? Use the support flow below or reach the team directly.</p>
        <div class="contact-line">eduv4928092@vossie.net</div>
    </section>

    <div class="row g-4 section-gap">
        <div class="col-lg-6">
            <section class="flat-block">
                <div class="accent-kicker mb-2">Support Ticket Log</div>
                <h2 class="h3 fw-bold mb-3">Submit an issue</h2>
                <?php if ($ticketSuccess): ?>
                    <div id="success-message" class="alert alert-success border-0 mb-4" role="alert">Ticket submitted successfully!</div>
                <?php endif; ?>

                <form action="<?= htmlspecialchars(kasi_exchange_url('connect.php'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="mb-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="role" class="form-label fw-semibold">Role</label>
                            <select id="role" name="role" class="form-select flat-select" required>
                                <option value="">Choose role</option>
                                <option value="buyer">Buyer</option>
                                <option value="seller">Seller</option>
                                <option value="hub_agent">Hub Agent</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label for="subject" class="form-label fw-semibold">Subject</label>
                            <input type="text" id="subject" name="subject" class="form-control flat-input" placeholder="Short issue title" required>
                        </div>
                        <div class="col-12">
                            <label for="message" class="form-label fw-semibold">Message</label>
                            <textarea id="message" name="message" class="form-control flat-textarea" rows="4" placeholder="Describe the issue" required></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn flat-btn px-4 py-2">Send Ticket</button>
                        </div>
                    </div>
                </form>

                <div class="accent-kicker mb-2">Recent Tickets</div>
                <div class="flat-block">
                    <?php if ($supportTickets === []): ?>
                        <div class="ticket-row">
                            <div class="muted-copy">No support tickets yet.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($supportTickets as $ticket): ?>
                            <div class="ticket-row">
                                <div class="fw-semibold"><?= htmlspecialchars('[' . ucfirst((string) ($ticket['user_role'] ?? '')) . ' - ' . (string) ($ticket['user_name'] ?? 'Guest') . ']', ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="muted-copy"><?= htmlspecialchars((string) ($ticket['subject'] ?? '') . ': ' . (string) ($ticket['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="flat-block">
                <div class="accent-kicker mb-2">Feedback Feed</div>
                <h2 class="h3 fw-bold mb-3">Kasi Exchange Platform Reviews</h2>
                <div class="flat-block">
                    <?php foreach ($reviews as $review): ?>
                        <div class="review-row">
                            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                <div>
                                    <div class="review-stars mb-1"><?= htmlspecialchars(kasi_exchange_connect_stars((int) $review['rating']), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="fw-semibold"><?= htmlspecialchars($review['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="text-end muted-copy small"><?= htmlspecialchars((string) $review['rating'], ENT_QUOTES, 'UTF-8') ?>/5</div>
                            </div>
                            <p class="muted-copy mb-0 mt-2"><?= htmlspecialchars($review['text'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
</main>
<?php if ($ticketSuccess): ?>
<script>
setTimeout(() => {
    const msg = document.getElementById('success-message');
    if (msg) msg.style.display = 'none';
}, 3000);
</script>
<?php endif; ?>
</body>
</html>
