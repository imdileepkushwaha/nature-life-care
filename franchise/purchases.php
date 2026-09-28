<?php
/**
 * Franchise Stock Purchases from Company - Admin Panel Styled
 */
$pageTitle = 'Stock Purchases';
require_once __DIR__ . '/includes/header.php';

$frId = (int) $currentFranchise['id'];
$viewId = (int) ($_GET['view'] ?? 0);

$viewPurchase = null;
$viewItems = [];
if ($viewId > 0) {
    $viewPurchase = franchise_purchase_get($pdo, $viewId);
    if ($viewPurchase && (int) $viewPurchase['franchisee_id'] === $frId) {
        $viewItems = franchise_purchase_items($pdo, $viewId);
    } else {
        $viewPurchase = null;
    }
}

$filters = [
    'from' => trim($_GET['from'] ?? ''),
    'to' => trim($_GET['to'] ?? ''),
];
$purchases = franchise_purchases($pdo, array_merge($filters, ['franchisee_id' => $frId]));
?>

<?php if ($viewPurchase): ?>
<!-- Invoice Detail Modal / View -->
<div class="panel" style="border: 2px solid var(--brand); margin-bottom: 2rem;">
    <div class="panel-header" style="background:#f0f9ff; display:flex; align-items:center; justify-content:space-between">
        <div>
            <h2>Purchase Invoice: <?= e($viewPurchase['invoice_no'] ?: ('INV-' . $viewPurchase['id'])) ?></h2>
            <span style="font-size:0.85rem;color:var(--brand)">Purchase Date: <?= e($viewPurchase['purchase_date']) ?></span>
        </div>
        <div style="display:flex;gap:0.5rem">
            <button onclick="window.print()" class="btn btn-primary btn-sm no-print">Print Invoice</button>
            <a href="purchases.php" class="btn btn-outline btn-sm no-print">Close</a>
        </div>
    </div>

    <div class="panel-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:1rem;margin-bottom:1rem;font-size:0.9rem">
            <div>
                <span style="color:#64748b">Supplier:</span><br>
                <strong><?= e($company) ?> (Head Office)</strong>
            </div>
            <div>
                <span style="color:#64748b">Recipient Franchise:</span><br>
                <strong><?= e($viewPurchase['franchisee_name']) ?> (<?= e($viewPurchase['franchisee_code']) ?>)</strong>
            </div>
            <div>
                <span style="color:#64748b">Invoice Status:</span><br>
                <span class="badge badge-success"><?= e($viewPurchase['status']) ?></span>
            </div>
            <div>
                <span style="color:#64748b">Total Amount:</span><br>
                <strong style="font-size:1.15rem;color:var(--brand)">₹<?= number_format((float) $viewPurchase['total_amount'], 2) ?></strong>
            </div>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Rate</th>
                    <th>Quantity</th>
                    <th>Total Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php $i = 1; foreach ($viewItems as $it): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><strong><?= e($it['product_name']) ?></strong></td>
                    <td><code><?= e($it['sku'] ?: '—') ?></code></td>
                    <td>₹<?= number_format((float) $it['rate'], 2) ?></td>
                    <td><strong><?= number_format((int) $it['qty']) ?></strong></td>
                    <td><strong>₹<?= number_format((float) $it['amount'], 2) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bill-totals-row">
                    <td colspan="4" style="text-align:right">Grand Total:</td>
                    <td><?= number_format(array_sum(array_column($viewItems, 'qty'))) ?> Units</td>
                    <td>₹<?= number_format((float) $viewPurchase['total_amount'], 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php if (!empty($viewPurchase['note'])): ?>
        <div class="panel-body">
            <p style="font-size:0.88rem;color:#64748b"><strong>Note:</strong> <?= e($viewPurchase['note']) ?></p>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-header">
        <h2>Company Stock Invoices (<?= count($purchases) ?>)</h2>
        <p style="color:#64748b;font-size:0.88rem;margin-top:0.25rem">
            All stock purchases and stock transfers received by your franchise from company admin.
        </p>
    </div>

    <div class="panel-body">
        <form method="get" action="purchases.php" style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="margin-bottom:0">
                <label style="font-size:0.8rem">From Date</label>
                <input type="date" name="from" class="form-control" value="<?= e($filters['from']) ?>">
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label style="font-size:0.8rem">To Date</label>
                <input type="date" name="to" class="form-control" value="<?= e($filters['to']) ?>">
            </div>
            <button type="submit" class="btn btn-outline" >Filter</button>
            <?php if ($filters['from'] !== '' || $filters['to'] !== ''): ?>
                <a href="purchases.php" class="btn btn-outline" style="height:38px">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Invoice No</th>
                    <th>Purchase Date</th>
                    <th>Total Amount</th>
                    <th>Note</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$purchases): ?>
                <tr>
                    <td colspan="6" style="text-align:center;color:#94a3b8;padding:2.5rem">
                        No purchase invoices found.
                    </td>
                </tr>
            <?php else: foreach ($purchases as $p): ?>
                <tr>
                    <td><strong><?= e($p['invoice_no'] ?: ('INV-' . $p['id'])) ?></strong></td>
                    <td><?= date('d M Y', strtotime($p['purchase_date'])) ?></td>
                    <td><strong style="color:var(--brand)">₹<?= number_format((float) $p['total_amount'], 2) ?></strong></td>
                    <td><?= e($p['note'] ?? '—') ?></td>
                    <td><span class="badge badge-success"><?= e($p['status']) ?></span></td>
                    <td>
                        <a href="purchases.php?view=<?= (int) $p['id'] ?>" class="btn btn-outline btn-sm">View Items</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
