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
$error = '';
$done = false;
$devLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = strtoupper(trim($_POST['login'] ?? ''));

    if ($login === '') {
        $error = 'Enter your Member ID.';
    } elseif (!preg_match('/^[A-Z]{2,10}\d{3,8}$/', $login)) {
        $error = 'Enter a valid Member ID.';
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
    <title>Forgot password | <?= e($company) ?></title>
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
        <h1 class="ulog-title">Recover your password</h1>
        <p class="ulog-lead">Enter your Member ID. We will send a reset link if the account exists.</p>

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
                    <span>Continue to sign in</span>
                    <span class="ulog-submit-arrow" aria-hidden="true">→</span>
                </a>
            </div>
        <?php else: ?>
            <form method="post" class="ulog-form" autocomplete="off">
                <div class="ulog-field">
                    <label for="login">Member ID</label>
                    <input type="text" id="login" name="login" value="<?= e(strtoupper((string) ($_POST['login'] ?? ''))) ?>" placeholder="Member ID" required autofocus style="text-transform:uppercase">
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
