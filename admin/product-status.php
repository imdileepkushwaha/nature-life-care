<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Change Product Status';

if (isset($_GET['toggle'])) {
    utility_toggle_status($pdo, 'products', (int) $_GET['toggle']);
    $redir = 'product-status.php';
    if (!empty($_GET['status'])) $redir .= '?status=' . urlencode($_GET['status']);
    header('Location: ' . $redir);
    exit;
}

$statusFilter = $_GET['status'] ?? '';
$where = '1=1';
$params = [];
if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where = 'p.status = ?';
    $params[] = $statusFilter;
}

$stmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN product_categories c ON c.id = p.category_id
    WHERE $where
    ORDER BY p.name
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$active = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$inactive = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status='inactive'")->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="stats-grid" style="margin-bottom:1.25rem">
    <a href="product-status.php" class="stat-card g-blue" style="text-decoration:none">
        <div class="value"><?= $total ?></div>
        <div class="label">Total Products</div>
    </a>
    <a href="product-status.php?status=active" class="stat-card g-green" style="text-decoration:none">
        <div class="value"><?= $active ?></div>
        <div class="label">Active</div>
    </a>
    <a href="product-status.php?status=inactive" class="stat-card g-orange" style="text-decoration:none">
        <div class="value"><?= $inactive ?></div>
        <div class="label">Inactive</div>
    </a>
</div>

<div class="panel">
    <div class="panel-header" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem">
        <h2>Products (<?= count($rows) ?>)</h2>
        <form method="get" class="filters" style="margin:0">
            <div class="form-group">
                <select name="status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </form>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Quick Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="6">No products found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e($r['sku'] ?? '—') ?></td>
                    <td><?= e($r['category_name'] ?? '—') ?></td>
                    <td><?= (int)$r['stock_qty'] ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td>
                        <div class="action-icons">
                            <?= action_toggle(
                                '?toggle=' . (int)$r['id'] . ($statusFilter !== '' ? '&status=' . urlencode($statusFilter) : ''),
                                $r['status']
                            ) ?>
                            <?php if ($r['status'] === 'active'): ?>
                                <a href="?toggle=<?= (int)$r['id'] ?><?= $statusFilter !== '' ? '&status=' . urlencode($statusFilter) : '' ?>" class="btn btn-outline btn-sm">Deactivate</a>
                            <?php else: ?>
                                <a href="?toggle=<?= (int)$r['id'] ?><?= $statusFilter !== '' ? '&status=' . urlencode($statusFilter) : '' ?>" class="btn btn-primary btn-sm">Activate</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
