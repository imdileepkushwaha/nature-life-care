<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Add Plan';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'plans', (int) $_GET['toggle']);
    header('Location: plans.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'plans', (int) $_GET['delete']);
    header('Location: plans.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($name === '') $errors[] = 'Plan name is required.';

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE plans SET name=?, description=?, status=? WHERE id=?')->execute([$name, $description ?: null, $status, $id]);
                flash('success', 'Plan updated.');
            } else {
                $pdo->prepare('INSERT INTO plans (name, description, status) VALUES (?,?,?)')->execute([$name, $description ?: null, $status]);
                flash('success', 'Plan added.');
            }
            log_activity($id ? 'plan_edit' : 'plan_add', $name);
            header('Location: plans.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Plan name already exists.';
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM plans WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$rows = $pdo->query('SELECT p.*, (SELECT COUNT(*) FROM package_plans pp WHERE pp.plan_id = p.id) AS pkg_count FROM plans p ORDER BY p.id DESC')->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit Plan' : 'Add Plan' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Plan Name *</label>
                    <input type="text" name="name" value="<?= e($edit['name'] ?? $_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= (($edit['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (($edit['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="form-group" style="margin-top:1rem">
                <label>Description</label>
                <textarea name="description" rows="3"><?= e($edit['description'] ?? $_POST['description'] ?? '') ?></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add Plan' ?></button>
                <?php if ($edit): ?><a href="plans.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>All Plans (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Plan</th><th>Description</th><th>Packages Linked</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="5">No plans yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e($r['description'] ?? '—') ?></td>
                    <td><?= (int)$r['pkg_count'] ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td>
                        <div class="action-icons">
                            <?= action_edit('?edit=' . (int)$r['id']) ?>
                            <?= action_toggle('?toggle=' . (int)$r['id'], $r['status']) ?>
                            <a href="package-plans.php?plan_id=<?= (int)$r['id'] ?>" class="btn-icon" title="Packages" aria-label="Packages"><?= icon_svg('package') ?></a>
                            <?= action_delete('?delete=' . (int)$r['id'], 'Delete this plan?') ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
