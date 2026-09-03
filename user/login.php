<?php
require_once __DIR__ . '/includes/auth.php';

unset($_SESSION['reg_success']);

$maintenanceOn = is_maintenance_mode();
$licenseBlocked = !client_license_ok();
$wasSignedOut = false;

if (($maintenanceOn || $licenseBlocked) && !empty($_SESSION['user_id'])) {
    user_logout_session();
    $wasSignedOut = true;
    if (empty($_SESSION['flash'])) {
        flash('error', $licenseBlocked
            ? (client_license_message() ?: 'Access is temporarily unavailable. Please contact support.')
            : 'Portal is under maintenance. You have been signed out. Please try again later.');
    }
}

if (!$maintenanceOn && !$licenseBlocked && !empty($_SESSION['user_id'])) {
    session_enforce_idle('user', 'login.php');
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($licenseBlocked || !client_license_ok()) {
        $error = client_license_message() ?: 'Access is temporarily unavailable. Please contact support.';
        $licenseBlocked = true;
    } elseif ($maintenanceOn || is_maintenance_mode()) {
        clear_setting_cache('maintenance_mode');
        $error = 'Portal is under maintenance. Login is temporarily disabled.';
        $maintenanceOn = true;
    } else {
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($login === '' || $password === '') {
            $error = 'Username / email and password are required.';
        } else {
            $stmt = $pdo->prepare('
                SELECT * FROM members
                WHERE (username = ? OR email = ? OR member_id = ?)
                LIMIT 1
            ');
            $stmt->execute([$login, $login, $login]);
            $member = $stmt->fetch();

            clear_setting_cache('maintenance_mode');
            if (!client_license_ok()) {
                $error = client_license_message() ?: 'Access is temporarily unavailable. Please contact support.';
                $licenseBlocked = true;
            } elseif (is_maintenance_mode()) {
                $error = 'Portal is under maintenance. Login is temporarily disabled.';
                $maintenanceOn = true;
            } elseif ($member && password_verify($password, $member['password'])) {
                if (($member['status'] ?? '') === 'blocked') {
                    $error = 'Your account has been blocked. Contact support.';
                } else {
                    $_SESSION['user_id'] = (int) $member['id'];
                    $_SESSION['user_name'] = $member['full_name'];
                    $_SESSION['user_code'] = $member['member_id'];
                    session_touch('user');
                    header('Location: index.php');
                    exit;
                }
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}

$company = setting('company_name', 'Binary MLM');
$logoUrl = company_logo_url();
$favUrl = company_favicon_url();
$flash = get_flash();
$signedOutMsg = '';
$portalLocked = $maintenanceOn || $licenseBlocked;
if ($flash && $flash['type'] === 'error' && (
    stripos((string) $flash['message'], 'maintenance') !== false
    || stripos((string) $flash['message'], 'license') !== false
    || stripos((string) $flash['message'], 'suspended') !== false
    || stripos((string) $flash['message'], 'expired') !== false
)) {
    $signedOutMsg = (string) $flash['message'];
    $wasSignedOut = true;
    $flash = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in | <?= e($company) ?></title>
    <?php if ($favUrl): ?><link rel="icon" href="<?= e($favUrl) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=Unbounded:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/user.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/user.css') ?>">
</head>
<body class="ulog-body<?= $portalLocked ? ' is-maintenance' : '' ?>">
<div class="ulog"<?= $portalLocked ? ' aria-hidden="true"' : '' ?>>
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
        <p class="ulog-rail-copy">Build left.<br>Grow right.<br>Earn both.</p>
    </aside>

    <main class="ulog-main">
        <p class="ulog-kicker">Member access</p>
        <?php if ($logoUrl): ?>
        <img class="ulog-logo" src="<?= e($logoUrl) ?>" alt="<?= e($company) ?>">
        <?php endif; ?>
        <p class="ulog-brand"><?= e($company) ?></p>
        <h1 class="ulog-title">Sign in to your network desk</h1>
        <p class="ulog-lead">Use your member ID, username, or email to continue.</p>

        <?php if ($flash):
            $ftype = $flash['type'] === 'success' ? 'ok' : ($flash['type'] === 'error' ? 'err' : 'info');
        ?>
            <div class="up-alert up-alert-<?= e($ftype) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <?php if ($error && !$portalLocked): ?>
            <div class="up-alert up-alert-err"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" class="ulog-form" autocomplete="off"<?= $portalLocked ? ' inert' : '' ?>>
            <div class="ulog-field">
                <label for="login">Username / Email / Member ID</label>
                <input type="text" id="login" name="login" value="<?= e($_POST['login'] ?? '') ?>" placeholder="member001 or you@email.com" required<?= $portalLocked ? ' disabled' : ' autofocus' ?>>
            </div>

            <div class="ulog-field">
                <div class="ulog-label-row">
                    <label for="password">Password</label>
                    <a href="forgot-password.php" class="ulog-forgot">Forgot?</a>
                </div>
                <div class="up-password-wrap">
                    <input type="password" id="password" name="password" placeholder="Your password" required<?= $portalLocked ? ' disabled' : '' ?>>
                    <button type="button" class="up-eye" data-password-toggle aria-label="Show password"<?= $portalLocked ? ' disabled' : '' ?>>
                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="ulog-submit"<?= $portalLocked ? ' disabled' : '' ?>>
                <span>Continue to dashboard</span>
                <span class="ulog-submit-arrow" aria-hidden="true">→</span>
            </button>
        </form>

        <footer class="ulog-foot">
            <p>New here? <a href="register.php">Create an account</a></p>
            <p class="ulog-copy">&copy; <?= date('Y') ?> <?= e($company) ?></p>
        </footer>
    </main>
</div>

<?php if ($portalLocked): ?>
<div class="maint-overlay" role="dialog" aria-modal="true" aria-labelledby="maintTitle" data-locked="1">
    <div class="maint-backdrop" aria-hidden="true"></div>
    <div class="maint-modal">
        <div class="maint-glow" aria-hidden="true"></div>
        <div class="maint-icon-wrap" aria-hidden="true">
            <span class="maint-ring"></span>
            <span class="maint-mark">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/>
                </svg>
            </span>
        </div>
        <header class="maint-head">
            <p class="maint-kicker">Temporarily offline</p>
            <h2 id="maintTitle"><?= $licenseBlocked ? 'Access unavailable' : 'Under maintenance' ?></h2>
            <p class="maint-sub"><?= $licenseBlocked ? 'Please contact support for help' : 'Member portal is temporarily unavailable' ?></p>
        </header>
        <div class="maint-box is-warn">
            <span class="maint-box-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="16" x2="12.01" y2="17"/></svg>
            </span>
            <div>
                <strong><?= $licenseBlocked ? 'Access blocked' : 'Maintenance mode is ON' ?></strong>
                <span><?= e($licenseBlocked ? (client_license_message() ?: 'Please contact support.') : 'Login is disabled right now. Please try again later.') ?></span>
            </div>
        </div>
        <?php if ($wasSignedOut || $signedOutMsg !== ''): ?>
        <div class="maint-box is-err">
            <span class="maint-box-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </span>
            <div>
                <strong>Signed out</strong>
                <span><?= e($signedOutMsg !== '' ? $signedOutMsg : ($licenseBlocked ? (client_license_message() ?: 'Access blocked.') : 'Portal is under maintenance. You have been signed out. Please try again later.')) ?></span>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="maint-box is-err">
            <span class="maint-box-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </span>
            <div>
                <strong>Login blocked</strong>
                <span><?= e($error) ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); }
    }, true);
    var overlay = document.querySelector('.maint-overlay');
    if (!overlay) return;
    document.querySelectorAll('.ulog-form').forEach(function (form) {
        form.addEventListener('submit', function (e) { e.preventDefault(); e.stopPropagation(); }, true);
    });
    var observer = new MutationObserver(function () {
        if (!document.querySelector('.maint-overlay[data-locked="1"]')) {
            document.body.classList.add('is-maintenance');
            if (!document.querySelector('.maint-overlay')) document.body.appendChild(overlay);
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
})();
</script>
<?php endif; ?>

<script src="assets/js/user.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/user.js') ?>"></script>
</body>
</html>
