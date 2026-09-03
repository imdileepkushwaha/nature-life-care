<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/utility.php';
$pageTitle = 'Stock Purchase';

$vendors = $pdo->query("SELECT id, name FROM product_vendors WHERE status='active' ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT id, name, sku FROM products WHERE status='active' ORDER BY name")->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vendorId = (int) ($_POST['vendor_id'] ?? 0);
    $invoiceNo = trim($_POST['invoice_no'] ?? '');
    $purchaseDate = trim($_POST['purchase_date'] ?? '');
    $note = trim($_POST['note'] ?? '');

    $lineProducts = $_POST['product_id'] ?? [];
    $lineBatches = $_POST['batch_number'] ?? [];
    $lineQtys = $_POST['qty'] ?? [];
    $lineRates = $_POST['rate'] ?? [];

    $items = [];
    $count = max(count($lineProducts), count($lineBatches), count($lineQtys), count($lineRates));
    for ($i = 0; $i < $count; $i++) {
        $pid = (int) ($lineProducts[$i] ?? 0);
        $qty = (int) ($lineQtys[$i] ?? 0);
        $rate = (float) ($lineRates[$i] ?? 0);
        $batch = trim((string) ($lineBatches[$i] ?? ''));
        if ($pid > 0 && $qty > 0) {
            $items[] = [
                'product_id' => $pid,
                'batch_number' => $batch !== '' ? $batch : null,
                'qty' => $qty,
                'rate' => $rate,
                'amount' => round($qty * $rate, 2),
            ];
        }
    }

    if (!$vendorId) $errors[] = 'Select a vendor.';
    if ($purchaseDate === '') $errors[] = 'Purchase date is required.';
    if (!$items) $errors[] = 'Add at least one line item with product and qty > 0.';

    if (!$errors) {
        $totalAmount = 0;
        foreach ($items as $it) {
            $totalAmount += $it['amount'];
        }

        try {
            $pdo->beginTransaction();

            $pdo->prepare('
                INSERT INTO stock_purchases (vendor_id, invoice_no, purchase_date, total_amount, note, status)
                VALUES (?,?,?,?,?,?)
            ')->execute([
                $vendorId,
                $invoiceNo ?: null,
                $purchaseDate,
                $totalAmount,
                $note ?: null,
                'completed',
            ]);
            $purchaseId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('
                INSERT INTO stock_purchase_items (purchase_id, product_id, batch_number, qty, rate, amount)
                VALUES (?,?,?,?,?,?)
            ');
            $stockStmt = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?');

            foreach ($items as $it) {
                $itemStmt->execute([$purchaseId, $it['product_id'], $it['batch_number'], $it['qty'], $it['rate'], $it['amount']]);
                $stockStmt->execute([$it['qty'], $it['product_id']]);
            }

            $pdo->commit();
            log_activity('stock_purchase_add', "Purchase #$purchaseId total $totalAmount");
            flash('success', 'Stock purchase saved and inventory updated.');
            header('Location: purchase-details.php?id=' . $purchaseId);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Could not save purchase. Please try again.';
        }
    }
}

