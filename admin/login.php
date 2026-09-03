<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/schema_setup.php';

if (!empty($_SESSION['admin_id'])) {
    session_enforce_idle('admin', 'login.php');
    header('Location: index.php');
    exit;
}

$error = '';
$flash = get_flash();

try {
    $schema = mlm_schema_status($pdo);
    $needsSetup = !$schema['complete'];
} catch (Throwable $e) {
    $needsSetup = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ? AND status = ? LIMIT 1');
            $stmt->execute([$username, 'active']);
            $admin = $stmt->fetch();
        } catch (Throwable $e) {
            $admin = false;
            $error = 'System is not ready. Please contact support.';
            $needsSetup = true;
        }

        if (empty($error) && $admin && password_verify($password, $admin['password'])) {
            if (!client_license_ok()) {
                $error = client_license_message() ?: 'Access is temporarily unavailable. Please contact support.';
            } else {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['full_name'];
                $_SESSION['admin_username'] = $admin['username'];
                session_touch('admin');

                $pdo->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?')->execute([$admin['id']]);
                log_activity('login', 'Admin logged in');

                header('Location: index.php');
                exit;
            }
        }
        if (empty($error)) {
            $error = 'Invalid username or password.';
        }
    }
}

try {
    $company = setting('company_name', 'Binary MLM');
} catch (Throwable $e) {
    $company = 'Binary MLM';
    $needsSetup = true;
}
$logoUrl = function_exists('company_logo_url') ? company_logo_url() : null;
$favUrl = function_exists('company_favicon_url') ? company_favicon_url() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | <?= e($company) ?></title>
    <?php if ($favUrl): ?><link rel="icon" href="<?= e($favUrl) ?>"><?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="auth-page">
<div class="auth-shell">
    <div class="auth-brand" aria-hidden="false">
        <div class="auth-brand-orb auth-brand-orb-a"></div>
        <div class="auth-brand-orb auth-brand-orb-b"></div>
        <div class="auth-brand-inner">
            <div class="auth-mark">
                <?php if ($logoUrl): ?>
                <img src="<?= e($logoUrl) ?>" alt="<?= e($company) ?>" style="width:100%;height:100%;object-fit:contain;border-radius:14px">
                <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                <?php endif; ?>
            </div>
            <p class="auth-kicker">Admin Panel</p>
            <h1 class="auth-company"><?= e($company) ?></h1>
            <p class="auth-tagline">Manage members, commissions, products and payouts from one secure dashboard.</p>
        </div>
    </div>

    <div class="auth-panel">
        <div class="auth-card">
            <div class="auth-card-head">
                <h2>Welcome back</h2>
                <p class="auth-sub">Sign in to continue to your dashboard</p>
            </div>

            <?php if (!empty($flash)): ?>
                <div class="alert alert-<?= e($flash['type'] === 'success' ? 'success' : ($flash['type'] === 'error' ? 'error' : 'info')) ?> auth-alert"><?= e($flash['message']) ?></div>
            <?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error auth-alert"><?= e($error) ?></div><?php endif; ?>

            <?php if (!empty($needsSetup)): ?>
            <div class="alert alert-info auth-alert">
                System is not ready yet. Please contact support if you cannot sign in.
            </div>
            <?php endif; ?>

            <form method="post" autocomplete="off" class="auth-form">
                <div class="auth-field">
                    <label for="username">Username</label>
                    <div class="auth-input">
                        <span class="auth-input-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <input type="text" id="username" name="username" value="<?= e($_POST['username'] ?? '') ?>" placeholder="Enter username" required autofocus>
                    </div>
                </div>

                <div class="auth-field">
                    <label for="password">Password</label>
                    <div class="auth-input password-field">
                        <span class="auth-input-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        </span>
                        <input type="password" id="password" name="password" placeholder="Enter password" required>
                        <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                            <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block auth-submit">
                    <span>Sign in</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
                </button>
            </form>
        </div>
        <p class="auth-foot">&copy; <?= date('Y') ?> <?= e($company) ?></p>
    </div>
</div>
<script>
document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => {
        const wrap = btn.closest('.password-field');
        const input = wrap && wrap.querySelector('input');
        if (!input) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.classList.toggle('is-visible', show);
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.setAttribute('title', show ? 'Hide password' : 'Show password');
    });
});
</script>
</body>
</html>
