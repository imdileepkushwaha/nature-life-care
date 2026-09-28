<?php
/**
 * Franchise Reset Password Handler
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/franchise.php';

if (!empty($_SESSION['franchise_id'])) {
    header('Location: index.php');
    exit;
}

$company = setting('company_name', 'Binary MLM');
$logoUrl = company_logo_url();
$favUrl = company_favicon_url();

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$reset = $token !== '' ? franchise_pw_find_valid_token($pdo, $token) : null;

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$reset) {
        $error = 'This password reset link is invalid or has expired. Please request a new one.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Passwords do not match.';
        } else {
            franchise_pw_complete_reset($pdo, (int) $reset['reset_id'], (int) $reset['franchisee_id'], $password);
            flash('success', 'Your password has been reset successfully! You can now log in with your new password.');
            header('Location: login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password | <?= e($company) ?></title>
    <?php if ($favUrl): ?><link rel="icon" href="<?= e($favUrl) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-primary: #0284c7;
            --brand-primary-hover: #0369a1;
            --bg-dark: #0b132b;
            --card-glass: rgba(255, 255, 255, 0.96);
            --text-main: #0f172a;
            --text-muted: #64748b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle at 10% 20%, #1e293b 0%, #0f172a 45%, #080d1a 100%);
            padding: 1.5rem;
            position: relative;
            overflow-x: hidden;
        }
        .bg-mesh-1, .bg-mesh-2 {
            position: absolute;
            border-radius: 50%;
            filter: blur(90px);
            pointer-events: none;
            opacity: 0.35;
        }
        .bg-mesh-1 {
            width: 480px; height: 480px;
            background: linear-gradient(135deg, #0284c7, #38bdf8);
            top: -100px; left: -100px;
        }
        .bg-mesh-2 {
            width: 450px; height: 450px;
            background: linear-gradient(135deg, #10b981, #06b6d4);
            bottom: -80px; right: -80px;
        }
        .card-wrap {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 460px;
        }
        .auth-card {
            background: var(--card-glass);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 2.75rem 2.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.6);
        }
        .card-head {
            text-align: center;
            margin-bottom: 1.75rem;
        }
        .brand-logo {
            max-height: 48px;
            max-width: 170px;
            object-fit: contain;
            margin-bottom: 0.85rem;
        }
        .title {
            font-size: 1.55rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.02em;
        }
        .subtitle {
            font-size: 0.88rem;
            color: var(--text-muted);
            margin-top: 0.4rem;
        }
        .verified-banner {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            padding: 0.85rem 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .verified-banner svg {
            color: #16a34a;
            width: 22px; height: 22px;
            flex-shrink: 0;
        }
        .verified-text {
            font-size: 0.86rem;
            color: #166534;
        }
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            font-size: 0.86rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            line-height: 1.4;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-group label {
            display: block;
            font-size: 0.84rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 0.45rem;
        }
        .input-box {
            position: relative;
        }
        .input-box svg {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 19px;
            height: 19px;
            color: #94a3b8;
        }
        .input-box input {
            width: 100%;
            padding: 0.75rem 2.75rem 0.75rem 2.75rem;
            font-size: 0.92rem;
            font-family: inherit;
            color: var(--text-main);
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            outline: none;
            transition: all 0.2s ease;
        }
        .input-box input:focus {
            background: #ffffff;
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 4px rgba(2, 132, 199, 0.15);
        }
        .btn-eye {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 0.25rem;
        }
        .btn-eye:hover { color: #475569; }
        .btn-submit {
            width: 100%;
            padding: 0.85rem;
            font-size: 0.98rem;
            font-weight: 700;
            font-family: inherit;
            color: #ffffff;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            border: none;
            border-radius: 12px;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.35);
            transition: all 0.2s ease;
            margin-top: 0.5rem;
        }
        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(2, 132, 199, 0.45);
        }
        .card-foot {
            margin-top: 2rem;
            padding-top: 1.25rem;
            border-top: 1px solid #f1f5f9;
            text-align: center;
            font-size: 0.88rem;
            color: var(--text-muted);
        }
        .card-foot a {
            color: var(--brand-primary);
            text-decoration: none;
            font-weight: 700;
        }
    </style>
</head>
<body>

<div class="bg-mesh-1"></div>
<div class="bg-mesh-2"></div>

<div class="card-wrap">
    <div class="auth-card">
        <div class="card-head">
            <?php if ($logoUrl): ?>
                <img src="<?= e($logoUrl) ?>" alt="<?= e($company) ?>" class="brand-logo">
            <?php endif; ?>
            <h1 class="title">Create New Password</h1>
            <p class="subtitle">Set a strong and secure password for your franchise account</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert-error">
                <svg style="width:20px;height:20px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!$reset): ?>
            <div class="alert-error">
                This reset link is invalid or has expired. Please verify your details again.
            </div>
            <a href="forgot-password.php" class="btn-submit" style="text-align:center;text-decoration:none;display:block">
                Request New Password Reset →
            </a>
        <?php else: ?>
            <div class="verified-banner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <div class="verified-text">
                    Franchise Verified: <strong><?= e($reset['franchisee_code']) ?></strong> (<?= e($reset['name']) ?>)
                </div>
            </div>

            <form method="post" action="reset-password.php">
                <input type="hidden" name="token" value="<?= e($token) ?>">

                <div class="form-group">
                    <label for="newPass">New Password (min 6 characters) *</label>
                    <div class="input-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        <input type="password" id="newPass" name="password" minlength="6" placeholder="Enter new password" required autofocus>
                        <button type="button" class="btn-eye" onclick="togglePass('newPass')">
                            <svg style="width:18px;height:18px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="newPassConfirm">Confirm New Password *</label>
                    <div class="input-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
                        <input type="password" id="newPassConfirm" name="password_confirm" minlength="6" placeholder="Repeat new password" required>
                        <button type="button" class="btn-eye" onclick="togglePass('newPassConfirm')">
                            <svg style="width:18px;height:18px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    Save New Password & Sign In
                </button>
            </form>
        <?php endif; ?>

        <div class="card-foot">
            <p><a href="login.php">← Back to Login</a></p>
        </div>
    </div>
</div>

<script>
function togglePass(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.type = (el.type === 'password') ? 'text' : 'password';
}
</script>

</body>
</html>
