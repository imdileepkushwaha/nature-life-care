<?php
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$info = $_SESSION['reg_success'] ?? null;
if (!$info || empty($info['member_id'])) {
    header('Location: register.php');
    exit;
}

// One-time view — clear after read so refresh still works this session, but new visit without reg goes to register
$company = setting('company_name', 'Binary MLM');
$fullName = trim(($info['name_title'] ?? '') . ' ' . ($info['full_name'] ?? ''));
$memberId = (string) ($info['member_id'] ?? '');
$password = (string) ($info['password'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful | <?= e($company) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/user.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/user.css') ?>">
</head>
<body class="urs-body">
<div class="urs">
    <div class="urs-card">
        <div class="urs-burst" aria-hidden="true">
            <span></span><span></span><span></span>
        </div>

        <div class="urs-check" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
        </div>

        <p class="urs-kicker"><?= e($company) ?></p>
        <h1>Welcome aboard!</h1>
        <p class="urs-lead">Your account has been created successfully. Save these details and sign in to continue.</p>

        <div class="urs-hello">
            <span class="urs-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($info['full_name'] ?? 'U', 0, 1))) ?></span>
            <div>
                <strong><?= e($fullName) ?></strong>
                <small>You're all set to join the network</small>
            </div>
        </div>

        <dl class="urs-details">
            <div>
                <dt>Member ID</dt>
                <dd>
                    <span id="ursMemberId"><?= e($memberId) ?></span>
                    <button type="button" class="urs-copy" data-copy-text="<?= e($memberId) ?>" data-label="Copy member ID" title="Copy member ID" aria-label="Copy member ID">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    </button>
                </dd>
            </div>
            <div>
                <dt>Password</dt>
                <dd>
                    <?php if ($password !== ''): ?>
                    <span id="ursPassword"><?= e($password) ?></span>
                    <button type="button" class="urs-copy" data-copy-text="<?= e($password) ?>" data-label="Copy password" title="Copy password" aria-label="Copy password">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    </button>
                    <?php else: ?>
                    <span class="urs-muted">Not available — register again or use Forgot Password on login.</span>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>

        <div class="urs-note">
            Save your <strong>Member ID</strong> and <strong>password</strong> — you'll need them to sign in.
        </div>

        <div class="urs-actions">
            <a href="login.php" class="urs-login">
                <span>Go to Login</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
            </a>
        </div>

        <p class="urs-foot">&copy; <?= date('Y') ?> <?= e($company) ?></p>
    </div>
</div>
<script src="assets/js/user.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/user.js') ?>"></script>
</body>
</html>
