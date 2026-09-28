<?php
require_once __DIR__ . '/_boot.php';
$pageTitle = 'Franchisee Product Purchase';

$franchisees = $pdo->query("SELECT id, franchisee_code, name FROM franchisees WHERE status='active' ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT id, name, sku, price, stock_qty FROM products WHERE status='active' ORDER BY name")->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $franchiseeId = (int) ($_POST['franchisee_id'] ?? 0);
    $invoiceNo = trim($_POST['invoice_no'] ?? '');
    $purchaseDate = trim($_POST['purchase_date'] ?? '');
    $note = trim($_POST['note'] ?? '');

    $lineProducts = $_POST['product_id'] ?? [];
    $lineQtys = $_POST['qty'] ?? [];
    $lineRates = $_POST['rate'] ?? [];

    $items = [];
    $count = max(count($lineProducts), count($lineQtys), count($lineRates));
    for ($i = 0; $i < $count; $i++) {
        $pid = (int) ($lineProducts[$i] ?? 0);
        $qty = (int) ($lineQtys[$i] ?? 0);
        $rate = (float) ($lineRates[$i] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $items[] = [
                'product_id' => $pid,
                'qty' => $qty,
                'rate' => $rate,
                'amount' => round($qty * $rate, 2),
            ];
        }
    }

    $result = franchise_save_purchase(
        $pdo,
        $franchiseeId,
        $purchaseDate,
        $invoiceNo,
        $note,
        $items,
        $franchise_role,
        $franchise_actor_id ?: null
    );

    if ($result['ok']) {
        flash('success', 'Purchase saved. Company stock debited and franchisee stock updated.');
        header('Location: franchisee-purchase-report.php?id=' . (int) $result['id']);
        exit;
    }
    $errors[] = $result['error'] ?? 'Could not save purchase.';
}

$recent = franchise_purchases($pdo);
$recent = array_slice($recent, 0, 12);

franchise_header();

$productOptionsHtml = '';
foreach ($products as $p) {
    $productOptionsHtml .= '<option value="' . (int) $p['id'] . '"'
        . ' data-stock="' . (int) $p['stock_qty'] . '"'
        . ' data-price="' . e(number_format((float) $p['price'], 2, '.', '')) . '">'
        . e($p['name'] . ($p['sku'] ? ' (' . $p['sku'] . ')' : ''))
        . ' — stock ' . (int) $p['stock_qty']
        . '</option>';
}
?>

