<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Purchase Details';

$purchaseId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (isset($_GET['delete'])) {
    $delId = (int) $_GET['delete'];
    try {
        $pdo->beginTransaction();

        $items = $pdo->prepare('SELECT product_id, qty FROM stock_purchase_items WHERE purchase_id = ?');
        $items->execute([$delId]);
        $lines = $items->fetchAll();

        $stockStmt = $pdo->prepare('UPDATE products SET stock_qty = GREATEST(0, stock_qty - ?) WHERE id = ?');
        foreach ($lines as $line) {
            $stockStmt->execute([(int)$line['qty'], (int)$line['product_id']]);
        }

        // Items cascade on purchase delete; reverse stock first, then remove purchase.
        $pdo->prepare('DELETE FROM stock_purchases WHERE id = ?')->execute([$delId]);
        $pdo->commit();
        log_activity('stock_purchase_delete', "Deleted purchase #$delId and reversed stock");
        flash('success', 'Purchase deleted and stock reversed.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'Could not delete purchase (may be in use).');
    }
    header('Location: purchase-details.php');
    exit;
}

if ($purchaseId > 0) {
    $stmt = $pdo->prepare('
        SELECT sp.*, v.name AS vendor_name, v.phone AS vendor_phone, v.email AS vendor_email
        FROM stock_purchases sp
        JOIN product_vendors v ON v.id = sp.vendor_id
        WHERE sp.id = ?
    ');
    $stmt->execute([$purchaseId]);
    $purchase = $stmt->fetch();

    $itemRows = [];
    if ($purchase) {
        $is = $pdo->prepare('
            SELECT spi.*, p.name AS product_name, p.sku
            FROM stock_purchase_items spi
            JOIN products p ON p.id = spi.product_id
            WHERE spi.purchase_id = ?
            ORDER BY spi.id
        ');
        $is->execute([$purchaseId]);
        $itemRows = $is->fetchAll();
        $pageTitle = 'Purchase #' . $purchaseId;
    }

    require_once __DIR__ . '/../includes/header.php';
    ?>

    <?php if (!$purchase): ?>
        <div class="panel">
            <div class="panel-body">
                <div class="alert alert-error">Purchase not found.</div>
                <a href="purchase-details.php" class="btn btn-outline">Back to list</a>
            </div>
        </div>
    <?php else: ?>
        <div class="panel">
            <div class="panel-header" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem">
                <h2>Purchase #<?= (int)$purchase['id'] ?></h2>
                <div>
                    <a href="purchase-details.php" class="btn btn-outline">All Purchases</a>
                    <a href="stock-purchase.php" class="btn btn-primary">New Purchase</a>
                </div>
            </div>
            <div class="panel-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Vendor</label>
                        <div><strong><?= e($purchase['vendor_name']) ?></strong></div>
                    </div>
                    <div class="form-group">
                        <label>Invoice No</label>
                        <div><?= e($purchase['invoice_no'] ?? '—') ?></div>
                    </div>
                    <div class="form-group">
                        <label>Purchase Date</label>
                        <div><?= e($purchase['purchase_date']) ?></div>
                    </div>
                    <div class="form-group">
                        <label>Total Amount</label>
                        <div><strong><?= currency((float)$purchase['total_amount']) ?></strong></div>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <div><?= status_badge($purchase['status']) ?></div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Note</label>
                        <div><?= e($purchase['note'] ?? '—') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header"><h2>Items (<?= count($itemRows) ?>)</h2></div>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Batch Number</th>
                            <th>Qty</th>
                            <th>Rate</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$itemRows): ?><tr><td colspan="6">No items.</td></tr>
                    <?php else: foreach ($itemRows as $it): ?>
                        <tr>
                            <td><strong><?= e($it['product_name']) ?></strong></td>
                            <td><?= e($it['sku'] ?? '—') ?></td>
                            <td><?= e($it['batch_number'] ?? '—') ?></td>
                            <td><?= (int)$it['qty'] ?></td>
                            <td><?= currency((float)$it['rate']) ?></td>
                            <td><?= currency((float)$it['amount']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="panel-body">
                <a href="?delete=<?= (int)$purchase['id'] ?>" class="btn btn-danger" data-confirm="Delete this purchase and reverse stock quantities?">Delete Purchase</a>
            </div>
        </div>
    <?php endif; ?>

    <?php require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// List all purchases
$rows = $pdo->query('
    SELECT sp.*, v.name AS vendor_name
    FROM stock_purchases sp
    JOIN product_vendors v ON v.id = sp.vendor_id
    ORDER BY sp.id DESC
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem">
        <h2>All Purchases (<?= count($rows) ?>)</h2>
        <a href="stock-purchase.php" class="btn btn-primary">New Purchase</a>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Vendor</th>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="7">No purchases yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><strong><?= e($r['vendor_name']) ?></strong></td>
                    <td><?= e($r['invoice_no'] ?? '—') ?></td>
                    <td><?= e($r['purchase_date']) ?></td>
                    <td><?= currency((float)$r['total_amount']) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td>
                        <div class="action-icons">
                            <?= action_edit('?id=' . (int)$r['id']) ?>
                            <?= action_delete('?delete=' . (int)$r['id'], 'Delete this purchase and reverse stock?') ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
