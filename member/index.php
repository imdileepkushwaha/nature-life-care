<?php
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['member_id'])) {
    header('Location: ../admin/direct-member-login.php');
    exit;
}

session_enforce_idle('member', '../admin/direct-member-login.php');
if (!empty($_SESSION['admin_id'])) {
    session_touch('admin');
}

$stmt = $pdo->prepare('
    SELECT m.*, p.name AS package_name
    FROM members m
    LEFT JOIN packages p ON p.id = m.package_id
    WHERE m.id = ?
');
$stmt->execute([(int) $_SESSION['member_id']]);
$member = $stmt->fetch();

if (!$member) {
    unset($_SESSION['member_id'], $_SESSION['member_code'], $_SESSION['member_name'], $_SESSION['member_login_by_admin']);
    header('Location: ../admin/direct-member-login.php');
    exit;
}

$news = $pdo->query("SELECT title, content, published_at FROM news WHERE status='active' ORDER BY published_at DESC, id DESC LIMIT 5")->fetchAll();
$byAdmin = !empty($_SESSION['member_login_by_admin']);
$company = setting('company_name', 'Binary MLM');

$statusRaw = strtolower(trim((string) ($member['status'] ?? 'inactive')));
if ($statusRaw === 'blocked') {
    $statusLabel = 'Blocked';
    $statusCls = 'is-bad';
} elseif (empty($member['package_id'])) {
    $statusLabel = 'Inactive';
    $statusCls = 'is-wait';
} elseif ($statusRaw === 'active') {
    $statusLabel = 'Active';
    $statusCls = 'is-ok';
} else {
    $statusLabel = ucfirst($statusRaw ?: 'Inactive');
    $statusCls = 'is-muted';
}

$name = (string) ($member['full_name'] ?? 'Member');
$parts = preg_split('/\s+/', trim($name)) ?: [];
$initials = '';
foreach (array_slice($parts, 0, 2) as $p) {
    $initials .= mb_strtoupper(mb_substr($p, 0, 1));
}
if ($initials === '') {
    $initials = 'M';
}
$firstName = $parts[0] ?? $name;
$joinDate = !empty($member['join_date']) ? date('d M Y', strtotime($member['join_date'])) : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Dashboard | <?= e($company) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/member.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/member.css') ?>">
</head>
<body class="mp-body">
<div class="mp-shell">
    <header class="mp-top">
        <div class="mp-brand">
            <span class="mp-brand-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </span>
            <div>
                <strong><?= e($company) ?></strong>
                <small>Member Panel</small>
            </div>
        </div>
        <div class="mp-top-right">
            <div class="mp-user-chip">
                <span class="mp-ava"><?= e($initials) ?></span>
                <div>
                    <strong><?= e($name) ?></strong>
                    <small><?= e($member['member_id']) ?></small>
                </div>
            </div>
            <?php if ($byAdmin): ?>
                <a href="../admin/direct-member-login.php" class="mp-btn mp-btn-ghost">Back to Admin</a>
            <?php endif; ?>
            <a href="logout.php" class="mp-btn mp-btn-danger">Logout</a>
        </div>
    </header>

    <main class="mp-main">
        <?php if ($byAdmin): ?>
            <div class="mp-alert">
                <span class="mp-alert-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                </span>
                <div>
                    <strong>Admin preview</strong>
                    <p>You are viewing this dashboard via Direct Member Login.</p>
                </div>
            </div>
        <?php endif; ?>

        <section class="mp-hero">
            <div class="mp-hero-copy">
                <span class="mp-kicker">Welcome back</span>
                <h1><?= e($company) ?></h1>
                <p>Hi <?= e($firstName) ?> — here’s a live snapshot of your membership, wallet and team.</p>
            </div>
            <div class="mp-hero-meta">
                <span class="mp-pill <?= e($statusCls) ?>"><?= e($statusLabel) ?></span>
                <span class="mp-id-tag">@<?= e($member['member_id']) ?></span>
            </div>
            <div class="mp-hero-glow" aria-hidden="true"></div>
        </section>

        <div class="mp-stats">
            <article class="mp-stat g-blue">
                <span class="mp-stat-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg>
                </span>
                <div>
                    <span class="mp-stat-label">Left / Right</span>
                    <strong><?= (int) $member['left_count'] ?> / <?= (int) $member['right_count'] ?></strong>
                    <small>Binary count</small>
                </div>
            </article>
            <article class="mp-stat g-green">
                <span class="mp-stat-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg>
                </span>
                <div>
                    <span class="mp-stat-label">Wallet</span>
                    <strong class="is-sm"><?= currency((float) $member['wallet_balance']) ?></strong>
                    <small>Available balance</small>
                </div>
            </article>
            <article class="mp-stat g-orange">
                <span class="mp-stat-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg>
                </span>
                <div>
                    <span class="mp-stat-label">Total Earnings</span>
                    <strong class="is-sm"><?= currency((float) $member['total_earnings']) ?></strong>
                    <small>Lifetime income</small>
                </div>
            </article>
            <article class="mp-stat g-navy">
                <span class="mp-stat-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
                </span>
                <div>
                    <span class="mp-stat-label">Package</span>
                    <strong class="is-sm"><?= e($member['package_name'] ?? 'No Plan') ?></strong>
                    <small>Current plan</small>
                </div>
            </article>
        </div>

        <div class="mp-grid">
            <section class="mp-panel">
                <div class="mp-panel-head is-navy">
                    <div class="mp-panel-main">
                        <span class="mp-panel-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <div>
                            <span class="mp-kicker">Account</span>
                            <h2>Profile</h2>
                        </div>
                    </div>
                </div>
                <div class="mp-panel-body">
                    <div class="mp-profile-top">
                        <span class="mp-ava lg"><?= e($initials) ?></span>
                        <div>
                            <strong><?= e($name) ?></strong>
                            <small>@<?= e($member['username']) ?></small>
                        </div>
                        <span class="mp-pill <?= e($statusCls) ?>"><?= e($statusLabel) ?></span>
                    </div>
                    <dl class="mp-facts">
                        <div>
                            <dt>Member ID</dt>
                            <dd><?= e($member['member_id']) ?></dd>
                        </div>
                        <div>
                            <dt>Email</dt>
                            <dd><?= e($member['email'] ?: '—') ?></dd>
                        </div>
                        <div>
                            <dt>Phone</dt>
                            <dd><?= e($member['phone'] ?: '—') ?></dd>
                        </div>
                        <div>
                            <dt>Joined</dt>
                            <dd><?= e($joinDate) ?></dd>
                        </div>
                        <div>
                            <dt>Position</dt>
                            <dd><?= e($member['position'] ? ucfirst((string) $member['position']) : '—') ?></dd>
                        </div>
                        <div>
                            <dt>Package</dt>
                            <dd><?= e($member['package_name'] ?? 'No Plan') ?></dd>
                        </div>
                    </dl>
                </div>
            </section>

            <section class="mp-panel">
                <div class="mp-panel-head is-coral">
                    <div class="mp-panel-main">
                        <span class="mp-panel-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        </span>
                        <div>
                            <span class="mp-kicker">Updates</span>
                            <h2>Latest News</h2>
                        </div>
                    </div>
                </div>
                <div class="mp-panel-body mp-news">
                    <?php if (!$news): ?>
                        <div class="mp-empty">
                            <strong>No news yet</strong>
                            <p>Company updates will appear here when published.</p>
                        </div>
                    <?php else: foreach ($news as $n): ?>
                        <article class="mp-news-item">
                            <div class="mp-news-top">
                                <strong><?= e($n['title']) ?></strong>
                                <?php if (!empty($n['published_at'])): ?>
                                    <time><?= e(date('d M Y', strtotime($n['published_at']))) ?></time>
                                <?php endif; ?>
                            </div>
                            <p><?= nl2br(e($n['content'])) ?></p>
                        </article>
                    <?php endforeach; endif; ?>
                </div>
            </section>
        </div>
    </main>

    <footer class="mp-foot">
        <span>&copy; <?= date('Y') ?> <?= e($company) ?></span>
        <span class="mp-foot-pill">Secure session</span>
    </footer>
</div>
</body>
</html>
