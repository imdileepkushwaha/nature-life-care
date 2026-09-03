<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/team.php';
$pageTitle = 'Downline';

$useBinary = plan_uses_binary();
$useMatrix = plan_uses_matrix();
$search = trim($_GET['q'] ?? '');
$leg = $_GET['leg'] ?? 'all';
if (!in_array($leg, ['all', 'left', 'right'], true)) {
    $leg = 'all';
}

$root = null;
if ($search !== '') {
    $stmt = $pdo->prepare('SELECT id, member_id, full_name, username, status, left_count, right_count, join_date FROM members WHERE member_id = ? OR username = ? LIMIT 1');
    $stmt->execute([$search, $search]);
    $root = $stmt->fetch() ?: null;
} else {
    $root = $pdo->query('SELECT id, member_id, full_name, username, status, left_count, right_count, join_date FROM members ORDER BY id ASC LIMIT 1')->fetch() ?: null;
    if ($root) {
        $search = $root['member_id'];
    }
}

$downline = [];
$leftCount = 0;
$rightCount = 0;
$modeLabel = $useBinary ? 'Binary placement' : ($useMatrix ? 'Matrix placement' : 'Sponsor generations');
$treeHref = $useBinary ? 'tree-view.php' : ($useMatrix ? 'matrix-tree.php' : 'level-tree.php');

if ($root) {
    $rid = (int) $root['id'];
    if ($useBinary) {
        $leftList = team_collect_downline($pdo, $rid, 'left');
        $rightList = team_collect_downline($pdo, $rid, 'right');
        $leftCount = count($leftList);
        $rightCount = count($rightList);
        if ($leg === 'left') {
            $downline = $leftList;
        } elseif ($leg === 'right') {
            $downline = $rightList;
        } else {
            $downline = array_merge($leftList, $rightList);
        }
    } else {
        $pack = team_collect_plan_downline($pdo, $rid, 'all');
        $downline = $pack['rows'];
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel members-panel">
    <div class="panel-header members-toolbar">
        <div>
            <h2>Downline</h2>
            <p class="members-sub"><?= e($modeLabel) ?> under a member</p>
        </div>
        <?php if ($root): ?>
        <a href="<?= e($treeHref) ?>?<?= $useBinary || $useMatrix ? 'root=' . (int) $root['id'] : 'q=' . urlencode((string) $root['member_id']) ?>" class="btn btn-outline btn-sm">Tree View</a>
        <?php endif; ?>
    </div>
    <div class="panel-body members-filters">
        <form class="members-filter-form" method="get">
            <div class="form-group">
                <label>Member ID / Username</label>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="<?= e(member_id_prefix() . str_pad('1', member_id_pad(), '0', STR_PAD_LEFT)) ?>">
            </div>
            <?php if ($useBinary): ?>
            <div class="form-group">
                <label>Leg</label>
                <select name="leg">
                    <option value="all" <?= $leg === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="left" <?= $leg === 'left' ? 'selected' : '' ?>>Left</option>
                    <option value="right" <?= $leg === 'right' ? 'selected' : '' ?>>Right</option>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">Show Downline</button>
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
        <?php if ($useBinary): ?>
        <div class="m-stat">
            <span class="m-stat-ico green">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg>
            </span>
            <div>
                <strong><?= $leftCount ?></strong>
                <span>Left Downline</span>
            </div>
        </div>
        <div class="m-stat">
            <span class="m-stat-ico orange">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg>
            </span>
            <div>
                <strong><?= $rightCount ?></strong>
                <span>Right Downline</span>
            </div>
        </div>
        <?php else: ?>
        <div class="m-stat">
            <span class="m-stat-ico green">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            </span>
            <div>
                <strong><?= team_direct_count($pdo, (int) $root['id']) ?></strong>
                <span>Direct</span>
            </div>
        </div>
        <?php endif; ?>
        <div class="m-stat">
            <span class="m-stat-ico red">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            </span>
            <div>
                <strong><?= count($downline) ?></strong>
                <span><?= $useBinary && $leg !== 'all' ? ucfirst($leg) . ' Shown' : 'Total' ?></span>
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
                    <?php if ($useBinary): ?><th>Leg</th><?php endif; ?>
                    <th>Position</th>
                    <th>Sponsor</th>
                    <th>Package</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $colspan = $useBinary ? 10 : 9;
            if (!$root): ?>
                <tr>
                    <td colspan="<?= $colspan ?>">
                        <div class="empty-state">
                            <strong>No member found</strong>
                            <span>Search by Member ID or username.</span>
                        </div>
                    </td>
                </tr>
            <?php elseif (!$downline): ?>
                <tr>
                    <td colspan="<?= $colspan ?>">
                        <div class="empty-state">
                            <strong>No downline</strong>
                            <span><?= e($root['full_name']) ?> has no team members yet.</span>
                        </div>
                    </td>
                </tr>
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
                    <?php if ($useBinary): ?><td><?= e(ucfirst((string) $m['leg'])) ?></td><?php endif; ?>
                    <td><?= e(ucfirst((string) ($m['position'] ?? '—'))) ?></td>
                    <td><?= !empty($m['sponsor_mid']) ? e($m['sponsor_mid']) : '—' ?></td>
                    <td><?= e($m['package_name'] ?? '—') ?></td>
                    <td><?= status_badge($m['status']) ?></td>
                    <td><?= e(date('d M Y', strtotime($m['join_date']))) ?></td>
                    <td>
                        <div class="action-icons">
                            <a href="<?= e($treeHref) ?>?<?= $useBinary ? 'root=' . (int) $m['id'] : ($useMatrix ? 'member=' . urlencode((string) $m['member_id']) : 'q=' . urlencode((string) $m['member_id'])) ?>" class="btn-icon" title="Tree"><?= icon_svg('package') ?></a>
                            <a href="downline.php?q=<?= urlencode($m['member_id']) ?>" class="btn-icon" title="Downline"><?= icon_svg('view') ?></a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
