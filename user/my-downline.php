<?php
$pageTitle = 'My Downline';
require_once __DIR__ . '/../includes/team.php';
require_once __DIR__ . '/includes/header.php';

$uid = (int) $user['id'];
$useBinary = plan_uses_binary();
$useMatrix = plan_uses_matrix();
$leg = $_GET['leg'] ?? 'all';
if (!in_array($leg, ['all', 'left', 'right'], true)) {
    $leg = 'all';
}
$q = trim($_GET['q'] ?? '');

$leftCount = 0;
$rightCount = 0;
$modeLabel = 'Sponsor team';
$treeHref = 'level-tree.php';

if ($useBinary) {
    $modeLabel = 'Binary placement';
    $treeHref = 'my-treeview.php';
    $leftList = team_collect_downline($pdo, $uid, 'left');
    $rightList = team_collect_downline($pdo, $uid, 'right');
    $leftCount = count($leftList);
    $rightCount = count($rightList);
    if ($leg === 'left') {
        $rows = $leftList;
    } elseif ($leg === 'right') {
        $rows = $rightList;
    } else {
        $rows = array_merge($leftList, $rightList);
    }
} elseif ($useMatrix) {
    $modeLabel = 'Matrix placement';
    $treeHref = 'matrix-tree.php';
    $pack = team_collect_plan_downline($pdo, $uid, 'all');
    $rows = $pack['rows'];
} else {
    $modeLabel = 'Sponsor generations';
    $treeHref = 'level-tree.php';
    $pack = team_collect_plan_downline($pdo, $uid, 'all');
    $rows = $pack['rows'];
}

if ($q !== '') {
    $ql = mb_strtolower($q);
    $rows = array_values(array_filter($rows, static function ($m) use ($ql) {
        $hay = mb_strtolower(($m['member_id'] ?? '') . ' ' . ($m['username'] ?? '') . ' ' . ($m['full_name'] ?? ''));
        return str_contains($hay, $ql);
    }));
}
?>
<div class="up-page-head">
    <div>
        <h1>My Downline</h1>
        <p><?= e($modeLabel) ?> under your account.</p>
    </div>
    <a href="<?= e($treeHref) ?>" class="up-btn up-btn-primary">Open Tree</a>
</div>

<div class="team-stats">
    <?php if ($useBinary): ?>
    <article class="team-stat g-green">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg></span>
        <div>
            <span class="team-stat-label">Left Downline</span>
            <strong><?= $leftCount ?></strong>
        </div>
    </article>
    <article class="team-stat g-orange">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg></span>
        <div>
            <span class="team-stat-label">Right Downline</span>
            <strong><?= $rightCount ?></strong>
        </div>
    </article>
    <article class="team-stat g-blue">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg></span>
        <div>
            <span class="team-stat-label">Cached L / R</span>
            <strong><?= (int) $user['left_count'] ?> / <?= (int) $user['right_count'] ?></strong>
        </div>
    </article>
    <?php else: ?>
    <article class="team-stat g-blue">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg></span>
        <div>
            <span class="team-stat-label">Direct referrals</span>
            <strong><?= team_direct_count($pdo, $uid) ?></strong>
        </div>
    </article>
    <?php endif; ?>
    <article class="team-stat g-purple">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>
        <div>
            <span class="team-stat-label">Showing</span>
            <strong><?= count($rows) ?></strong>
        </div>
    </article>
</div>

<section class="team-card">
    <div class="team-banner is-gold">
        <div class="team-banner-main">
            <span class="team-banner-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg>
            </span>
            <div>
                <span class="team-banner-kicker"><?= e($modeLabel) ?></span>
                <h2>Downline List</h2>
            </div>
        </div>
        <form method="get" class="team-search on-dark">
            <?php if ($useBinary): ?>
            <select name="leg">
                <option value="all" <?= $leg === 'all' ? 'selected' : '' ?>>All Legs</option>
                <option value="left" <?= $leg === 'left' ? 'selected' : '' ?>>Left</option>
                <option value="right" <?= $leg === 'right' ? 'selected' : '' ?>>Right</option>
            </select>
            <?php endif; ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search…">
            <button type="submit" class="up-btn up-btn-primary">Apply</button>
        </form>
    </div>

    <?php if ($useBinary): ?>
    <div class="team-leg-tabs">
        <a href="?leg=all" class="<?= $leg === 'all' ? 'is-on' : '' ?>">All</a>
        <a href="?leg=left" class="<?= $leg === 'left' ? 'is-on' : '' ?>">Left (<?= $leftCount ?>)</a>
        <a href="?leg=right" class="<?= $leg === 'right' ? 'is-on' : '' ?>">Right (<?= $rightCount ?>)</a>
    </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <div class="team-empty-state">
            <strong>No downline yet</strong>
            <p><?= $useBinary ? 'Place members under your left or right to build the binary tree.' : 'Invite members with your sponsor link to grow your team.' ?></p>
        </div>
    <?php else: ?>
        <div class="team-list">
            <?php foreach ($rows as $i => $m):
                $ini = user_initials((string) $m['full_name']);
                $photo = user_photo_url($m['photo'] ?? null);
                $legKey = (string) ($m['leg'] ?? '');
                $legCls = $legKey === 'left' ? 'leg-l' : ($legKey === 'right' ? 'leg-r' : '');
                $legLabel = $useBinary ? ucfirst($legKey) : ('L' . (int) ($m['level'] ?? 0));
                ?>
                <article class="team-row <?= e($legCls) ?>">
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
                        </div>
                    </div>
                    <div class="team-row-meta">
                        <span class="team-level">L<?= (int) $m['level'] ?></span>
                        <span class="team-chip <?= e($legCls) ?>"><?= e($legLabel) ?></span>
                        <?php if (!empty($m['position'])): ?>
                        <span class="team-chip soft"><?= e(ucfirst((string) $m['position'])) ?></span>
                        <?php endif; ?>
                        <span class="team-chip soft"><?= e($m['sponsor_mid'] ?? '—') ?></span>
                        <span class="team-chip soft"><?= e($m['package_name'] ?? '—') ?></span>
                        <?= team_status_pill($m) ?>
                        <time><?= e(date('d M Y', strtotime($m['join_date']))) ?></time>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
