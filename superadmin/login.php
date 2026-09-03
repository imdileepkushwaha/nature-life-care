<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/schema_setup.php';

if (!empty($_SESSION['superadmin_id'])) {
    session_enforce_idle('superadmin', 'login.php');
    header('Location: index.php');
    exit;
}

$error = '';
$setupMsg = '';
$setupOk = false;
$flash = get_flash();

try {
    $schema = mlm_schema_status($pdo);
    $needsSetup = !$schema['complete'];
} catch (Throwable $e) {
    $schema = ['missing' => [], 'existing' => [], 'complete' => false];
    $needsSetup = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['run_schema_setup'])) {
        try {
            $result = mlm_run_schema_setup($pdo);
            $setupOk = $result['ok'];
            $setupMsg = $result['message']
                . ' Tables: ' . $result['tables']
                . ', Procedures: ' . $result['procedures']
                . '.';
            if ($result['created']) {
                $setupMsg .= ' Created: ' . implode(', ', $result['created']) . '.';
            }
            $schema = mlm_schema_status($pdo);
            $needsSetup = !$schema['complete'];
        } catch (Throwable $e) {
            $error = 'Setup failed: ' . $e->getMessage();
        }
    } else {
        // Light bootstrap when tables mostly exist
        try {
            feature_ensure_superadmin_table($pdo);
            feature_ensure_defaults($pdo);
        } catch (Throwable $e) {
            // ignore — setup button handles full install
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Username and password are required.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT * FROM super_admins WHERE username = ? AND status = ? LIMIT 1');
                $stmt->execute([$username, 'active']);
                $sa = $stmt->fetch();
            } catch (Throwable $e) {
                $sa = false;
                $error = 'Database not ready. Run “Setup Database” first.';
            }

            if (empty($error) && $sa && password_verify($password, $sa['password'])) {
                $_SESSION['superadmin_id'] = (int) $sa['id'];
                $_SESSION['superadmin_name'] = $sa['full_name'];
                $_SESSION['superadmin_username'] = $sa['username'];
                session_touch('superadmin');

                $pdo->prepare('UPDATE super_admins SET last_login = NOW() WHERE id = ?')->execute([$sa['id']]);
                log_superadmin_activity('login', 'Super Admin logged in');

                header('Location: index.php');
                exit;
            }
            if (empty($error)) {
                $error = 'Invalid username or password.';
            }
        }
    }
}

try {
    $company = setting('company_name', 'Binary MLM');
} catch (Throwable $e) {
    $company = 'Binary MLM';
    $needsSetup = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login | <?= e($company) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="../assets/css/superadmin.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/superadmin.css') ?>">
</head>
<body class="auth-page sa-auth">
<div class="auth-shell">
    <div class="auth-brand sa-brand" aria-hidden="false">
        <div class="auth-brand-orb auth-brand-orb-a"></div>
        <div class="auth-brand-orb auth-brand-orb-b"></div>
        <div class="auth-brand-inner">
            <div class="auth-mark">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <p class="auth-kicker">Platform Control</p>
            <h1 class="auth-company">Super Admin</h1>
            <p class="auth-tagline">Install database, configure plan mode, modules and what this client’s Admin &amp; User panels can use.</p>
        </div>
    </div>

    <div class="auth-panel">
        <div class="auth-card">
            <div class="auth-card-head">
                <h2>Super Admin</h2>
                <p class="auth-sub">Sign in to manage this install</p>
            </div>

            <?php if (!empty($flash)): ?>
                <div class="alert alert-<?= e($flash['type'] === 'success' ? 'success' : 'error') ?> auth-alert"><?= e($flash['message']) ?></div>
            <?php endif; ?>
            <?php if ($setupMsg): ?><div class="alert alert-<?= $setupOk ? 'success' : 'error' ?> auth-alert"><?= e($setupMsg) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error auth-alert"><?= e($error) ?></div><?php endif; ?>

            <?php if ($needsSetup): ?>
            <div class="sa-setup-box">
                <form method="post" onsubmit="return confirm('Create / update all missing tables and procedures now?');">
                    <input type="hidden" name="run_schema_setup" value="1">
                    <p class="sa-setup-text">Database incomplete (<?= (int) count($schema['missing']) ?> tables missing). One click installs tables + procedures, Super Admin, and Client Admin (admin / admin123).</p>
                    <button type="submit" class="sa-setup-btn">Setup Database (Tables + Procedures)</button>
                </form>
            </div>
            <?php endif; ?>

            <form method="post" class="auth-form" autocomplete="off">
                <div class="auth-field">
                    <label for="username">Username</label>
                    <div class="auth-input">
                        <span class="auth-input-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <input type="text" id="username" name="username" value="<?= e($_POST['username'] ?? '') ?>" placeholder="superadmin" required autofocus>
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
                <button type="submit" class="btn btn-primary btn-block auth-submit sa-submit">
                    <span>Sign in</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
                </button>
            </form>

            <p class="sa-hint">Default: <code>superadmin</code> / <code>superadmin123</code> — change after first login.</p>
            <p class="sa-hint"><a href="../admin/login.php">Open Client Admin login →</a></p>
        </div>
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
