<?php
$pageTitle = 'My Direct';
require_once __DIR__ . '/../includes/team.php';
require_once __DIR__ . '/includes/header.php';

$uid = (int) $user['id'];
$q = trim($_GET['q'] ?? '');
$rows = team_get_directs($pdo, $uid);

if ($q !== '') {
    $ql = mb_strtolower($q);
    $rows = array_values(array_filter($rows, static function ($m) use ($ql) {
        $hay = mb_strtolower(($m['member_id'] ?? '') . ' ' . ($m['username'] ?? '') . ' ' . ($m['full_name'] ?? '') . ' ' . ($m['phone'] ?? ''));
        return str_contains($hay, $ql);
    }));
}

$total = count($rows);
$active = count(array_filter($rows, static fn ($m) => member_is_active($m)));
$leftSum = array_sum(array_map(fn ($m) => (int) $m['left_count'], $rows));
$rightSum = array_sum(array_map(fn ($m) => (int) $m['right_count'], $rows));
?>
<div class="up-page-head">
    <div>
        <h1>My Direct</h1>
        <p>Members you personally sponsored (referral tree).</p>
    </div>
    <a href="my-downline.php" class="up-btn up-btn-outline">View Downline</a>
</div>

<div class="team-stats">
    <article class="team-stat g-purple">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg></span>
        <div>
            <span class="team-stat-label">Total Direct</span>
            <strong><?= $total ?></strong>
        </div>
    </article>
    <article class="team-stat g-green">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
        <div>
            <span class="team-stat-label">Active</span>
            <strong><?= $active ?></strong>
        </div>
    </article>
    <article class="team-stat g-orange">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg></span>
        <div>
            <span class="team-stat-label">Left Team Sum</span>
            <strong><?= $leftSum ?></strong>
        </div>
    </article>
    <article class="team-stat g-blue">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg></span>
        <div>
            <span class="team-stat-label">Right Team Sum</span>
            <strong><?= $rightSum ?></strong>
        </div>
    </article>
</div>

<section class="team-card">
    <div class="team-banner is-gold">
        <div class="team-banner-main">
            <span class="team-banner-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
            </span>
            <div>
                <span class="team-banner-kicker">Referral network</span>
                <h2>Direct Members</h2>
            </div>
        </div>
        <form method="get" class="team-search on-dark">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, ID, phone…">
            <button type="submit" class="up-btn up-btn-primary">Search</button>
            <?php if ($q !== ''): ?><a href="my-direct.php" class="up-btn team-btn-ghost">Reset</a><?php endif; ?>
        </form>
    </div>

    <?php if (!$rows): ?>
        <div class="team-empty-state">
            <span class="team-empty-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg></span>
            <strong>No direct members yet</strong>
            <p>Share your Left / Right referral links from the dashboard to grow your team.</p>
        </div>
    <?php else: ?>
        <div class="team-list">
            <?php foreach ($rows as $i => $m):
                $ini = user_initials((string) $m['full_name']);
                $photo = user_photo_url($m['photo'] ?? null);
                ?>
                <article class="team-row">
                    <div class="team-row-left">
                        <span class="team-idx"><?= $i + 1 ?></span>
                        <?php if ($photo): ?>
                            <div class="team-ava has-photo"><img src="<?= e($photo) ?>" alt=""></div>
                        <?php else: ?>
                            <div class="team-ava"><?= e($ini) ?></div>
                        <?php endif; ?>
                        <div class="team-row-copy">
                            <strong><?= e($m['full_name']) ?></strong>
                            <span><?= e($m['member_id']) ?> · @<?= e($m['username']) ?></span>
                            <?php if (!empty($m['phone'])): ?><span class="team-phone"><?= e($m['phone']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="team-row-meta">
                        <span class="team-chip"><?= e(ucfirst((string) ($m['position'] ?: '—'))) ?></span>
                        <span class="team-chip soft"><?= e($m['package_name'] ?? 'No package') ?></span>
                        <span class="team-chip soft">L/R <?= (int) $m['left_count'] ?>/<?= (int) $m['right_count'] ?></span>
                        <?= team_status_pill($m) ?>
                        <time><?= e(date('d M Y', strtotime($m['join_date']))) ?></time>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
