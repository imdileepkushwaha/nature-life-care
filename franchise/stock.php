<?php
/**
 * Franchise Stock Inventory - Admin Panel Styled
 */
$pageTitle = 'My Stock Inventory';
require_once __DIR__ . '/includes/header.php';

$frId = (int) $currentFranchise['id'];
$q = trim($_GET['q'] ?? '');

$sql = '
    SELECT s.product_id, s.qty, s.updated_at,
           pr.name AS product_name, pr.sku, pr.price, pr.bv AS bv_points, pr.description
    FROM franchisee_stock s
    JOIN products pr ON pr.id = s.product_id
    WHERE s.franchisee_id = ?
';
$params = [$frId];

if ($q !== '') {
    $sql .= ' AND (pr.name LIKE ? OR pr.sku LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY pr.name ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalUnits = 0;
$totalValue = 0.0;
foreach ($stockItems as $item) {
    $qty = (int) $item['qty'];
    $price = (float) $item['price'];
    $totalUnits += $qty;
    $totalValue += ($qty * $price);
}
?>

<div class="panel">
    <div class="panel-header stock-panel-header">
        <div>
            <h2>My Stock Inventory (<?= count($stockItems) ?> Products)</h2>
            <p class="panel-subtitle">
                Current inventory available in your franchise for billing to members.
            </p>
        </div>
        <div>
            <a href="billing.php" class="btn btn-primary btn-sm">
                + Issue Bill to Member
            </a>
        </div>
    </div>

    <div class="panel-body">
        <!-- Filter and summary -->
        <div class="stock-toolbar">
            <form method="get" action="stock.php" class="stock-search-form">
                <div class="stock-search-wrap">
                    <svg class="search-icon" viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" name="q" class="stock-search-input" value="<?= e($q) ?>" placeholder="Search product name or SKU...">
                </div>
                <button type="submit" class="btn btn-primary">Filter</button>
                <?php if ($q !== ''): ?>
                    <a href="stock.php" class="btn btn-outline btn-sm" title="Clear filter">Clear</a>
                <?php endif; ?>
            </form>

            <div class="stock-summary-metrics">
                <div class="stock-metric-chip">
                    <span>Total Units in Hand:</span>
                    <strong><?= number_format($totalUnits) ?></strong>
                </div>
                <div class="stock-metric-chip highlight">
                    <span>Inventory Valuation:</span>
                    <strong>₹<?= number_format($totalValue, 2) ?></strong>
                </div>
            </div>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Unit Price</th>
                    <th>Available Stock</th>
                    <th>Inventory Value</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$stockItems): ?>
                <tr>
                    <td colspan="7" style="text-align:center;color:#94a3b8;padding:2.5rem">
                        <?= $q !== '' ? 'No products match your search.' : 'You currently do not have any stock in your franchise. Contact admin to receive stock.' ?>
                    </td>
                </tr>
            <?php else: foreach ($stockItems as $item): ?>
                <?php
                $qty = (int) $item['qty'];
                $price = (float) $item['price'];
                $lineValue = $qty * $price;
                ?>
                <tr>
                    <td>
                        <strong><?= e($item['product_name']) ?></strong>
                        <?php if (!empty($item['bv_points']) && (float) $item['bv_points'] > 0): ?>
                            <span class="badge badge-info" style="margin-left:0.4rem"><?= (float) $item['bv_points'] ?> BV</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= e($item['sku'] ?: '—') ?></code></td>
                    <td>₹<?= number_format($price, 2) ?></td>
                    <td>
                        <strong style="font-size:1.05rem;color:<?= $qty > 5 ? '#15803d' : ($qty > 0 ? '#b45309' : '#b91c1c') ?>">
                            <?= number_format($qty) ?>
                        </strong>
                    </td>
                    <td><strong>₹<?= number_format($lineValue, 2) ?></strong></td>
                    <td>
                        <?php if ($qty > 10): ?>
                            <span class="badge badge-success">In Stock</span>
                        <?php elseif ($qty > 0): ?>
                            <span class="badge badge-warning">Low Stock</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Out of Stock</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($qty > 0): ?>
                            <a href="billing.php?product_id=<?= (int) $item['product_id'] ?>" class="btn btn-outline btn-sm">Sell Product</a>
                        <?php else: ?>
                            <span style="color:#94a3b8;font-size:0.85rem">No Stock</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
