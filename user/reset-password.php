<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/password-reset.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

ensure_password_resets_table($pdo);

$company = setting('company_name', 'Binary MLM');
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$reset = $token !== '' ? pw_reset_find_valid($pdo, $token) : null;
$invalid = ($token === '' || !$reset);

if (!$invalid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    $reset = pw_reset_find_valid($pdo, $token);
    if (!$reset) {
        $invalid = true;
        $errors[] = 'This reset link is invalid or has expired.';
    }

    if (!$errors && !$invalid) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE members SET password = ? WHERE id = ?')
            ->execute([$hash, (int) $reset['mid']]);
        pw_reset_mark_used($pdo, (int) $reset['reset_id']);

        try {
            log_activity('password_reset_complete', 'Password reset for member #' . (int) $reset['mid']);
        } catch (Throwable $e) {
            // ignore
        }

        flash('success', 'Password updated successfully. Please sign in.');
        header('Location: login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | <?= e($company) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=Unbounded:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/user.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/user.css') ?>">
</head>
<body class="ulog-body">
<div class="ulog">
    <div class="ulog-stage" aria-hidden="true">
        <span class="ulog-blade ulog-blade-a"></span>
        <span class="ulog-blade ulog-blade-b"></span>
        <span class="ulog-blade ulog-blade-c"></span>
        <span class="ulog-dots"></span>
    </div>

    <aside class="ulog-rail" aria-hidden="true">
        <svg class="ulog-tree" viewBox="0 0 280 520" fill="none">
            <path class="ulog-tree-line" d="M140 40 V160 M140 160 L60 260 M140 160 L220 260 M60 260 V340 M220 260 V340 M60 340 L30 420 M60 340 L90 420 M220 340 L190 420 M220 340 L250 420"/>
            <circle class="ulog-tree-dot is-core" cx="140" cy="40" r="10"/>
            <circle class="ulog-tree-dot" cx="140" cy="160" r="8"/>
            <circle class="ulog-tree-dot" cx="60" cy="260" r="7"/>
            <circle class="ulog-tree-dot" cx="220" cy="260" r="7"/>
            <circle class="ulog-tree-dot" cx="60" cy="340" r="6"/>
            <circle class="ulog-tree-dot" cx="220" cy="340" r="6"/>
            <circle class="ulog-tree-dot" cx="30" cy="420" r="5"/>
            <circle class="ulog-tree-dot" cx="90" cy="420" r="5"/>
            <circle class="ulog-tree-dot" cx="190" cy="420" r="5"/>
            <circle class="ulog-tree-dot" cx="250" cy="420" r="5"/>
        </svg>
        <p class="ulog-rail-copy">New password.<br>Same account.<br>Fresh start.</p>
    </aside>

    <main class="ulog-main">
        <p class="ulog-kicker">Account recovery</p>
        <p class="ulog-brand"><?= e($company) ?></p>
        <?php if ($invalid): ?>
            <h1 class="ulog-title">This reset link isn’t valid</h1>
            <p class="ulog-lead">It may have expired or already been used. Request a new link to continue.</p>
            <div class="up-alert up-alert-err">This password reset link is invalid or has expired.</div>
            <div class="ulog-form">
                <a href="forgot-password.php" class="ulog-submit">
                    <span>Request new link</span>
                    <span class="ulog-submit-arrow" aria-hidden="true">→</span>
                </a>
            </div>
        <?php else: ?>
            <h1 class="ulog-title">Choose a new password</h1>
            <p class="ulog-lead">Hi <?= e($reset['full_name'] ?? 'Member') ?> — updating password for <strong>@<?= e($reset['username'] ?? '') ?></strong>. Link works once and expires in 1 hour.</p>
            <?php foreach ($errors as $err): ?>
                <div class="up-alert up-alert-err"><?= e($err) ?></div>
            <?php endforeach; ?>
            <form method="post" class="ulog-form" autocomplete="off">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="ulog-field">
                    <label for="password">New password</label>
                    <div class="up-password-wrap">
                        <input type="password" id="password" name="password" placeholder="At least 6 characters" required autofocus>
                        <button type="button" class="up-eye" data-password-toggle aria-label="Show password">
                            <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>
                <div class="ulog-field">
                    <label for="password_confirm">Confirm password</label>
                    <div class="up-password-wrap">
                        <input type="password" id="password_confirm" name="password_confirm" placeholder="Re-enter password" required>
                        <button type="button" class="up-eye" data-password-toggle aria-label="Show password">
                            <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="ulog-submit">
                    <span>Update password</span>
                    <span class="ulog-submit-arrow" aria-hidden="true">→</span>
                </button>
            </form>
        <?php endif; ?>
        <footer class="ulog-foot">
            <p><a href="login.php">← Back to sign in</a></p>
            <p class="ulog-copy">&copy; <?= date('Y') ?> <?= e($company) ?></p>
        </footer>
    </main>
</div>
<script src="assets/js/user.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/user.js') ?>"></script>
</body>
</html>
