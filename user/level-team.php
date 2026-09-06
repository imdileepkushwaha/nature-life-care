<?php
/**
 * Level Team — sponsor-line org chart (L1 directs, then nested generations).
 */
$pageTitle = 'Level Team';
require_once __DIR__ . '/../includes/team.php';
require_once __DIR__ . '/includes/header.php';

$uid = (int) $user['id'];
$maxLevel = max(1, min(20, (int) ($_GET['max'] ?? 10)));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));

$downline = team_collect_sponsor_downline($pdo, $uid, $maxLevel);
$groupedAll = team_group_by_level($downline);
$levelCounts = [];
foreach ($groupedAll as $lv => $members) {
    $levelCounts[(int) $lv] = count($members);
}
$totalAll = count($downline);
$activeAll = count(array_filter($downline, static fn ($m) => member_is_active($m)));
$levelsFilled = count($groupedAll);
$deepest = $groupedAll ? max(array_keys($groupedAll)) : 0;

$rows = $downline;
$needsAncestorFilter = ($statusFilter !== 'all' || $q !== '');
if ($needsAncestorFilter) {
    $ql = mb_strtolower($q);
    $rows = team_filter_with_ancestors($downline, static function ($m) use ($statusFilter, $q, $ql) {
        $active = member_is_active($m);
        if ($statusFilter === 'active' && !$active) {
            return false;
        }
        if ($statusFilter === 'inactive' && $active) {
            return false;
        }
        if ($q === '') {
            return true;
        }
        $hay = mb_strtolower(
            ($m['member_id'] ?? '') . ' '
            . ($m['username'] ?? '') . ' '
            . ($m['full_name'] ?? '') . ' '
            . ($m['phone'] ?? '') . ' '
            . ($m['sponsor_mid'] ?? '')
        );
        return str_contains($hay, $ql);
    });
}

$childrenMap = team_sponsor_children_map($rows);
$rootKids = $childrenMap[$uid] ?? [];
$viewTotal = count($rows);

$queryKeep = static function (array $extra = []) use ($maxLevel, $statusFilter, $q): string {
    $base = [
        'max' => $maxLevel,
        'status' => $statusFilter,
        'q' => $q,
    ];
    foreach ($extra as $k => $v) {
        $base[$k] = $v;
    }
    if (($base['status'] ?? 'all') === 'all') {
        unset($base['status']);
    }
    if (($base['q'] ?? '') === '') {
        unset($base['q']);
    }
    if ((int) ($base['max'] ?? 10) === 10) {
        unset($base['max']);
    }
    return $base ? ('?' . http_build_query($base)) : '';
};

$selfActive = member_is_active($user);
$selfAmt = 0.0;
try {
    if (!empty($user['package_id'])) {
        $st = $pdo->prepare('SELECT amount FROM packages WHERE id = ? LIMIT 1');
        $st->execute([(int) $user['package_id']]);
        $selfAmt = round((float) ($st->fetchColumn() ?: 0), 2);
    }
} catch (Throwable $e) {
}
?>
<div class="up-page-head">
    <div>
        <h1>Level Team</h1>
        <p>Sponsor-line tree — same generation layout as Level Tree. L1 under you, then their team nested below.</p>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center">
        <form method="get" class="team-search">
            <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
            <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
            <label class="team-max-label">Depth
                <select name="max" onchange="this.form.submit()">
                    <?php foreach ([5, 8, 10, 12, 15, 20] as $n): ?>
                        <option value="<?= $n ?>" <?= $maxLevel === $n ? 'selected' : '' ?>>L<?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <?php if ($featLevel): ?>
        <a href="level-tree.php" class="up-btn up-btn-outline">Level Tree</a>
        <?php endif; ?>
    </div>
</div>

<div class="team-stats">
    <article class="team-stat g-purple">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg></span>
        <div>
            <span class="team-stat-label">Total Level Team</span>
            <strong><?= (int) $totalAll ?></strong>
        </div>
    </article>
    <article class="team-stat g-green">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
        <div>
            <span class="team-stat-label">Active</span>
            <strong><?= (int) $activeAll ?></strong>
        </div>
    </article>
    <article class="team-stat g-orange">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span>
        <div>
            <span class="team-stat-label">Level 1</span>
            <strong><?= (int) ($levelCounts[1] ?? 0) ?></strong>
        </div>
    </article>
    <article class="team-stat g-blue">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19h16M7 15V9M12 15V5M17 15v-3"/></svg></span>
        <div>
            <span class="team-stat-label">Levels · Deepest</span>
            <strong><?= (int) $levelsFilled ?> · <?= (int) $deepest ?></strong>
        </div>
    </article>
