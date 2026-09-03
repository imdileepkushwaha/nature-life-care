<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Add Size';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'product_sizes', (int) $_GET['toggle']);
    header('Location: product-sizes.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'product_sizes', (int) $_GET['delete']);
    header('Location: product-sizes.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($name === '') $errors[] = 'Size name is required.';

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE product_sizes SET name=?, sort_order=?, status=? WHERE id=?')
                    ->execute([$name, $sortOrder, $status, $id]);
                log_activity('product_size_edit', "Updated size #$id");
                flash('success', 'Size updated.');
            } else {
                $pdo->prepare('INSERT INTO product_sizes (name, sort_order, status) VALUES (?,?,?)')
                    ->execute([$name, $sortOrder, $status]);
                log_activity('product_size_add', "Added size $name");
                flash('success', 'Size added.');
            }
            header('Location: product-sizes.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Size already exists or DB error.';
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM product_sizes WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$rows = $pdo->query('SELECT * FROM product_sizes ORDER BY sort_order, name')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit Size' : 'Add Size' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Size Name *</label>
                    <input type="text" name="name" value="<?= e($edit['name'] ?? $_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? $_POST['sort_order'] ?? 0) ?>">
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
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add Size' ?></button>
                <?php if ($edit): ?><a href="product-sizes.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>All Sizes (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Name</th><th>Sort Order</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="4">No sizes yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= (int)$r['sort_order'] ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete this size?', '', $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
