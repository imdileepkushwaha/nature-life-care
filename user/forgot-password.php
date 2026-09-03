<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/password-reset.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

ensure_password_resets_table($pdo);

$company = setting('company_name', 'Binary MLM');
$error = '';
$done = false;
$devLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');

    if ($login === '') {
        $error = 'Enter your username, email, or Member ID.';
    } else {
        $member = pw_reset_find_member($pdo, $login);

        if ($member) {
            $token = pw_reset_create_token($pdo, (int) $member['id']);
            $url = pw_reset_url($token);
            $mailed = pw_reset_send_mail($member, $url);

            if (!$mailed || pw_reset_is_local()) {
                $_SESSION['pw_reset_dev_link'] = $url;
            }

            try {
                log_activity('password_reset_request', 'Reset requested for member #' . (int) $member['id']);
            } catch (Throwable $e) {
                // ignore
            }
        }

        $done = true;
        $devLink = (string) ($_SESSION['pw_reset_dev_link'] ?? '');
        unset($_SESSION['pw_reset_dev_link']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | <?= e($company) ?></title>
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
        <p class="ulog-rail-copy">Secure link.<br>One hour.<br>One use.</p>
    </aside>

    <main class="ulog-main">
        <p class="ulog-kicker">Account recovery</p>
        <p class="ulog-brand"><?= e($company) ?></p>
        <h1 class="ulog-title">Forgot your password?</h1>
        <p class="ulog-lead">Enter your member ID, username, or email — we’ll send a reset link if the account exists.</p>

        <?php if ($error): ?>
            <div class="up-alert up-alert-err"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($done): ?>
            <div class="ulog-form">
                <div class="up-alert up-alert-ok" style="margin:0">
                    If an account matches, a password reset link has been sent to the registered email. The link expires in 1 hour.
                </div>
                <?php if ($devLink !== ''): ?>
                    <div class="upw-dev">
                        <strong>Local / mail unavailable</strong>
                        <p>Use this one-time link to continue:</p>
                        <a href="<?= e($devLink) ?>"><?= e($devLink) ?></a>
                    </div>
                <?php endif; ?>
                <a href="login.php" class="ulog-submit">
                    <span>Back to sign in</span>
                    <span class="ulog-submit-arrow" aria-hidden="true">→</span>
                </a>
            </div>
        <?php else: ?>
            <form method="post" class="ulog-form" autocomplete="off">
                <div class="ulog-field">
                    <label for="login">Username / Email / Member ID</label>
                    <input type="text" id="login" name="login" value="<?= e($_POST['login'] ?? '') ?>" placeholder="member001 or you@email.com" required autofocus>
                </div>
                <button type="submit" class="ulog-submit">
                    <span>Send reset link</span>
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
