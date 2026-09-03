<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
require_once __DIR__ . '/../includes/report_helpers.php';
$pageTitle = 'Stock Report';

$q = trim((string) ($_GET['q'] ?? ''));
$categoryId = (int) ($_GET['category_id'] ?? 0);
$status = trim((string) ($_GET['status'] ?? ''));
$stockLevel = trim((string) ($_GET['stock'] ?? ''));

$categories = [];
try {
    $categories = $pdo->query("SELECT id, name FROM product_categories ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    $categories = [];
}

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like);
}
if ($categoryId > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $categoryId;
}
if ($status !== '' && in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'p.status = ?';
    $params[] = $status;
}

$sql = '
    SELECT p.id, p.name, p.sku, p.stock_qty, p.status,
           c.name AS category_name,
           ss.min_stock_alert
    FROM products p
    LEFT JOIN product_categories c ON c.id = p.category_id
    LEFT JOIN subcategory_settings ss ON ss.subcategory_id = p.subcategory_id AND ss.status = \'active\'
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY p.stock_qty ASC, p.name
';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allRows = $stmt->fetchAll();

$rows = [];
$totalProducts = 0;
$totalStock = 0;
$lowStock = 0;
$outOfStock = 0;
foreach ($allRows as $r) {
    $alert = $r['min_stock_alert'] !== null ? (int) $r['min_stock_alert'] : 5;
    $r['_alert'] = $alert;
    $r['_low'] = (int) $r['stock_qty'] <= $alert;
    $r['_out'] = (int) $r['stock_qty'] <= 0;

    if ($stockLevel === 'low' && !$r['_low']) {
        continue;
    }
    if ($stockLevel === 'out' && !$r['_out']) {
        continue;
    }
    if ($stockLevel === 'ok' && $r['_low']) {
        continue;
    }

    $totalProducts++;
    $totalStock += (int) $r['stock_qty'];
    if ($r['_out']) {
        $outOfStock++;
    }
    if ($r['_low']) {
        $lowStock++;
    }
    $rows[] = $r;
}

$catOpts = ['' => 'All categories'];
foreach ($categories as $c) {
    $catOpts[(string) $c['id']] = $c['name'];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>Stock Report</h2>
            <p class="tpin-panel-sub">Filter by product, category, status and stock level</p>
        </div>
    </div>
    <div class="panel-body">
        <?php
        report_filter_form('stock-report.php', '', '', [
            ['name' => 'q', 'label' => 'Search', 'type' => 'text', 'value' => $q, 'placeholder' => 'Product / SKU', 'wide' => true],
            ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'value' => $categoryId > 0 ? (string) $categoryId : '', 'options' => $catOpts],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'value' => $status, 'options' => [
                '' => 'All status', 'active' => 'Active', 'inactive' => 'Inactive',
            ]],
            ['name' => 'stock', 'label' => 'Stock level', 'type' => 'select', 'value' => $stockLevel, 'options' => [
                '' => 'All levels',
                'ok' => 'Healthy',
                'low' => 'Low stock',
                'out' => 'Out of stock',
            ]],
        ], ['show_dates' => false]);
        ?>

        <div class="stats-grid tpin-stats">
            <div class="stat-card accent"><div class="label">Products</div><div class="value"><?= $totalProducts ?></div></div>
            <div class="stat-card"><div class="label">Total units</div><div class="value"><?= $totalStock ?></div></div>
            <div class="stat-card"><div class="label">Low stock</div><div class="value"><?= $lowStock ?></div></div>
            <div class="stat-card"><div class="label">Out of stock</div><div class="value"><?= $outOfStock ?></div></div>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data tpin-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th>Stock Qty</th>
                    <th>Min Alert</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" style="text-align:center;padding:1.2rem;color:#64748b">No products match these filters.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr<?= $r['_low'] ? ' style="background:rgba(225,29,72,.06)"' : '' ?>>
                    <td>
                        <strong><?= e($r['name']) ?></strong>
                        <?php if ($r['_low']): ?>
                            <span class="badge badge-inactive" style="margin-left:.35rem">Low</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($r['sku'] ?? '—') ?></td>
                    <td><?= e($r['category_name'] ?? '—') ?></td>
                    <td><strong><?= (int) $r['stock_qty'] ?></strong></td>
                    <td><?= (int) $r['_alert'] ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
