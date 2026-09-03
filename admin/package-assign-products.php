<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/package_products.php';

$pageTitle = 'Assign Product';
package_products_ensure_table($pdo);

$errors = [];
$selectedPackageId = (int) ($_POST['package_id'] ?? $_GET['package_id'] ?? 0);

$packages = $pdo->query("
    SELECT id, name, amount, status
    FROM packages
    ORDER BY amount ASC, name ASC
")->fetchAll();

$products = [];
try {
    $products = $pdo->query("
        SELECT id, name, sku, price, status
        FROM products
        WHERE status = 'active'
        ORDER BY name ASC
    ")->fetchAll();
} catch (Throwable $e) {
    $products = [];
    $errors[] = 'Products table not ready. Add products first from Product Management.';
}

$existingAssigned = $selectedPackageId > 0 ? package_products_list($pdo, $selectedPackageId) : [];
$assignmentLocked = count($existingAssigned) > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (client_packages_locked()) {
        flash('error', 'Packages cannot be changed right now.');
        header('Location: package-assign-products.php' . ($selectedPackageId ? '?package_id=' . $selectedPackageId : ''));
        exit;
    }
    $selectedPackageId = (int) ($_POST['package_id'] ?? 0);
    $existingAssigned = $selectedPackageId > 0 ? package_products_list($pdo, $selectedPackageId) : [];
    $assignmentLocked = count($existingAssigned) > 0;

    if ($selectedPackageId <= 0) {
        $errors[] = 'Please select a package.';
    } elseif ($assignmentLocked) {
        $errors[] = 'Products are already assigned to this package. Assignment cannot be changed.';
    } else {
        $productIds = $_POST['product_id'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $prices = $_POST['unit_price'] ?? [];

        if (!is_array($productIds)) {
            $productIds = [];
        }
        if (!is_array($qtys)) {
            $qtys = [];
        }
        if (!is_array($prices)) {
            $prices = [];
        }

        $rows = [];
        $n = max(count($productIds), count($qtys), count($prices));
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [
                'product_id' => (int) ($productIds[$i] ?? 0),
                'qty' => (int) ($qtys[$i] ?? 0),
                'unit_price' => (float) ($prices[$i] ?? 0),
            ];
        }

        $res = package_products_save($pdo, $selectedPackageId, $rows);
        if ($res['ok']) {
            $msg = $res['count'] > 0
                ? $res['count'] . ' product(s) assigned. Products total ' . number_format($res['products_total'], 2)
                    . ' · Package ' . number_format($res['package_amount'], 2)
                    . ' · Discount ' . number_format($res['discount_percent'], 2) . '%'
                : 'All products cleared from this package.';
            flash('success', $msg);
            log_activity('package_assign_products', 'Package #' . $selectedPackageId . ' → ' . $res['count'] . ' products');
            header('Location: package-assign-products.php?package_id=' . $selectedPackageId);
            exit;
        }
        $errors[] = $res['error'] ?? 'Save failed.';
    }
}

$packageAmount = 0.0;
$packageName = '';
foreach ($packages as $pkg) {
    if ((int) $pkg['id'] === $selectedPackageId) {
        $packageAmount = (float) $pkg['amount'];
        $packageName = (string) $pkg['name'];
        break;
    }
}

$assigned = $existingAssigned;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors && !$assignmentLocked) {
    // Rebuild rows from POST for redisplay (only when still editable)
    $assigned = [];
    $productIds = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    $n = is_array($productIds) ? count($productIds) : 0;
    for ($i = 0; $i < $n; $i++) {
        $pid = (int) ($productIds[$i] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $pname = '';
        foreach ($products as $p) {
            if ((int) $p['id'] === $pid) {
                $pname = (string) $p['name'];
                break;
            }
        }
        $assigned[] = [
            'product_id' => $pid,
            'product_name' => $pname,
            'qty' => (int) ($qtys[$i] ?? 1),
            'unit_price' => (float) ($prices[$i] ?? 0),
        ];
    }
}

$productsTotal = 0.0;
foreach ($assigned as $row) {
    $productsTotal += ((float) $row['unit_price']) * ((int) $row['qty']);
}
$discountPercent = 0.0;
if ($productsTotal > 0 && $packageAmount > 0 && $packageAmount < $productsTotal) {
    $discountPercent = (($productsTotal - $packageAmount) / $productsTotal) * 100;
}

$productJson = [];
foreach ($products as $p) {
    $productJson[] = [
        'id' => (int) $p['id'],
        'name' => (string) $p['name'],
        'sku' => (string) ($p['sku'] ?? ''),
        'price' => (float) $p['price'],
    ];
}
$packageJson = [];
foreach ($packages as $pkg) {
    $packageJson[] = [
        'id' => (int) $pkg['id'],
        'name' => (string) $pkg['name'],
        'amount' => (float) $pkg['amount'],
        'status' => (string) $pkg['status'],
    ];
}

require_once __DIR__ . '/../includes/header.php';
$flash = get_flash();
?>

<div class="stats-grid tpin-stats">
    <div class="stat-card accent">
        <div class="label">Package price</div>
        <div class="value" id="statPackagePrice"><?= $selectedPackageId > 0 ? currency($packageAmount) : '—' ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Products total</div>
        <div class="value" id="statProductsTotal"><?= currency($productsTotal) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Discount</div>
        <div class="value" id="statDiscount"><?= number_format($discountPercent, 2) ?>%</div>
    </div>
</div>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>Assign Product</h2>
            <p class="tpin-panel-sub">Attach products + quantity to a package and see auto discount vs package price</p>
        </div>
        <a href="packages.php" class="btn btn-outline btn-sm">Add Packages</a>
    </div>
    <div class="panel-body">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <div class="tpin-transfer-steps">
            <div class="tpin-step"><span>1</span> Select package</div>
            <div class="tpin-step"><span>2</span> Add products + qty</div>
            <div class="tpin-step"><span>3</span> Review total &amp; discount</div>
        </div>

        <form method="post" class="tpin-gen-form" id="pkgAssignForm">
            <div class="tpin-gen-grid">
                <div class="form-group">
                    <label for="package_id">Package *</label>
                    <select name="package_id" id="package_id" required>
                        <option value="">Select package</option>
                        <?php foreach ($packages as $pkg): ?>
                            <option
                                value="<?= (int) $pkg['id'] ?>"
                                data-amount="<?= e(number_format((float) $pkg['amount'], 2, '.', '')) ?>"
                                <?= $selectedPackageId === (int) $pkg['id'] ? 'selected' : '' ?>
                            >
                                <?= e($pkg['name']) ?> — <?= currency((float) $pkg['amount']) ?><?= ($pkg['status'] ?? '') !== 'active' ? ' (inactive)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="package_amount">Package price</label>
                    <input type="text" id="package_amount" value="<?= $selectedPackageId > 0 ? e(number_format($packageAmount, 2)) : '' ?>" readonly tabindex="-1" class="tpin-readonly" placeholder="Select package">
                </div>
            </div>

            <div class="pkg-assign-toolbar">
                <strong>Products in package</strong>
                <?php if (!$assignmentLocked): ?>
                    <button type="button" class="btn btn-outline btn-sm" id="addProductRow">+ Add product</button>
                <?php else: ?>
                    <span class="pkg-assign-locked-badge">Assignment locked</span>
                <?php endif; ?>
            </div>

            <?php if ($assignmentLocked): ?>
                <div class="pkg-assign-locked-note">
                    Products are already assigned to this package. Add / remove and save are disabled.
                </div>
            <?php endif; ?>

            <div class="table-wrap pkg-assign-table-wrap">
                <table class="data tpin-table" id="pkgProductTable">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Quantity</th>
                        <th>Line total</th>
                        <?php if (!$assignmentLocked): ?><th>Action</th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody id="pkgProductBody">
                    <?php if (!$assigned): ?>
                        <tr class="pkg-empty-row">
                            <td colspan="<?= $assignmentLocked ? 4 : 5 ?>" class="tpin-td-empty" style="text-align:center;padding:1.2rem;color:#64748b">
                                No products yet. Click “Add product”.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($assigned as $row):
                            $line = ((float) $row['unit_price']) * ((int) $row['qty']);
                        ?>
                        <tr class="pkg-product-row">
                            <td>
                                <?php if ($assignmentLocked): ?>
                                    <input type="text" class="tpin-readonly" value="<?= e((string) ($row['product_name'] ?? '')) ?>" readonly tabindex="-1">
                                <?php else: ?>
                                <select name="product_id[]" class="pkg-product-select" required>
                                    <option value="">Select product</option>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>" data-price="<?= e(number_format((float) $p['price'], 2, '.', '')) ?>" <?= (int) $row['product_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                                            <?= e($p['name']) ?><?= !empty($p['sku']) ? ' (' . e($p['sku']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="text" class="pkg-price-view tpin-readonly" value="<?= e(number_format((float) $row['unit_price'], 2)) ?>" readonly tabindex="-1">
                                <?php if (!$assignmentLocked): ?>
                                <input type="hidden" name="unit_price[]" class="pkg-unit-price" value="<?= e(number_format((float) $row['unit_price'], 2, '.', '')) ?>">
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($assignmentLocked): ?>
                                    <input type="text" class="tpin-readonly" value="<?= (int) $row['qty'] ?>" readonly tabindex="-1">
                                <?php else: ?>
                                <input type="number" name="qty[]" class="pkg-qty" min="1" max="9999" value="<?= (int) $row['qty'] ?>" required>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="text" class="pkg-line-total tpin-readonly" value="<?= e(number_format($line, 2)) ?>" readonly tabindex="-1">
                            </td>
                            <?php if (!$assignmentLocked): ?>
                            <td>
                                <button type="button" class="btn btn-outline btn-sm pkg-remove-row">Remove</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pkg-summary">
                <div class="pkg-summary-item">
                    <span>Package price</span>
                    <strong id="sumPackage"><?= $selectedPackageId > 0 ? e(number_format($packageAmount, 2)) : '0.00' ?></strong>
                </div>
                <div class="pkg-summary-item">
                    <span>Total products price</span>
                    <strong id="sumProducts"><?= e(number_format($productsTotal, 2)) ?></strong>
                </div>
                <div class="pkg-summary-item is-discount">
                    <span>Discount</span>
                    <strong id="sumDiscount"><?= e(number_format($discountPercent, 2)) ?>%</strong>
                </div>
            </div>

            <?php if (!$assignmentLocked): ?>
            <div class="tpin-gen-actions">
                <button type="submit" class="btn btn-primary">Save assignment</button>
                <a href="package-assign-products.php" class="btn btn-outline">Reset</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<template id="pkgRowTemplate">
    <tr class="pkg-product-row">
        <td>
            <select name="product_id[]" class="pkg-product-select" required>
                <option value="">Select product</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" data-price="<?= e(number_format((float) $p['price'], 2, '.', '')) ?>">
                        <?= e($p['name']) ?><?= !empty($p['sku']) ? ' (' . e($p['sku']) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="text" class="pkg-price-view tpin-readonly" value="" readonly tabindex="-1" placeholder="0.00">
            <input type="hidden" name="unit_price[]" class="pkg-unit-price" value="0">
        </td>
        <td>
            <input type="number" name="qty[]" class="pkg-qty" min="1" max="9999" value="1" required>
        </td>
        <td>
            <input type="text" class="pkg-line-total tpin-readonly" value="0.00" readonly tabindex="-1">
        </td>
        <td>
            <button type="button" class="btn btn-outline btn-sm pkg-remove-row">Remove</button>
        </td>
    </tr>
</template>

<style>
.tpin-readonly {
    background: #f1f5f9 !important;
    color: #0f172a !important;
    cursor: default !important;
}
.tpin-readonly:focus {
    box-shadow: none !important;
    border-color: #e2e8f0 !important;
}
.tpin-transfer-steps {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .65rem;
    margin-bottom: 1.25rem;
}
.tpin-step {
    display: flex;
    align-items: center;
    gap: .55rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: .65rem .75rem;
    font-size: .82rem;
    font-weight: 600;
    color: #334155;
}
.tpin-step span {
    width: 22px;
    height: 22px;
    border-radius: 999px;
    display: inline-grid;
    place-items: center;
    background: #0f172a;
    color: #fff;
    font-size: .75rem;
    flex-shrink: 0;
}
.pkg-assign-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    margin: .35rem 0 .75rem;
}
.pkg-assign-toolbar strong { font-size: .92rem; color: #0f172a; }
.pkg-assign-locked-badge {
    display: inline-flex;
    align-items: center;
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #b45309;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 999px;
    padding: .35rem .7rem;
}
.pkg-assign-locked-note {
    margin-bottom: .85rem;
    padding: .75rem .9rem;
    border-radius: 10px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    font-size: .875rem;
    font-weight: 500;
}

#pkgProductTable {
    border-collapse: separate;
    border-spacing: 0;
}
#pkgProductTable thead th {
    font-size: .72rem;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #64748b;
    font-weight: 700;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: .75rem .85rem;
    white-space: nowrap;
}
#pkgProductTable tbody td {
    padding: .7rem .85rem;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
}
#pkgProductTable thead th:nth-child(1),
#pkgProductTable tbody td:nth-child(1) { min-width: 240px; width: 42%; }
#pkgProductTable thead th:nth-child(2),
#pkgProductTable tbody td:nth-child(2) { width: 140px; }
#pkgProductTable thead th:nth-child(3),
#pkgProductTable tbody td:nth-child(3) { width: 120px; }
#pkgProductTable thead th:nth-child(4),
#pkgProductTable tbody td:nth-child(4) { width: 140px; }
#pkgProductTable thead th:nth-child(5),
#pkgProductTable tbody td:nth-child(5) { width: 100px; }
.pkg-product-row select,
.pkg-product-row input[type="number"],
.pkg-product-row input[type="text"] {
    width: 100%;
    min-height: 42px;
    box-sizing: border-box;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #fff;
    color: #0f172a;
    font: inherit;
    font-size: .875rem;
    font-weight: 500;
    padding: .55rem .75rem;
    outline: none;
    box-shadow: none;
    appearance: none;
    -webkit-appearance: none;
    transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
}
.pkg-product-row select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .75rem center;
    padding-right: 2rem;
    cursor: pointer;
}
.pkg-product-row select:focus,
.pkg-product-row input[type="number"]:focus,
.pkg-product-row input[type="text"]:focus {
    border-color: #fda4af;
    box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.12);
}
.pkg-product-row input.tpin-readonly,
.pkg-product-row input.pkg-price-view,
.pkg-product-row input.pkg-line-total {
    background: #f1f5f9 !important;
    color: #0f172a !important;
    border-color: #e2e8f0 !important;
    cursor: default !important;
    box-shadow: none !important;
}
.pkg-product-row .pkg-qty {
    max-width: 110px;
}
.pkg-product-row .pkg-remove-row {
    white-space: nowrap;
}
.pkg-summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .75rem;
    margin-top: 1rem;
}
.pkg-summary-item {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #f8fafc;
    padding: .85rem 1rem;
}
.pkg-summary-item span {
    display: block;
    font-size: .78rem;
    color: #64748b;
    margin-bottom: .25rem;
}
.pkg-summary-item strong {
    font-size: 1.1rem;
    color: #0f172a;
}
.pkg-summary-item.is-discount {
    background: #fff1f3;
    border-color: #fecdd3;
}
.pkg-summary-item.is-discount strong { color: #e11d48; }
.tpin-gen-actions { gap: .65rem; align-items: center; }
@media (max-width: 800px) {
    .tpin-transfer-steps,
    .pkg-summary { grid-template-columns: 1fr; }
    .pkg-product-row .pkg-qty { max-width: none; }
}
</style>

<script>
(function () {
    const assignmentLocked = <?= $assignmentLocked ? 'true' : 'false' ?>;
    const products = <?= json_encode($productJson, JSON_UNESCAPED_UNICODE) ?>;
    const packages = <?= json_encode($packageJson, JSON_UNESCAPED_UNICODE) ?>;
    const pkgSelect = document.getElementById('package_id');
    const pkgAmountEl = document.getElementById('package_amount');
    const body = document.getElementById('pkgProductBody');
    const addBtn = document.getElementById('addProductRow');
    const tpl = document.getElementById('pkgRowTemplate');
    const currencyPrefix = <?= json_encode(html_entity_decode('&#8377;', ENT_QUOTES, 'UTF-8')) ?>;

    function fmt(n) {
        const x = Number(n) || 0;
        return x.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function clearEmptyRow() {
        if (!body) return;
        const empty = body.querySelector('.pkg-empty-row');
        if (empty) empty.remove();
    }

    function ensureEmptyState() {
        if (!body) return;
        if (body.querySelectorAll('.pkg-product-row').length === 0) {
            body.innerHTML = '<tr class="pkg-empty-row"><td colspan="5" style="text-align:center;padding:1.2rem;color:#64748b">No products yet. Click “Add product”.</td></tr>';
        }
    }

    function packageAmount() {
        const opt = pkgSelect && pkgSelect.options[pkgSelect.selectedIndex];
        return opt ? parseFloat(opt.getAttribute('data-amount') || '0') : 0;
    }

    function syncPackage() {
        const amt = packageAmount();
        if (pkgAmountEl) pkgAmountEl.value = amt > 0 ? fmt(amt) : '';
        const stat = document.getElementById('statPackagePrice');
        if (stat) stat.textContent = amt > 0 ? (currencyPrefix + fmt(amt)) : '—';
        const sumPkg = document.getElementById('sumPackage');
        if (sumPkg) sumPkg.textContent = fmt(amt);
        if (!assignmentLocked) recalc();
    }

    function syncRow(row) {
        const sel = row.querySelector('.pkg-product-select');
        const priceView = row.querySelector('.pkg-price-view');
        const unitHidden = row.querySelector('.pkg-unit-price');
        const qtyEl = row.querySelector('.pkg-qty');
        const lineEl = row.querySelector('.pkg-line-total');
        const opt = sel && sel.options[sel.selectedIndex];
        const price = opt ? parseFloat(opt.getAttribute('data-price') || '0') : 0;
        const qty = Math.max(1, parseInt(qtyEl && qtyEl.value ? qtyEl.value : '1', 10) || 1);
        if (priceView) priceView.value = price > 0 ? fmt(price) : '';
        if (unitHidden) unitHidden.value = price > 0 ? price.toFixed(2) : '0';
        if (lineEl) lineEl.value = fmt(price * qty);
    }

    function recalc() {
        if (assignmentLocked || !body) return;
        let total = 0;
        body.querySelectorAll('.pkg-product-row').forEach((row) => {
            syncRow(row);
            const unit = parseFloat((row.querySelector('.pkg-unit-price') || {}).value || '0');
            const qty = Math.max(1, parseInt((row.querySelector('.pkg-qty') || {}).value || '1', 10) || 1);
            total += unit * qty;
        });
        const pkgAmt = packageAmount();
        let discount = 0;
        if (total > 0 && pkgAmt > 0 && pkgAmt < total) {
            discount = ((total - pkgAmt) / total) * 100;
        }
        const sumProducts = document.getElementById('sumProducts');
        const sumDiscount = document.getElementById('sumDiscount');
        const statProducts = document.getElementById('statProductsTotal');
        const statDiscount = document.getElementById('statDiscount');
        if (sumProducts) sumProducts.textContent = fmt(total);
        if (sumDiscount) sumDiscount.textContent = fmt(discount) + '%';
        if (statProducts) statProducts.textContent = currencyPrefix + fmt(total);
        if (statDiscount) statDiscount.textContent = fmt(discount) + '%';
    }

    function bindRow(row) {
        if (assignmentLocked) return;
        const sel = row.querySelector('.pkg-product-select');
        const qty = row.querySelector('.pkg-qty');
        const remove = row.querySelector('.pkg-remove-row');
        if (sel) sel.addEventListener('change', () => { syncRow(row); recalc(); });
        if (qty) {
            qty.addEventListener('input', () => { syncRow(row); recalc(); });
            qty.addEventListener('change', () => { syncRow(row); recalc(); });
        }
        if (remove) {
            remove.addEventListener('click', () => {
                row.remove();
                ensureEmptyState();
                recalc();
            });
        }
    }

    function addRow() {
        if (assignmentLocked || !tpl || !body) return;
        clearEmptyRow();
        const node = tpl.content.cloneNode(true);
        const row = node.querySelector('.pkg-product-row');
        body.appendChild(node);
        bindRow(row);
        recalc();
    }

    if (pkgSelect) {
        pkgSelect.addEventListener('change', () => {
            const id = pkgSelect.value;
            if (id) {
                window.location = 'package-assign-products.php?package_id=' + encodeURIComponent(id);
            } else {
                window.location = 'package-assign-products.php';
            }
        });
    }
    if (addBtn && !assignmentLocked) addBtn.addEventListener('click', addRow);
    if (!assignmentLocked) {
        body.querySelectorAll('.pkg-product-row').forEach(bindRow);
        syncPackage();
    } else {
        syncPackage();
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