$recent = $pdo->query('
    SELECT sp.*, v.name AS vendor_name
    FROM stock_purchases sp
    JOIN product_vendors v ON v.id = sp.vendor_id
    ORDER BY sp.id DESC
    LIMIT 15
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header"><h2>New Stock Purchase</h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <form method="post">
            <div class="form-grid">
                <div class="form-group">
                    <label>Vendor *</label>
                    <select name="vendor_id" required>
                        <option value="">— Select Vendor —</option>
                        <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= ((int)($_POST['vendor_id'] ?? 0) === (int)$v['id']) ? 'selected' : '' ?>><?= e($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Invoice No</label>
                    <input type="text" name="invoice_no" value="<?= e($_POST['invoice_no'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Purchase Date *</label>
                    <input type="date" name="purchase_date" value="<?= e($_POST['purchase_date'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label>Note</label>
                    <textarea name="note" rows="2"><?= e($_POST['note'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="spi-card" id="spiCard">
                <div class="spi-head">
                    <div class="spi-head-left">
                        <span class="spi-head-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                        </span>
                        <div>
                            <h3>Line Items</h3>
                            <p>Product, batch number, quantity aur rate add karein. Amount auto calculate hoga.</p>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" id="spiAddRow">+ Add Item</button>
                </div>

                <div class="spi-table-wrap">
                    <div class="spi-cols">
                        <span>#</span>
                        <span>Product</span>
                        <span>Batch Number</span>
                        <span>Qty</span>
                        <span>Rate</span>
                        <span>Amount</span>
                        <span></span>
                    </div>
                    <div class="spi-rows" id="spiRows">
                        <?php
                        $postedCount = max(1, count($_POST['product_id'] ?? [0]));
                        $rowCount = min(20, max(1, $postedCount));
                        for ($i = 0; $i < $rowCount; $i++):
                            $rowQty = (int)($_POST['qty'][$i] ?? 0);
                            $rowRate = (float)($_POST['rate'][$i] ?? 0);
                            $rowAmt = round($rowQty * $rowRate, 2);
                        ?>
                        <div class="spi-row">
                            <div class="spi-no"><?= $i + 1 ?></div>
                            <div class="spi-field">
                                <select name="product_id[]" class="spi-product">
                                    <option value="">— Select Product —</option>
                                    <?php foreach ($products as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= ((int)($_POST['product_id'][$i] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>>
                                        <?= e($p['name'] . ($p['sku'] ? ' (' . $p['sku'] . ')' : '')) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="spi-field">
                                <input type="text" name="batch_number[]" class="spi-batch" maxlength="60" placeholder="e.g. BATCH-001" value="<?= e((string)($_POST['batch_number'][$i] ?? '')) ?>">
                            </div>
                            <div class="spi-field">
                                <input type="number" name="qty[]" class="spi-qty" min="0" value="<?= $rowQty ?>">
                            </div>
                            <div class="spi-field">
                                <input type="number" step="0.01" name="rate[]" class="spi-rate" min="0" value="<?= e((string)($_POST['rate'][$i] ?? '0')) ?>">
                            </div>
                            <div class="spi-amount" data-spi-amount><?= number_format($rowAmt, 2) ?></div>
                            <div class="spi-actions">
                                <button type="button" class="spi-remove" title="Remove row" aria-label="Remove row">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
                                </button>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="spi-foot">
                    <span>Total Amount</span>
                    <strong id="spiTotal">0.00</strong>
                </div>
            </div>

            <template id="spiRowTemplate">
                <div class="spi-row">
                    <div class="spi-no">1</div>
                    <div class="spi-field">
                        <select name="product_id[]" class="spi-product">
                            <option value="">— Select Product —</option>
                            <?php foreach ($products as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= e($p['name'] . ($p['sku'] ? ' (' . $p['sku'] . ')' : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="spi-field">
                        <input type="text" name="batch_number[]" class="spi-batch" maxlength="60" placeholder="e.g. BATCH-001" value="">
                    </div>
                    <div class="spi-field">
                        <input type="number" name="qty[]" class="spi-qty" min="0" value="0">
                    </div>
                    <div class="spi-field">
                        <input type="number" step="0.01" name="rate[]" class="spi-rate" min="0" value="0">
                    </div>
                    <div class="spi-amount" data-spi-amount>0.00</div>
                    <div class="spi-actions">
                        <button type="button" class="spi-remove" title="Remove row" aria-label="Remove row">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
                        </button>
                    </div>
                </div>
            </template>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Purchase</button>
                <a href="purchase-details.php" class="btn btn-outline">View All Purchases</a>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header"><h2>Recent Purchases</h2></div>
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
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$recent): ?><tr><td colspan="7">No purchases yet.</td></tr>
            <?php else: foreach ($recent as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><strong><?= e($r['vendor_name']) ?></strong></td>
                    <td><?= e($r['invoice_no'] ?? '—') ?></td>
                    <td><?= e($r['purchase_date']) ?></td>
                    <td><?= currency((float)$r['total_amount']) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><a href="purchase-details.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
