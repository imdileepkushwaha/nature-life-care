<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Add Product';

$categories = $pdo->query("SELECT id, name FROM product_categories WHERE status='active' ORDER BY name")->fetchAll();
$subcategories = $pdo->query("
    SELECT id, category_id, name FROM product_subcategories WHERE status='active' ORDER BY name
")->fetchAll();
$sizes = $pdo->query("SELECT id, name FROM product_sizes WHERE status='active' ORDER BY sort_order, name")->fetchAll();
$colors = $pdo->query("SELECT id, name FROM product_colors WHERE status='active' ORDER BY name")->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $categoryId = (int) ($_POST['category_id'] ?? 0) ?: null;
    $subcategoryId = (int) ($_POST['subcategory_id'] ?? 0) ?: null;
    $sizeId = (int) ($_POST['size_id'] ?? 0) ?: null;
    $colorId = (int) ($_POST['color_id'] ?? 0) ?: null;
    $price = (float) ($_POST['price'] ?? 0);
    $mrp = (float) ($_POST['mrp'] ?? 0);
    $stockQty = (int) ($_POST['stock_qty'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($name === '') $errors[] = 'Product name is required.';
    if ($price < 0) $errors[] = 'Price cannot be negative.';
    if ($stockQty < 0) $errors[] = 'Stock cannot be negative.';

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare('
                    UPDATE products SET name=?, sku=?, category_id=?, subcategory_id=?, size_id=?, color_id=?,
                        price=?, mrp=?, stock_qty=?, description=?, status=?
                    WHERE id=?
                ')->execute([
                    $name, $sku ?: null, $categoryId, $subcategoryId, $sizeId, $colorId,
                    $price, $mrp, $stockQty, $description ?: null, $status, $id
                ]);
                log_activity('product_edit', "Updated product #$id");
                flash('success', 'Product updated.');
            } else {
                $pdo->prepare('
                    INSERT INTO products (name, sku, category_id, subcategory_id, size_id, color_id, price, mrp, stock_qty, description, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ')->execute([
                    $name, $sku ?: null, $categoryId, $subcategoryId, $sizeId, $colorId,
                    $price, $mrp, $stockQty, $description ?: null, $status
                ]);
                log_activity('product_add', "Added product $name");
                flash('success', 'Product added.');
            }
            header('Location: product-details.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'SKU already exists or DB error.';
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
    if ($edit) {
        $pageTitle = 'Edit Product';
    }
}

$selectedCategory = (int)($edit['category_id'] ?? $_POST['category_id'] ?? 0);
$selectedSub = (int)($edit['subcategory_id'] ?? $_POST['subcategory_id'] ?? 0);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2><?= $edit ? 'Edit Product' : 'Add Product' ?></h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" name="name" value="<?= e($edit['name'] ?? $_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>SKU</label>
                    <input type="text" name="sku" value="<?= e($edit['sku'] ?? $_POST['sku'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id" id="category_id">
                        <option value="">— Select Category —</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $selectedCategory === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sub-Category</label>
                    <select name="subcategory_id" id="subcategory_id">
                        <option value="">— Select Sub-Category —</option>
                        <?php foreach ($subcategories as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" data-category="<?= (int)$s['category_id'] ?>" <?= $selectedSub === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Size</label>
                    <select name="size_id">
                        <option value="">— None —</option>
                        <?php foreach ($sizes as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= ((int)($edit['size_id'] ?? $_POST['size_id'] ?? 0) === (int)$s['id']) ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Color</label>
                    <select name="color_id">
                        <option value="">— None —</option>
                        <?php foreach ($colors as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ((int)($edit['color_id'] ?? $_POST['color_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Price *</label>
                    <input type="number" step="0.01" name="price" value="<?= e((string)($edit['price'] ?? $_POST['price'] ?? '0')) ?>" required>
                </div>
                <div class="form-group">
                    <label>MRP</label>
                    <input type="number" step="0.01" name="mrp" value="<?= e((string)($edit['mrp'] ?? $_POST['mrp'] ?? '0')) ?>">
                </div>
                <div class="form-group">
                    <label>Stock Qty</label>
                    <input type="number" name="stock_qty" value="<?= (int)($edit['stock_qty'] ?? $_POST['stock_qty'] ?? 0) ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= (($edit['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (($edit['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label>Description</label>
                    <textarea name="description" rows="3"><?= e($edit['description'] ?? $_POST['description'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Update Product' : 'Add Product' ?></button>
                <a href="product-details.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var cat = document.getElementById('category_id');
    var sub = document.getElementById('subcategory_id');
    if (!cat || !sub) return;
    function filterSubs() {
        var cid = cat.value;
        Array.prototype.forEach.call(sub.options, function (opt) {
            if (!opt.value) { opt.hidden = false; return; }
            var match = !cid || opt.getAttribute('data-category') === cid;
            opt.hidden = !match;
            if (!match && opt.selected) opt.selected = false;
        });
    }
    cat.addEventListener('change', filterSubs);
    filterSubs();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
