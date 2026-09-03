<?php
require_once __DIR__ . '/../config/database.php';
$pageTitle = 'Member List';

if (isset($_GET['action'], $_GET['id']) && in_array($_GET['action'], ['activate', 'deactivate', 'block'], true)) {
    $id = (int) $_GET['id'];
    $map = ['activate' => 'active', 'deactivate' => 'inactive', 'block' => 'blocked'];
    $status = $map[$_GET['action']];
    $pdo->prepare('UPDATE members SET status = ? WHERE id = ?')->execute([$status, $id]);
    log_activity('member_status', "Member #$id set to $status");
    flash('success', "Member status updated to $status.");
    header('Location: members.php');
    exit;
}

$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = '(m.member_id LIKE ? OR m.username LIKE ? OR m.full_name LIKE ? OR m.email LIKE ? OR m.phone LIKE ?)';
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
if (in_array($statusFilter, ['active', 'inactive', 'blocked'], true)) {
    $where[] = 'm.status = ?';
    $params[] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM members m WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $pdo->prepare("
    SELECT m.id, m.member_id, m.username, m.full_name, m.email, m.phone,
           m.status, m.join_date, m.package_id, p.name AS package_name
    FROM members m
    LEFT JOIN packages p ON p.id = m.package_id
    WHERE $whereSql
    ORDER BY m.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$members = $stmt->fetchAll();

$statTotal = (int) $pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
$statActive = (int) $pdo->query("SELECT COUNT(*) FROM members WHERE status = 'active'")->fetchColumn();
$statInactive = (int) $pdo->query("SELECT COUNT(*) FROM members WHERE status = 'inactive'")->fetchColumn();
$statBlocked = (int) $pdo->query("SELECT COUNT(*) FROM members WHERE status = 'blocked'")->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="members-stats">
    <a href="members.php" class="m-stat <?= $statusFilter === '' && $q === '' ? 'is-on' : '' ?>">
        <span class="m-stat-ico blue"><?= icon_svg('view') ?></span>
        <div>
            <strong><?= $statTotal ?></strong>
            <span>Total Members</span>
        </div>
    </a>
    <a href="members.php?status=active" class="m-stat <?= $statusFilter === 'active' ? 'is-on' : '' ?>">
        <span class="m-stat-ico green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </span>
        <div>
            <strong><?= $statActive ?></strong>
            <span>Active</span>
        </div>
    </a>
    <a href="members.php?status=inactive" class="m-stat <?= $statusFilter === 'inactive' ? 'is-on' : '' ?>">
        <span class="m-stat-ico orange">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </span>
        <div>
            <strong><?= $statInactive ?></strong>
            <span>Inactive</span>
        </div>
    </a>
    <a href="members.php?status=blocked" class="m-stat <?= $statusFilter === 'blocked' ? 'is-on' : '' ?>">
        <span class="m-stat-ico red">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
        </span>
        <div>
            <strong><?= $statBlocked ?></strong>
            <span>Blocked</span>
        </div>
    </a>
</div>

<div class="panel members-panel">
    <div class="panel-header members-toolbar">
        <div>
            <h2>Member List</h2>
            <p class="members-sub">Showing <?= count($members) ?> of <?= $total ?> members</p>
        </div>
        <a href="member-add.php" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Member
        </a>
    </div>

    <div class="panel-body members-filters">
        <form class="members-filter-form" method="get">
            <div class="search-field">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search ID, name, email, phone...">
            </div>
            <select name="status" class="status-select">
                <option value="">All Status</option>
                <?php foreach (['active', 'inactive', 'blocked'] as $s): ?>
                <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="members.php" class="btn btn-outline">Reset</a>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data members-table">
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Contact</th>
                    <th>Package</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$members): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <strong>No members found</strong>
                            <p>Try a different search or add a new member.</p>
                            <a href="member-add.php" class="btn btn-primary btn-sm">Add Member</a>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($members as $m): ?>
                <tr>
                    <td>
                        <div class="member-cell">
                            <span class="member-avatar"><?= strtoupper(substr((string) $m['full_name'], 0, 1)) ?></span>
                            <div>
                                <a class="member-id" href="member-view.php?id=<?= (int) $m['id'] ?>"><?= e($m['member_id']) ?></a>
                                <strong><?= e($m['full_name']) ?></strong>
                                <small>@<?= e($m['username']) ?></small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if (!empty($m['phone']) || !empty($m['email'])): ?>
                            <?php if (!empty($m['phone'])): ?>
                                <strong><?= e($m['phone']) ?></strong>
                            <?php endif; ?>
                            <?php if (!empty($m['email'])): ?>
                                <small class="d-block muted"><?= e($m['email']) ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($m['package_name']): ?>
                            <span class="pkg-chip"><?= e($m['package_name']) ?></span>
                        <?php else: ?>
                            <span class="muted">No package</span>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $m['status']) ?></td>
                    <td><span class="muted"><?= !empty($m['join_date']) ? date('d M Y', strtotime((string) $m['join_date'])) : '—' ?></span></td>
                    <td>
                        <div class="action-icons" style="justify-content:flex-end">
                            <a href="member-view.php?id=<?= (int) $m['id'] ?>" class="btn-icon btn-icon-view" title="View details"><?= icon_svg('view') ?></a>
                            <a href="member-edit.php?id=<?= (int) $m['id'] ?>" class="btn-icon" title="Edit"><?= icon_svg('edit') ?></a>
                            <?php if ($m['status'] === 'active'): ?>
                                <?= action_toggle('?action=deactivate&id=' . (int) $m['id'], 'active') ?>
                            <?php else: ?>
                                <?= action_toggle('?action=activate&id=' . (int) $m['id'], 'inactive') ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination members-pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($statusFilter) ?>">‹ Prev</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current"><?= $i ?></span>
            <?php else: ?>
                <a href="?page=<?= $i ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($statusFilter) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($statusFilter) ?>">Next ›</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
