<?php
require_once __DIR__ . '/_boot.php';
$pageTitle = 'Franchisee Stock Details';

$franchiseeId = (int) ($_GET['franchisee_id'] ?? 0);
$franchisees = $pdo->query('SELECT id, franchisee_code, name FROM franchisees ORDER BY name')->fetchAll();
$rows = franchise_stock_rows($pdo, $franchiseeId > 0 ? $franchiseeId : null);

franchise_header();
?>

<div class="panel">
    <div class="panel-header"><h2>Stock Details</h2></div>
    <div class="panel-body">
        <form method="get" class="form-grid" style="margin-bottom:1rem;max-width:420px">
            <div class="form-group">
                <label>Franchisee</label>
                <select name="franchisee_id" onchange="this.form.submit()">
                    <option value="0">All franchisees</option>
                    <?php foreach ($franchisees as $f): ?>
                    <option value="<?= (int) $f['id'] ?>" <?= $franchiseeId === (int) $f['id'] ? 'selected' : '' ?>>
                        <?= e($f['franchisee_code'] . ' — ' . $f['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Franchisee</th>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6">No stock found. Create a product purchase to allot stock.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['franchisee_code']) ?></strong><br><small><?= e($r['franchisee_name']) ?></small></td>
                    <td><?= e($r['product_name']) ?></td>
                    <td><?= e($r['sku'] ?? '—') ?></td>
                    <td><strong><?= (int) $r['qty'] ?></strong></td>
                    <td><?= currency((float) ($r['price'] ?? 0)) ?></td>
                    <td><?= e($r['updated_at'] ?? '—') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php franchise_footer(); ?>
