<?php
$pageTitle = 'Level Tree';
require_once __DIR__ . '/../includes/team.php';
require_once __DIR__ . '/includes/header.php';

$uid = (int) $user['id'];
$maxLevel = max(1, min(20, (int) ($_GET['max'] ?? 10)));

$downline = team_collect_sponsor_downline($pdo, $uid, $maxLevel);
$grouped = team_group_by_level($downline);
$deepest = $grouped ? max(array_keys($grouped)) : 0;
?>
<div class="up-page-head">
    <div>
        <h1>Level Tree</h1>
        <p>Your sponsor-team generations (level income network).</p>
    </div>
    <form method="get" class="team-search">
        <label class="team-max-label">Show up to
            <select name="max" onchange="this.form.submit()">
                <?php foreach ([5, 8, 10, 12, 15, 20] as $n): ?>
                    <option value="<?= $n ?>" <?= $maxLevel === $n ? 'selected' : '' ?>>Level <?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</div>

<div class="team-stats">
    <article class="team-stat g-purple">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg></span>
        <div>
            <span class="team-stat-label">Total Downline</span>
            <strong><?= count($downline) ?></strong>
        </div>
    </article>
    <article class="team-stat g-blue">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19h16M7 15V9M12 15V5M17 15v-3"/></svg></span>
        <div>
            <span class="team-stat-label">Levels Filled</span>
            <strong><?= count($grouped) ?></strong>
        </div>
    </article>
    <article class="team-stat g-green">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg></span>
        <div>
            <span class="team-stat-label">Level 1 (Direct)</span>
            <strong><?= isset($grouped[1]) ? count($grouped[1]) : 0 ?></strong>
        </div>
    </article>
    <article class="team-stat g-orange">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span>
        <div>
            <span class="team-stat-label">Deepest Shown</span>
            <strong><?= (int) $deepest ?></strong>
        </div>
    </article>
</div>

<?php if (!$grouped): ?>
<section class="team-card">
    <div class="team-empty-state">
        <strong>No level data yet</strong>
        <p>When members join under your sponsor link, generations appear here.</p>
    </div>
</section>
<?php else: foreach ($grouped as $level => $members): ?>
<section class="team-card level-card">
    <div class="team-banner is-level">
        <div class="team-banner-main">
            <span class="team-level big">L<?= (int) $level ?></span>
            <div>
                <span class="team-banner-kicker">Generation</span>
                <h2>Level <?= (int) $level ?></h2>
                <p><?= count($members) ?> member<?= count($members) === 1 ? '' : 's' ?></p>
            </div>
        </div>
    </div>
    <div class="level-grid">
        <?php foreach ($members as $m):
            $ini = user_initials((string) $m['full_name']);
            $photo = user_photo_url($m['photo'] ?? null);
            ?>
            <article class="level-tile">
                <div class="level-tile-top">
                    <?php if ($photo): ?>
                        <div class="team-ava sm has-photo"><img src="<?= e($photo) ?>" alt=""></div>
                    <?php else: ?>
                        <div class="team-ava sm"><?= e($ini) ?></div>
                    <?php endif; ?>
                    <div class="level-tile-name">
                        <strong><?= e($m['full_name']) ?></strong>
                        <?= team_status_pill($m) ?>
                    </div>
                </div>
                <div class="level-tile-meta">
                    <span><?= e($m['member_id']) ?></span>
                    <span>@<?= e($m['username']) ?></span>
                </div>
                <div class="level-tile-foot">
                    <span class="leg-chip">Sponsor · <?= e($m['sponsor_mid'] ?? '—') ?></span>
                    <span><?= e($m['package_name'] ?? 'No package') ?></span>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
