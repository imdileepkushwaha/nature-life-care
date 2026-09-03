<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Add Sub-Category';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'product_subcategories', (int) $_GET['toggle']);
    header('Location: product-subcategories.php');
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'product_subcategories', (int) $_GET['delete']);
    header('Location: product-subcategories.php');
    exit;
}

$categories = $pdo->query("SELECT id, name FROM product_categories WHERE status='active' ORDER BY name")->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if (!$categoryId) $errors[] = 'Select a category.';
    if ($name === '') $errors[] = 'Sub-category name is required.';

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE product_subcategories SET category_id=?, name=?, status=? WHERE id=?')
                    ->execute([$categoryId, $name, $status, $id]);
                log_activity('product_subcategory_edit', "Updated subcategory #$id");
                flash('success', 'Sub-category updated.');
            } else {
                $pdo->prepare('INSERT INTO product_subcategories (category_id, name, status) VALUES (?,?,?)')
                    ->execute([$categoryId, $name, $status]);
                log_activity('product_subcategory_add', "Added subcategory $name");
                flash('success', 'Sub-category added.');
            }
            header('Location: product-subcategories.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Sub-category already exists for this category or DB error.';
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM product_subcategories WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
}

$rows = $pdo->query('
    SELECT s.*, c.name AS category_name
    FROM product_subcategories s
    JOIN product_categories c ON c.id = s.category_id
    ORDER BY c.name, s.name
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit Sub-Category' : 'Add Sub-Category' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Category *</label>
                    <select name="category_id" required>
                        <option value="">— Select Category —</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ((int)($edit['category_id'] ?? $_POST['category_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sub-Category Name *</label>
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
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add Sub-Category' ?></button>
                <?php if ($edit): ?><a href="product-subcategories.php" class="btn btn-outline">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>All Sub-Categories (<?= count($rows) ?>)</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Name</th><th>Category</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="4">No sub-categories yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e($r['category_name']) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= action_buttons((int)$r['id'], 'Delete this sub-category?', '', $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
