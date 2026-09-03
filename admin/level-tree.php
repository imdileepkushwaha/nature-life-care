<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/team.php';
$pageTitle = 'Level Tree';

$search = trim($_GET['q'] ?? '');
$maxLevel = max(1, min(20, (int) ($_GET['max'] ?? 10)));

$root = null;
if ($search !== '') {
    $stmt = $pdo->prepare('SELECT id, member_id, full_name, username, status FROM members WHERE member_id = ? OR username = ? LIMIT 1');
    $stmt->execute([$search, $search]);
    $root = $stmt->fetch() ?: null;
} else {
    $root = $pdo->query('SELECT id, member_id, full_name, username, status FROM members ORDER BY id ASC LIMIT 1')->fetch() ?: null;
    if ($root) {
        $search = (string) $root['member_id'];
    }
}

$downline = [];
$grouped = [];
if ($root) {
    $downline = team_collect_sponsor_downline($pdo, (int) $root['id'], $maxLevel);
    $grouped = team_group_by_level($downline);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel members-panel">
    <div class="panel-header members-toolbar">
        <div>
            <h2>Level Tree</h2>
            <p class="members-sub">Sponsor generations under a member</p>
        </div>
        <?php if ($root): ?>
        <a href="downline.php?q=<?= urlencode((string) $root['member_id']) ?>" class="btn btn-outline btn-sm">Downline</a>
        <?php endif; ?>
    </div>
    <div class="panel-body members-filters">
        <form class="members-filter-form" method="get">
            <div class="form-group">
                <label>Member ID / Username</label>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="<?= e(member_id_prefix() . str_pad('1', member_id_pad(), '0', STR_PAD_LEFT)) ?>">
            </div>
            <div class="form-group">
                <label>Max level</label>
                <select name="max">
                    <?php foreach ([5, 8, 10, 12, 15, 20] as $n): ?>
                    <option value="<?= $n ?>" <?= $maxLevel === $n ? 'selected' : '' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Show Levels</button>
        </form>
    </div>

    <?php if ($root): ?>
    <div class="members-stats" style="padding:0 1.25rem 1rem">
        <div class="m-stat is-on">
            <span class="m-stat-ico blue"><?= icon_svg('view') ?></span>
            <div>
                <strong><?= e($root['member_id']) ?></strong>
                <span><?= e($root['full_name']) ?></span>
            </div>
        </div>
        <div class="m-stat">
            <span class="m-stat-ico green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span>
            <div>
                <strong><?= count($downline) ?></strong>
                <span>Total</span>
            </div>
        </div>
        <div class="m-stat">
            <span class="m-stat-ico purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19h16M7 15V9M12 15V5M17 15v-3"/></svg></span>
            <div>
                <strong><?= count($grouped) ?></strong>
                <span>Levels</span>
            </div>
        </div>
        <div class="m-stat">
            <span class="m-stat-ico orange"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg></span>
            <div>
                <strong><?= isset($grouped[1]) ? count($grouped[1]) : 0 ?></strong>
                <span>Level 1</span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="data members-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Member</th>
                    <th>Level</th>
                    <th>Sponsor</th>
                    <th>Package</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$root): ?>
                <tr><td colspan="8"><div class="empty-state"><strong>No member found</strong><span>Search by Member ID or username.</span></div></td></tr>
            <?php elseif (!$downline): ?>
                <tr><td colspan="8"><div class="empty-state"><strong>No sponsor downline</strong><span><?= e($root['full_name']) ?> has no referred generations yet.</span></div></td></tr>
            <?php else: foreach ($downline as $i => $m): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td>
                        <div class="member-cell">
                            <strong><a href="member-view.php?id=<?= (int) $m['id'] ?>"><?= e($m['full_name']) ?></a></strong>
                            <span><?= e($m['member_id']) ?> · <?= e($m['username']) ?></span>
                        </div>
                    </td>
                    <td><span class="level-badge">L<?= (int) $m['level'] ?></span></td>
                    <td><?= !empty($m['sponsor_mid']) ? e($m['sponsor_mid']) : '—' ?></td>
                    <td><?= e($m['package_name'] ?? '—') ?></td>
                    <td><?= status_badge($m['status']) ?></td>
                    <td><?= e(date('d M Y', strtotime($m['join_date']))) ?></td>
                    <td>
                        <a href="level-tree.php?q=<?= urlencode($m['member_id']) ?>" class="btn btn-outline btn-sm">Levels</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
