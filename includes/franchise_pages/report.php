<?php
require_once __DIR__ . '/_boot.php';
$pageTitle = 'Franchisee Report';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'franchisees', (int) $_GET['toggle']);
    header('Location: franchisee-report.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'franchisees', (int) $_GET['delete']);
    header('Location: franchisee-report.php');
    exit;
}

$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'status' => trim($_GET['status'] ?? ''),
    'type_id' => (int) ($_GET['type_id'] ?? 0),
];
$types = franchise_types($pdo);
$rows = franchise_list($pdo, array_filter([
    'q' => $filters['q'] !== '' ? $filters['q'] : null,
    'status' => in_array($filters['status'], ['active', 'inactive'], true) ? $filters['status'] : null,
    'type_id' => $filters['type_id'] > 0 ? $filters['type_id'] : null,
], static fn ($v) => $v !== null && $v !== ''));

franchise_header();
?>

<div class="panel">
    <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
        <h2>Franchisee Report (<?= count($rows) ?>)</h2>
        <a href="franchisee-add.php" class="btn btn-primary btn-sm">+ Add Franchisee</a>
    </div>
    <div class="panel-body">
        <form method="get" class="form-grid" style="margin-bottom:1rem">
            <div class="form-group">
                <label>Search</label>
                <input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="Code, name, phone, city">
            </div>
            <div class="form-group">
                <label>Type</label>
                <select name="type_id">
                    <option value="0">All</option>
                    <?php foreach ($types as $t): ?>
                    <option value="<?= (int) $t['id'] ?>" <?= $filters['type_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="">All</option>
                    <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="form-group" style="align-self:end">
                <button type="submit" class="btn btn-outline">Filter</button>
            </div>
        </form>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Phone</th>
                    <th>GST No</th>
                    <th>City</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8">No franchisees found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['franchisee_code']) ?></strong></td>
                    <td>
                        <?= e($r['name']) ?>
                        <?php if (!empty($r['username'])): ?>
                            <br><small style="color:#64748b">User: <?= e($r['username']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e($r['type_name'] ?? '—') ?></td>
                    <td><?= e($r['phone'] ?? '—') ?></td>
                    <td><?= !empty($r['gst_no']) ? '<code>' . e($r['gst_no']) . '</code>' : '<span style="color:#94a3b8">—</span>' ?></td>
                    <td><?= e($r['city'] ?? '—') ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td>
                        <div class="action-icons" style="align-items:center">
                            <?= action_edit('franchisee-add.php?edit=' . (int) $r['id']) ?>
                            <?= action_toggle('?toggle=' . (int) $r['id'], $r['status']) ?>
                            <?= action_delete('?delete=' . (int) $r['id'], 'Delete this franchisee?') ?>
                            <?php if (($r['status'] ?? '') === 'active'): ?>
                                <a href="direct-franchise-login.php?id=<?= (int) $r['id'] ?>" target="_blank" class="btn btn-primary btn-sm" style="padding:0.25rem 0.6rem;font-size:0.75rem;white-space:nowrap;margin-left:0.35rem" title="Login directly to franchise portal in new tab">Login ↗</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php franchise_footer(); ?>
