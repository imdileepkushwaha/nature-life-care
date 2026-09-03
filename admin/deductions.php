<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Deduction Master';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'deductions', (int) $_GET['toggle']);
    header('Location: deductions.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'deductions', (int) $_GET['delete']);
    header('Location: deductions.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $type = ($_POST['deduction_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
    $value = (float) ($_POST['value'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($name === '') $errors[] = 'Name is required.';
    if ($value < 0) $errors[] = 'Value cannot be negative.';

    if (!$errors) {
        if ($id > 0) {
            $pdo->prepare('UPDATE deductions SET name=?, deduction_type=?, value=?, description=?, status=? WHERE id=?')
                ->execute([$name, $type, $value, $description ?: null, $status, $id]);
            flash('success', 'Deduction updated.');
        } else {
            $pdo->prepare('INSERT INTO deductions (name, deduction_type, value, description, status) VALUES (?,?,?,?,?)')
                ->execute([$name, $type, $value, $description ?: null, $status]);
            flash('success', 'Deduction added.');
        }
        log_activity($id ? 'deduction_edit' : 'deduction_add', $name);
        header('Location: deductions.php');
        exit;
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM deductions WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$rows = $pdo->query('SELECT * FROM deductions ORDER BY id DESC')->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit Deduction' : 'Add Deduction' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" name="name" value="<?= e($edit['name'] ?? $_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Type *</label>
                    <select name="deduction_type">
                        <option value="percent" <?= (($edit['deduction_type'] ?? 'percent') === 'percent') ? 'selected' : '' ?>>Percent (%)</option>
                        <option value="fixed" <?= (($edit['deduction_type'] ?? '') === 'fixed') ? 'selected' : '' ?>>Fixed Amount</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Value *</label>
                    <input type="number" step="0.01" name="value" value="<?= e((string)($edit['value'] ?? $_POST['value'] ?? '0')) ?>" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="description" value="<?= e($edit['description'] ?? $_POST['description'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= (($edit['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (($edit['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add Deduction' ?></button>
                <?php if ($edit): ?><a href="deductions.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>Deduction List (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Name</th><th>Type</th><th>Value</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="6">No deductions yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e(ucfirst($r['deduction_type'])) ?></td>
                    <td><?= $r['deduction_type'] === 'percent' ? number_format((float)$r['value'], 2) . '%' : currency((float)$r['value']) ?></td>
                    <td><?= e($r['description'] ?? '—') ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete this deduction?', '', $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
