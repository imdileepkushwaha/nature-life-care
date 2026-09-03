<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Product Details';
products_ensure_columns($pdo);

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'products', (int) $_GET['toggle']);
    $redir = 'product-details.php';
    if (!empty($_GET['q'])) $redir .= '?q=' . urlencode($_GET['q']);
    header('Location: ' . $redir);
    exit;
}
if (isset($_GET['delete'])) {
    utility_delete($pdo, 'products', (int) $_GET['delete']);
    header('Location: product-details.php');
    exit;
}

$q = trim($_GET['q'] ?? '');
$params = [];
$where = '1=1';
if ($q !== '') {
    $where = '(p.name LIKE ? OR p.sku LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like];
}

$stmt = $pdo->prepare("
    SELECT p.*,
           c.name AS category_name,
           sc.name AS subcategory_name,
           sz.name AS size_name,
           cl.name AS color_name
    FROM products p
    LEFT JOIN product_categories c ON c.id = p.category_id
    LEFT JOIN product_subcategories sc ON sc.id = p.subcategory_id
    LEFT JOIN product_sizes sz ON sz.id = p.size_id
    LEFT JOIN product_colors cl ON cl.id = p.color_id
    WHERE $where
    ORDER BY p.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem">
        <h2>All Products (<?= count($rows) ?>)</h2>
        <form method="get" class="filters" style="margin:0">
            <div class="form-group">
                <input type="text" name="q" placeholder="Search name / SKU" value="<?= e($q) ?>">
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if ($q !== ''): ?><a href="product-details.php" class="btn btn-outline">Clear</a><?php endif; ?>
            <a href="product-form.php" class="btn btn-primary">Add Product</a>
        </form>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th>Sub-Category</th>
                    <th>Size</th>
                    <th>Color</th>
                    <th>Price</th>
                    <th>PV</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="11">No products found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e($r['sku'] ?? '—') ?></td>
                    <td><?= e($r['category_name'] ?? '—') ?></td>
                    <td><?= e($r['subcategory_name'] ?? '—') ?></td>
                    <td><?= e($r['size_name'] ?? '—') ?></td>
                    <td><?= e($r['color_name'] ?? '—') ?></td>
                    <td><?= currency((float)$r['price']) ?></td>
                    <td><?= number_format((float) ($r['bv'] ?? 0), 2) ?></td>
                    <td><?= (int)$r['stock_qty'] ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td>
                        <div class="action-icons">
                            <?= action_edit('product-form.php?edit=' . (int)$r['id']) ?>
                            <?= action_toggle('?toggle=' . (int)$r['id'] . ($q !== '' ? '&q=' . urlencode($q) : ''), $r['status']) ?>
                            <?= action_delete('?delete=' . (int)$r['id'], 'Delete this product?') ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
