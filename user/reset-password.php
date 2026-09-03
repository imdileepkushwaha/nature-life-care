<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/password-reset.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

ensure_password_resets_table($pdo);

$company = setting('company_name', 'Binary MLM');
$logoUrl = company_logo_url();
$favUrl = company_favicon_url();
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
    <title>Reset password | <?= e($company) ?></title>
    <?php if ($favUrl): ?><link rel="icon" href="<?= e($favUrl) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700;800&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        <div class="ulog-mart">
            <svg class="ulog-mart-svg" viewBox="0 0 280 360" fill="none">
                <path class="ulog-mart-line" d="M40 78h200M40 78l20-28h160l20 28" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                <path class="ulog-mart-line" d="M52 78v18h176V78" stroke="currentColor" stroke-width="1.6"/>
                <path class="ulog-mart-stripe" d="M60 62h28M100 54h28M140 48h28M180 54h28" stroke="currentColor" stroke-width="6" stroke-linecap="round"/>

                <rect class="ulog-mart-fill" x="58" y="118" width="48" height="72" rx="8"/>
                <path class="ulog-mart-line" d="M70 118v-14a12 12 0 0124 0v14" stroke="currentColor" stroke-width="1.7"/>
                <rect class="ulog-mart-fill" x="116" y="108" width="48" height="82" rx="8"/>
                <path class="ulog-mart-line" d="M128 108v-16a12 12 0 0124 0v16" stroke="currentColor" stroke-width="1.7"/>
                <rect class="ulog-mart-fill" x="174" y="126" width="48" height="64" rx="8"/>
                <path class="ulog-mart-line" d="M186 126v-12a12 12 0 0124 0v12" stroke="currentColor" stroke-width="1.7"/>
                <path class="ulog-mart-line" d="M48 198h184" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>

                <path class="ulog-mart-fill" d="M86 248h108l12 72H74l12-72z"/>
                <path class="ulog-mart-line" d="M108 248v-22a32 32 0 0164 0v22" stroke="currentColor" stroke-width="1.8"/>
                <circle class="ulog-mart-dot is-core" cx="140" cy="278" r="10"/>
                <path class="ulog-mart-leaf" d="M152 268c14-2 24 8 22 20-12 2-22-8-22-20zM128 268c-14-2-24 8-22 20 12 2 22-8 22-20z"/>
            </svg>
            <ul class="ulog-mart-tags">
                <li>Wellness</li>
                <li>Organic</li>
                <li>Natural</li>
            </ul>
        </div>
        <p class="ulog-rail-copy">Nurture health.<br>Share products.<br>Grow together.</p>
    </aside>

    <main class="ulog-main">
        <p class="ulog-kicker">Wellness · Organic · Natural</p>
        <?php if ($logoUrl): ?>
        <img class="ulog-logo" src="<?= e($logoUrl) ?>" alt="<?= e($company) ?>">
        <?php endif; ?>
        <?php if ($invalid): ?>
            <h1 class="ulog-title">This reset link isn’t valid</h1>
            <p class="ulog-lead">It may have expired or already been used. Request a new link to continue.</p>
            <div class="up-alert up-alert-err">This password reset link is invalid or has expired.</div>
            <div class="ulog-form">
                <a href="forgot-password.php" class="ulog-submit">
                    <span>Request a new link</span>
                    <span class="ulog-submit-arrow" aria-hidden="true">→</span>
                </a>
            </div>
        <?php else: ?>
            <h1 class="ulog-title">Choose a new password</h1>
            <p class="ulog-lead">Hi <?= e($reset['full_name'] ?? 'Member') ?> — updating password for <strong>@<?= e($reset['username'] ?? '') ?></strong>. This link works once and expires in 1 hour.</p>
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
            <p>Remembered it? <a href="login.php">Sign in</a></p>
            <p class="ulog-copy">&copy; <?= date('Y') ?> <?= e($company) ?></p>
        </footer>
    </main>
</div>
<script src="assets/js/user.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/user.js') ?>"></script>
</body>
</html>