<div class="panel">
    <div class="panel-header"><h2>New Franchisee Product Purchase</h2></div>
    <div class="panel-body">
        <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
        <?php if (!$franchisees): ?>
            <div class="alert alert-info">No active franchisees. <a href="franchisee-add.php">Add a franchisee</a> first.</div>
        <?php elseif (!$products): ?>
            <div class="alert alert-info">No active products. Add products in Product Management first.</div>
        <?php endif; ?>

        <form method="post" id="fpPurchaseForm">
            <div class="fp-meta">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Franchisee *</label>
                        <select name="franchisee_id" required <?= !$franchisees ? 'disabled' : '' ?>>
                            <option value="">— Select franchisee —</option>
                            <?php foreach ($franchisees as $f): ?>
                            <option value="<?= (int) $f['id'] ?>" <?= ((int) ($_POST['franchisee_id'] ?? 0) === (int) $f['id']) ? 'selected' : '' ?>>
                                <?= e($f['franchisee_code'] . ' — ' . $f['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Invoice No</label>
                        <input type="text" name="invoice_no" value="<?= e($_POST['invoice_no'] ?? '') ?>" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label>Purchase Date *</label>
                        <input type="date" name="purchase_date" value="<?= e($_POST['purchase_date'] ?? date('Y-m-d')) ?>" required>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Note</label>
                        <textarea name="note" rows="2" placeholder="Optional remark"><?= e($_POST['note'] ?? '') ?></textarea>
                    </div>
                </div>
                <p class="fp-meta-hint">Company inventory se stock debit hoga aur selected franchisee ke stock mein add hoga.</p>
            </div>

            <div class="spi-card is-franchise" id="spiCard">
                <div class="spi-head">
                    <div class="spi-head-left">
                        <span class="spi-head-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                        </span>
                        <div>
                            <h3>Line Items</h3>
                            <p>Product select karo — rate auto-fill, company stock dikhega. Amount auto calculate hoga.</p>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" id="spiAddRow" <?= !$products ? 'disabled' : '' ?>>+ Add Item</button>
                </div>

                <div class="spi-table-wrap">
                    <div class="spi-cols spi-cols-fr">
                        <span>#</span>
                        <span>Product</span>
                        <span>Stock</span>
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
                            $rowPid = (int) ($_POST['product_id'][$i] ?? 0);
                            $rowQty = (int) ($_POST['qty'][$i] ?? 0);
                            $rowRate = (float) ($_POST['rate'][$i] ?? 0);
                            $rowAmt = round($rowQty * $rowRate, 2);
                            $rowStock = '—';
                            foreach ($products as $p) {
                                if ((int) $p['id'] === $rowPid) {
                                    $rowStock = (string) (int) $p['stock_qty'];
                                    break;
                                }
                            }
                        ?>
                        <div class="spi-row spi-row-fr">
                            <div class="spi-no"><?= $i + 1 ?></div>
                            <div class="spi-field">
                                <select name="product_id[]" class="spi-product">
                                    <option value="">— Select Product —</option>
                                    <?php foreach ($products as $p): ?>
                                    <option value="<?= (int) $p['id'] ?>"
                                        data-stock="<?= (int) $p['stock_qty'] ?>"
                                        data-price="<?= e(number_format((float) $p['price'], 2, '.', '')) ?>"
                                        <?= $rowPid === (int) $p['id'] ? 'selected' : '' ?>>
                                        <?= e($p['name'] . ($p['sku'] ? ' (' . $p['sku'] . ')' : '')) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="spi-stock" data-fp-stock><?= e($rowStock) ?></div>
                            <div class="spi-field">
                                <input type="number" name="qty[]" class="spi-qty" min="0" value="<?= $rowQty ?>">
                            </div>
                            <div class="spi-field">
                                <input type="number" step="0.01" name="rate[]" class="spi-rate" min="0" value="<?= e((string) ($_POST['rate'][$i] ?? '0')) ?>">
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
                <div class="spi-row spi-row-fr">
                    <div class="spi-no">1</div>
                    <div class="spi-field">
                        <select name="product_id[]" class="spi-product">
                            <option value="">— Select Product —</option>
                            <?= $productOptionsHtml ?>
                        </select>
                    </div>
                    <div class="spi-stock" data-fp-stock>—</div>
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
                <button type="submit" class="btn btn-primary" <?= (!$franchisees || !$products) ? 'disabled' : '' ?>>Save Purchase</button>
                <a href="franchisee-purchase-report.php" class="btn btn-outline">View All Purchases</a>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap">
        <h2>Recent Purchases</h2>
        <a href="franchisee-purchase-report.php" class="btn btn-outline btn-sm">Full report</a>
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
            <?php if (!$recent): ?>
                <tr><td colspan="7">No purchases yet.</td></tr>
            <?php else: foreach ($recent as $r): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= e($r['purchase_date']) ?></td>
                    <td><strong><?= e($r['franchisee_code'] ?? '') ?></strong><br><small><?= e($r['franchisee_name'] ?? '') ?></small></td>
                    <td><?= e($r['invoice_no'] ?? '—') ?></td>
                    <td><?= currency((float) $r['total_amount']) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><a href="franchisee-purchase-report.php?id=<?= (int) $r['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var rows = document.getElementById('spiRows');
    if (!rows) return;

    function syncProduct(row) {
        var sel = row.querySelector('.spi-product');
        var stock = row.querySelector('[data-fp-stock]');
        var rate = row.querySelector('.spi-rate');
        if (!sel) return;
        var opt = sel.options[sel.selectedIndex];
        if (stock) stock.textContent = (opt && opt.dataset.stock) ? opt.dataset.stock : '—';
        if (rate && opt && opt.dataset.price) {
            rate.value = opt.dataset.price;
            rate.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    function bindProduct(row) {
        var sel = row.querySelector('.spi-product');
        if (!sel || sel.dataset.fpBound) return;
        sel.dataset.fpBound = '1';
        sel.addEventListener('change', function () { syncProduct(row); });
    }

    rows.querySelectorAll('.spi-row').forEach(bindProduct);

    var addBtn = document.getElementById('spiAddRow');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            setTimeout(function () {
                rows.querySelectorAll('.spi-row').forEach(bindProduct);
            }, 0);
        });
    }
})();
</script>
<?php franchise_footer(); ?>