</div>

<section class="team-card lt-org-panel">
    <div class="team-banner is-gold">
        <div class="team-banner-main">
            <span class="team-banner-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg>
            </span>
            <div>
                <span class="team-banner-kicker">Sponsor network</span>
                <h2>Level Tree View</h2>
                <p style="margin:0.25rem 0 0;opacity:.85;font-size:0.85rem">
                    Green = Active · Red = Inactive · Amount = package price
                    <?= $needsAncestorFilter ? ' · Filtered view (' . (int) $viewTotal . ')' : '' ?>
                </p>
            </div>
        </div>
        <form method="get" class="team-search on-dark">
            <input type="hidden" name="max" value="<?= (int) $maxLevel ?>">
            <?php if ($statusFilter !== 'all'): ?>
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <?php endif; ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, ID, phone…">
            <button type="submit" class="up-btn up-btn-primary">Search</button>
            <?php if ($q !== '' || $statusFilter !== 'all'): ?>
                <a href="level-team.php<?= $queryKeep(['q' => '', 'status' => 'all']) ?>" class="up-btn team-btn-ghost">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="team-leg-tabs" style="flex-wrap:wrap">
        <a class="team-leg-tab<?= $statusFilter === 'all' ? ' is-on' : '' ?>" href="level-team.php<?= $queryKeep(['status' => 'all']) ?>">All</a>
        <a class="team-leg-tab<?= $statusFilter === 'active' ? ' is-on' : '' ?>" href="level-team.php<?= $queryKeep(['status' => 'active']) ?>">Active</a>
        <a class="team-leg-tab<?= $statusFilter === 'inactive' ? ' is-on' : '' ?>" href="level-team.php<?= $queryKeep(['status' => 'inactive']) ?>">Inactive</a>
    </div>

    <?php if (!$rootKids): ?>
        <div class="team-empty-state">
            <strong><?= $totalAll === 0 ? 'No level team yet' : 'No members match filters' ?></strong>
            <p><?= $totalAll === 0
                ? 'When someone joins with your sponsor ID, they appear under you in this tree.'
                : 'Try clearing search or status filter.' ?></p>
        </div>
    <?php else: ?>
        <div class="lt-org-board">
            <div class="lt-org-scroll">
                <div class="lt-org-tree" id="ltOrgTree" data-lt-org>
                    <ul class="lt-org-root">
                        <li class="is-collapsed has-branch">
                            <div class="lt-org-card is-you<?= $selfActive ? ' is-active' : ' is-inactive' ?> has-kids is-toggle"
                                 role="button" tabindex="0" aria-expanded="false" data-lt-toggle>
                                <span class="lt-org-ico" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a4.5 4.5 0 100-9 4.5 4.5 0 000 9zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5z"/></svg>
                                </span>
                                <strong class="lt-org-name"><?= e($user['full_name'] ?? 'You') ?></strong>
                                <span class="lt-org-status"><?= $selfActive ? 'Active' : 'Inactive' ?></span>
                                <span class="lt-org-amt"><?= currency($selfAmt) ?></span>
                                <span class="lt-org-code"><?= e((string) ($user['member_id'] ?? '')) ?> · You</span>
                                <span class="lt-org-expand"><?= count($rootKids) ?> ↓</span>
                            </div>
                            <?php team_render_level_org($childrenMap, $uid, 1, $maxLevel); ?>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <p class="lt-org-tip">Click a card to expand / collapse that member’s team. Directs are hidden until you open a node.</p>
        <script>
        (function () {
            var root = document.getElementById('ltOrgTree');
            if (!root) return;
            function toggle(card) {
                var li = card.closest('li');
                if (!li || !li.classList.contains('has-branch')) return;
                var open = li.classList.toggle('is-collapsed') === false;
                li.classList.toggle('is-open', open);
                card.setAttribute('aria-expanded', open ? 'true' : 'false');
                var badge = card.querySelector('.lt-org-expand');
                if (badge) {
                    var n = (badge.textContent || '').replace(/[^\d]/g, '') || '0';
                    badge.textContent = open ? (n + ' ↑') : (n + ' ↓');
                }
            }
            root.addEventListener('click', function (e) {
                var card = e.target.closest('[data-lt-toggle]');
                if (!card || !root.contains(card)) return;
                e.preventDefault();
                toggle(card);
            });
            root.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                var card = e.target.closest('[data-lt-toggle]');
                if (!card || !root.contains(card)) return;
                e.preventDefault();
                toggle(card);
            });
        })();
        </script>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
