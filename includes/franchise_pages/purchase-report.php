<?php
require_once __DIR__ . '/_boot.php';
$pageTitle = 'Product Purchase Report';

$viewId = (int) ($_GET['id'] ?? 0);
$view = $viewId > 0 ? franchise_purchase_get($pdo, $viewId) : null;
$viewItems = $view ? franchise_purchase_items($pdo, $viewId) : [];

$filters = [
    'franchisee_id' => (int) ($_GET['franchisee_id'] ?? 0),
    'from' => trim($_GET['from'] ?? ''),
    'to' => trim($_GET['to'] ?? ''),
];
$franchisees = $pdo->query('SELECT id, franchisee_code, name FROM franchisees ORDER BY name')->fetchAll();
$rows = franchise_purchases($pdo, array_filter([
    'franchisee_id' => $filters['franchisee_id'] > 0 ? $filters['franchisee_id'] : null,
    'from' => $filters['from'] !== '' ? $filters['from'] : null,
    'to' => $filters['to'] !== '' ? $filters['to'] : null,
], static fn ($v) => $v !== null));

franchise_header();
?>

<?php if ($view): ?>
<div class="panel">
    <div class="panel-header" style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center">
        <h2>Purchase #<?= (int) $view['id'] ?></h2>
        <a href="franchisee-purchase-report.php" class="btn btn-outline btn-sm">← All purchases</a>
    </div>
    <div class="panel-body">
        <div class="form-grid">
            <div class="form-group"><label>Franchisee</label><div><strong><?= e(($view['franchisee_code'] ?? '') . ' — ' . ($view['franchisee_name'] ?? '')) ?></strong></div></div>
            <div class="form-group"><label>Date</label><div><?= e($view['purchase_date']) ?></div></div>
            <div class="form-group"><label>Invoice</label><div><?= e($view['invoice_no'] ?? '—') ?></div></div>
            <div class="form-group"><label>Total</label><div><strong><?= currency((float) $view['total_amount']) ?></strong></div></div>
            <?php if (!empty($view['note'])): ?>
            <div class="form-group" style="grid-column:1/-1"><label>Note</label><div><?= e($view['note']) ?></div></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr><th>Product</th><th>SKU</th><th>Qty</th><th>Rate</th><th>Amount</th></tr>
            </thead>
            <tbody>
            <?php foreach ($viewItems as $it): ?>
                <tr>
                    <td><?= e($it['product_name']) ?></td>
                    <td><?= e($it['sku'] ?? '—') ?></td>
                    <td><?= (int) $it['qty'] ?></td>
                    <td><?= currency((float) $it['rate']) ?></td>
                    <td><?= currency((float) $it['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-header" style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center">
        <h2>Product Purchase Report</h2>
        <a href="franchisee-purchase.php" class="btn btn-primary btn-sm">+ New Purchase</a>
    </div>
    <div class="panel-body">
        <form method="get" class="form-grid" style="margin-bottom:1rem">
            <div class="form-group">
                <label>Franchisee</label>
                <select name="franchisee_id">
                    <option value="0">All</option>
                    <?php foreach ($franchisees as $f): ?>
                    <option value="<?= (int) $f['id'] ?>" <?= $filters['franchisee_id'] === (int) $f['id'] ? 'selected' : '' ?>>
                        <?= e($f['franchisee_code'] . ' — ' . $f['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>From</label>
                <input type="date" name="from" value="<?= e($filters['from']) ?>">
            </div>
            <div class="form-group">
                <label>To</label>
                <input type="date" name="to" value="<?= e($filters['to']) ?>">
            </div>
            <div class="form-group" style="align-self:end">
                <button type="submit" class="btn btn-outline">Filter</button>
            </div>
        </form>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Franchisee</th>
                    <th>Invoice</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7">No purchases found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= e($r['purchase_date']) ?></td>
                    <td><?= e(($r['franchisee_code'] ?? '') . ' — ' . ($r['franchisee_name'] ?? '')) ?></td>
                    <td><?= e($r['invoice_no'] ?? '—') ?></td>
                    <td><?= currency((float) $r['total_amount']) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><a href="franchisee-purchase-report.php?id=<?= (int) $r['id'] ?>">View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php franchise_footer(); ?>
