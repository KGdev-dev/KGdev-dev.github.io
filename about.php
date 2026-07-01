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

$homeUrl = kasi_exchange_url('index.php');
$loginUrl = kasi_exchange_url('login.php');

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasi Exchange | About</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --mint-bg: #dff6e9;
            --mint-panel: rgba(255, 255, 255, 0.12);
            --mint-text: #1f4a39;
            --mint-muted: rgba(31, 74, 57, 0.76);
            --orange: #ff8c00;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            background: linear-gradient(135deg, #dff6e9 0%, #d3f2e2 100%);
            color: var(--mint-text);
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        .about-shell {
            max-width: 1100px;
        }

        .about-kicker {
            color: var(--orange);
            font-size: 0.75rem;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .about-title {
            letter-spacing: -0.04em;
            line-height: 0.95;
        }

        .flat-panel {
            background: transparent;
            border: 0;
            box-shadow: none;
        }

        .about-copy,
        .flow-copy,
        .flow-detail {
            color: var(--mint-muted);
        }

        .flow-step {
            padding: 0.35rem 0;
        }

        .step-number {
            color: var(--orange);
            font-weight: 800;
            letter-spacing: -0.03em;
            margin-right: 0.5rem;
        }

        .flow-card {
            padding: 0.15rem 0;
        }

        .mini-rule {
            width: 3.25rem;
            height: 2px;
            background: var(--orange);
            border-radius: 999px;
        }

        .top-links a {
            color: var(--mint-text);
            text-decoration: none;
        }

        .top-links a:hover {
            color: var(--orange);
        }

        @media (max-width: 767.98px) {
            .about-title {
                font-size: clamp(2.2rem, 11vw, 3.4rem);
            }
        }
    </style>
</head>
<body>
<main class="container py-4 py-lg-5 about-shell min-vh-100 d-flex flex-column justify-content-center">
    <div class="d-flex justify-content-between align-items-center mb-4 top-links">
        <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>">← Back to Marketplace</a>
        <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Log in</a>
    </div>

    <section class="flat-panel mb-5">
        <div class="about-kicker mb-2">Platform Overview</div>
        <h1 class="display-4 fw-bold about-title mb-3">About Kasi Exchange</h1>
        <p class="lead about-copy mb-0">
            Kasi Exchange is built to make local school uniform trading easier, safer, and more community-centered. It connects buyers,
            sellers, and local Spaza Hub partners in one simple system so families can find verified uniforms, complete payment securely,
            and complete collection without the usual confusion or risk. The platform keeps the process local, transparent, and practical,
            helping uniforms move through the community with less friction and more trust.
        </p>
    </section>

    <section class="flat-panel">
        <div class="about-kicker mb-2">Process Flow</div>
        <h2 class="h1 fw-bold about-title mb-3">The Deep-Dive Process Flow</h2>
        <p class="flow-copy mb-4">
            Below is the full peer-to-peer escrow path that powers the platform from purchase to payout.
        </p>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="flow-card">
                    <div class="mini-rule mb-3"></div>
                    <h3 class="h4 fw-bold mb-2"><span class="step-number">Step 1:</span>Secure Listing &amp; Escrow Payment</h3>
                    <p class="flow-detail mb-0">
                        Buyers choose verified items and make payment. Funds are securely locked in the platform’s escrow system to prevent fraud.
                    </p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="flow-card">
                    <div class="mini-rule mb-3"></div>
                    <h3 class="h4 fw-bold mb-2"><span class="step-number">Step 2:</span>Spaza Hub Drop-Off</h3>
                    <p class="flow-detail mb-0">
                        Sellers receive an alert and securely deliver the school uniform to their nearest local Spaza Hub partner.
                    </p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="flow-card">
                    <div class="mini-rule mb-3"></div>
                    <h3 class="h4 fw-bold mb-2"><span class="step-number">Step 3:</span>Hub Agent Verification</h3>
                    <p class="flow-detail mb-0">
                        A registered Spaza Hub Agent inspects the item for quality, size match, and condition, confirming the physical drop-off in the system.
                    </p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="flow-card">
                    <div class="mini-rule mb-3"></div>
                    <h3 class="h4 fw-bold mb-2"><span class="step-number">Step 4:</span>Safe Collection &amp; Release</h3>
                    <p class="flow-detail mb-0">
                        The buyer collects the verified uniform from the hub, triggering the escrow engine to release the payout to the seller.
                    </p>
                </div>
            </div>
        </div>
    </section>
</main>
</body>
</html>
