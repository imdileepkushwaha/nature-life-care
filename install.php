<?php
/**
 * Binary MLM - One-time Installer
 * Run once, then DELETE this file.
 * Safe to re-run if tables already exist (only resets admin password).
 */
require_once __DIR__ . '/config/env.php';

$env = app_env_name();
$savedDb = app_db_config_for_current();
$host = $savedDb['host'];
$user = $savedDb['user'];
$pass = $savedDb['pass'];
$dbName = $savedDb['name'];
$adminUser = 'admin';
$adminPass = 'admin123';
$adminEmail = 'admin@naturelifecare.com';

$error = '';
$success = '';
$setupMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $user = trim($_POST['db_user'] ?? 'root');
    $pass = $_POST['db_pass'] ?? '';
    $dbName = trim($_POST['db_name'] ?? 'naturelife_db');
    $adminUser = trim($_POST['admin_user'] ?? 'admin');
    $adminPass = $_POST['admin_pass'] ?? 'admin123';
    $adminEmail = trim($_POST['admin_email'] ?? 'admin@naturelifecare.com');

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbName;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        app_write_db_credentials($env, [
            'host' => $host,
            'name' => $dbName,
            'user' => $user,
            'pass' => $pass,
        ]);

        require_once __DIR__ . '/includes/schema_setup.php';
        $setup = mlm_run_schema_setup($pdo);

        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $check = $pdo->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
        $check->execute([$adminUser]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $pdo->prepare('UPDATE admins SET email = ?, password = ?, full_name = ? WHERE id = ?');
            $stmt->execute([$adminEmail, $hash, 'Super Admin', $existing['id']]);
        } else {
            $stmt = $pdo->prepare('UPDATE admins SET username = ?, email = ?, password = ?, full_name = ? WHERE id = 1');
            $stmt->execute([$adminUser, $adminEmail, $hash, 'Super Admin']);
            if ($stmt->rowCount() === 0) {
                $pdo->prepare('INSERT INTO admins (username, email, password, full_name) VALUES (?, ?, ?, ?)')
                    ->execute([$adminUser, $adminEmail, $hash, 'Super Admin']);
            }
        }

        try {
            $memberHash = password_hash('member123', PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE members SET password = ? WHERE id = 1')->execute([$memberHash]);
        } catch (Throwable $e) {
            // optional sample member
        }

        $success = '1';
        $setupMessage = ($setup['message'] ?? 'Database setup complete.') . ' Environment saved: ' . strtoupper($env) . '.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install · Bharat Seva</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="auth-page install-page">
<div class="auth-shell">
    <div class="auth-brand" aria-hidden="false">
        <div class="auth-brand-orb auth-brand-orb-a"></div>
        <div class="auth-brand-orb auth-brand-orb-b"></div>
        <div class="auth-brand-inner">
            <div class="auth-mark">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            </div>
            <p class="auth-kicker">One-time setup</p>
            <h1 class="auth-company">Bharat Seva Install</h1>
            <p class="auth-tagline">Connect your MySQL database, create tables, and set the first admin account in one step.</p>

            <ol class="install-checklist">
                <li>
                    <span class="install-check-ico" aria-hidden="true">1</span>
                    <span>Create an empty MySQL database first</span>
                </li>
                <li>
                    <span class="install-check-ico" aria-hidden="true">2</span>
                    <span>Enter connection details and admin login</span>
                </li>
                <li>
                    <span class="install-check-ico" aria-hidden="true">3</span>
                    <span>Install, then delete this file</span>
                </li>
            </ol>
        </div>
    </div>

    <div class="auth-panel">
        <div class="auth-card install-card">
            <?php if ($success): ?>
            <div class="install-done">
                <div class="install-done-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6L9 17l-5-5"/></svg>
                </div>
                <h2>Installation complete</h2>
                <p class="auth-sub"><?= htmlspecialchars($setupMessage) ?></p>
                <div class="install-done-box">
                    <div>
                        <span>Admin username</span>
                        <strong><?= htmlspecialchars($adminUser) ?></strong>
                    </div>
                    <div>
                        <span>Password</span>
                        <strong>The password you just set</strong>
                    </div>
                </div>
                <p class="install-warn">Delete <code>install.php</code> now so this page cannot be run again.</p>
                <a href="admin/login.php" class="btn btn-primary btn-block auth-submit">
                    <span>Go to Admin Login</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
                </a>
            </div>
            <?php else: ?>
            <div class="auth-card-head">
                <h2>Setup workspace</h2>
                <p class="auth-sub">Database must already exist. This installer creates tables and the admin user.</p>
            </div>

            <div class="install-progress" aria-hidden="true">
                <span class="is-active">Database</span>
                <i></i>
                <span>Admin</span>
                <i></i>
                <span>Finish</span>
            </div>

            <?php if ($error): ?><div class="alert alert-error auth-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <div class="alert alert-info auth-alert">
                Running in <strong><?= htmlspecialchars(strtoupper($env)) ?></strong> mode.
                This installer saves only the <?= htmlspecialchars($env) ?> database settings, so local and live stay separate.
            </div>

            <form method="post" autocomplete="off" class="auth-form">
                <section class="install-section">
                    <header>
                        <span class="install-section-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>
                        </span>
                        <div>
                            <h3>Database connection</h3>
                            <p><?= $env === 'local' ? 'Local MySQL / XAMPP credentials' : 'Live server MySQL credentials' ?></p>
                        </div>
                    </header>
                    <div class="install-grid">
                        <div class="auth-field">
                            <label for="db_host">DB Host</label>
                            <div class="auth-input">
                                <span class="auth-input-ico" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 010 18"/><path d="M12 3a14 14 0 000 18"/></svg>
                                </span>
                                <input type="text" id="db_host" name="db_host" value="<?= htmlspecialchars($host) ?>" placeholder="localhost" required>
                            </div>
                        </div>
                        <div class="auth-field">
                            <label for="db_name">DB Name</label>
                            <div class="auth-input">
                                <span class="auth-input-ico" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16v10a2 2 0 01-2 2H6a2 2 0 01-2-2V7z"/><path d="M8 7V5a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                </span>
                                <input type="text" id="db_name" name="db_name" value="<?= htmlspecialchars($dbName) ?>" placeholder="naturelife_db" required>
                            </div>
                        </div>
                        <div class="auth-field">
                            <label for="db_user">DB User</label>
                            <div class="auth-input">
                                <span class="auth-input-ico" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                </span>
                                <input type="text" id="db_user" name="db_user" value="<?= htmlspecialchars($user) ?>" placeholder="root" required>
                            </div>
                        </div>
                        <div class="auth-field">
                            <label for="db_pass">DB Password</label>
                            <div class="auth-input password-field">
                                <span class="auth-input-ico" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                                </span>
                                <input type="password" id="db_pass" name="db_pass" value="<?= htmlspecialchars($pass) ?>" placeholder="Leave blank if none">
                                <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                                    <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="install-section">
                    <header>
                        <span class="install-section-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 12a4 4 0 100-8 4 4 0 000 8z"/><path d="M4 20a8 8 0 0116 0"/><path d="M19 8l2 2-2 2"/></svg>
                        </span>
                        <div>
                            <h3>Admin account</h3>
                            <p>First login for the client admin panel</p>
                        </div>
                    </header>
                    <div class="install-grid">
                        <div class="auth-field">
                            <label for="admin_user">Admin Username</label>
                            <div class="auth-input">
                                <span class="auth-input-ico" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                </span>
                                <input type="text" id="admin_user" name="admin_user" value="<?= htmlspecialchars($adminUser) ?>" placeholder="admin" required>
                            </div>
                        </div>
                        <div class="auth-field">
                            <label for="admin_email">Admin Email</label>
                            <div class="auth-input">
                                <span class="auth-input-ico" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16v12H4z"/><path d="M4 7l8 6 8-6"/></svg>
                                </span>
                                <input type="email" id="admin_email" name="admin_email" value="<?= htmlspecialchars($adminEmail) ?>" placeholder="admin@example.com" required>
                            </div>
                        </div>
                        <div class="auth-field install-span-2">
                            <label for="admin_pass">Admin Password</label>
                            <div class="auth-input password-field">
                                <span class="auth-input-ico" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                                </span>
                                <input type="password" id="admin_pass" name="admin_pass" value="<?= htmlspecialchars($adminPass) ?>" placeholder="Choose a strong password" required>
                                <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                                    <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <button type="submit" class="btn btn-primary btn-block auth-submit">
                    <span>Install Now</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
                </button>
            </form>
            <?php endif; ?>
        </div>
        <p class="auth-foot">Safe to re-run if tables already exist · only the admin password is reset</p>
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
