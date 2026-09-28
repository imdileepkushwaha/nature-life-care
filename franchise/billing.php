<?php
/**
 * Franchise Member Billing / Sell Products
 */
require_once __DIR__ . '/includes/auth.php';

$currentFranchise = franchise_require_auth($pdo);
$frId = (int) $currentFranchise['id'];

// AJAX Member Lookup
if (isset($_GET['ajax']) && $_GET['ajax'] === 'member') {
    header('Content-Type: application/json; charset=utf-8');
    $query = strtoupper(trim((string) ($_GET['code'] ?? '')));
    if ($query === '') {
        echo json_encode(['ok' => false, 'error' => 'Enter Member ID.']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id, member_id, full_name, phone, status FROM members WHERE member_id = ? OR phone = ? LIMIT 1');
    $stmt->execute([$query, $query]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        echo json_encode(['ok' => false, 'error' => 'Member not found.']);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'id' => (int) $m['id'],
        'member_id' => $m['member_id'],
        'name' => $m['full_name'],
        'phone' => $m['phone'] ?? '',
        'status' => $m['status'],
    ]);
    exit;
}

$pageTitle = 'Issue Bill to Member';
require_once __DIR__ . '/includes/header.php';

// Available products in franchise stock
$stmtStock = $pdo->prepare('
    SELECT s.product_id, s.qty, pr.name, pr.sku, pr.price, pr.bv AS bv_points
    FROM franchisee_stock s
    JOIN products pr ON pr.id = s.product_id
    WHERE s.franchisee_id = ? AND s.qty > 0
    ORDER BY pr.name ASC
');
$stmtStock->execute([$frId]);
$availableStock = $stmtStock->fetchAll(PDO::FETCH_ASSOC);

$preselectedPid = (int) ($_GET['product_id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $memberCode = strtoupper(trim($_POST['member_code'] ?? ''));
    $saleDate = trim($_POST['sale_date'] ?? date('Y-m-d'));
    $paymentMode = trim($_POST['payment_mode'] ?? 'cash');
    $note = trim($_POST['note'] ?? '');

    // Validate Member
    $memStmt = $pdo->prepare('SELECT id, member_id, full_name, status FROM members WHERE member_id = ? LIMIT 1');
    $memStmt->execute([$memberCode]);
    $member = $memStmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        $errors[] = 'Valid Member ID is required.';
    } elseif (($member['status'] ?? '') === 'blocked') {
        $errors[] = 'Member account is blocked.';
    }

    $rawProducts = $_POST['products'] ?? [];
    $rawQtys = $_POST['qtys'] ?? [];

    $items = [];
    if (is_array($rawProducts)) {
        // Stock lookup map
        $stockMap = [];
        foreach ($availableStock as $s) {
            $stockMap[(int) $s['product_id']] = $s;
        }

        foreach ($rawProducts as $idx => $pid) {
            $pid = (int) $pid;
            $qty = (int) ($rawQtys[$idx] ?? 0);

            if ($pid <= 0 || $qty <= 0) {
                continue;
            }

            if (!isset($stockMap[$pid])) {
                $errors[] = "Selected product #$pid is not available in your stock.";
                continue;
            }

            $prod = $stockMap[$pid];
            if ($qty > (int) $prod['qty']) {
                $errors[] = "Requested quantity for {$prod['name']} ($qty) exceeds available stock ({$prod['qty']}).";
                continue;
            }

            $rate = (float) $prod['price'];
            $items[] = [
                'product_id' => $pid,
                'qty' => $qty,
                'rate' => $rate,
                'amount' => round($qty * $rate, 2),
            ];
        }
    }

    if (empty($items)) {
        $errors[] = 'Add at least one product with valid quantity to the bill.';
    }

    if (empty($errors) && $member) {
        $res = franchise_record_sale($pdo, $frId, (int) $member['id'], $saleDate, $items, $paymentMode, $note);
        if ($res['ok']) {
            flash('success', "Bill #{$res['bill_no']} created successfully.");
            header('Location: invoice.php?id=' . (int) $res['id']);
            exit;
        } else {
            $errors[] = $res['error'] ?? 'Could not create bill. Please try again.';
        }
    }
}
?>

<div class="panel">
    <div class="panel-header">
        <h2>Issue Bill / Sell Products to Member</h2>
        <p style="color:#64748b;font-size:0.88rem;margin-top:0.25rem">
            Select member, pick products from your available stock, and generate sales receipt.
        </p>
    </div>

    <div class="panel-body">
    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul style="padding-left:1.2rem;margin:0">
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (empty($availableStock)): ?>
        <div class="alert alert-danger">
            You currently have 0 products in stock. You must receive stock from the admin before you can bill to members.
            <a href="purchases.php" style="margin-left:0.5rem;text-decoration:underline">Check purchases</a>
        </div>
    <?php else: ?>

    <form method="post" action="billing.php" id="billForm">
        <!-- Step 1: Member Selection -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:1.25rem;margin-bottom:1.5rem">
            <h3 style="font-size:1rem;margin-bottom:1rem;color:#0f172a">1. Member Information</h3>
            <div class="form-grid-3">
                <div class="form-group" style="margin-bottom:0">
                    <label>Member ID *</label>
                    <div style="display:flex;gap:0.4rem">
                        <input type="text" name="member_code" id="memCodeInput" class="form-control" value="<?= e($_POST['member_code'] ?? '') ?>" placeholder="e.g. MEM1001" required style="text-transform:uppercase">
                        <button type="button" id="lookupMemberBtn" class="btn btn-outline">Check</button>
                    </div>
                    <span id="memHint" style="font-size:0.8rem;display:block;margin-top:0.3rem;color:#64748b">Type Member ID to verify</span>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label>Member Name</label>
                    <input type="text" id="memNameDisplay" class="form-control" readonly placeholder="Verified automatically" style="background:#f1f5f9">
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label>Member Mobile</label>
                    <input type="text" id="memPhoneDisplay" class="form-control" readonly placeholder="Verified automatically" style="background:#f1f5f9">
                </div>
            </div>
        </div>

        <!-- Step 2: Line Items -->
        <div style="margin-bottom:1.5rem">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem">
                <h3 style="font-size:1rem;color:#0f172a">2. Products to Sell</h3>
                <button type="button" id="addRowBtn" class="btn btn-outline btn-sm">+ Add Item</button>
            </div>

            <div class="table-wrap">
                <table class="bill-line-table" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width:45%">Product (Available Stock)</th>
                            <th style="width:18%">Unit Price</th>
                            <th style="width:18%">Quantity</th>
                            <th style="width:15%">Line Total</th>
                            <th style="width:4%"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        <tr class="item-row">
                            <td>
                                <select name="products[]" class="form-control prod-select" required>
                                    <option value="">-- Choose Product --</option>
                                    <?php foreach ($availableStock as $s): ?>
                                        <option value="<?= (int) $s['product_id'] ?>" data-price="<?= (float) $s['price'] ?>" data-max="<?= (int) $s['qty'] ?>" <?= ($preselectedPid === (int) $s['product_id']) ? 'selected' : '' ?>>
                                            <?= e($s['name']) ?> (Stock: <?= (int) $s['qty'] ?>) - ₹<?= number_format((float) $s['price'], 2) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" class="form-control prod-price" readonly value="0.00" style="background:#f1f5f9">
                            </td>
                            <td>
                                <input type="number" name="qtys[]" class="form-control prod-qty" min="1" max="1" value="1" required>
                            </td>
                            <td>
                                <input type="text" class="form-control prod-amount" readonly value="0.00" style="background:#f1f5f9;font-weight:600">
                            </td>
                            <td style="text-align:center">
                                <button type="button" class="btn btn-outline btn-sm remove-row-btn" title="Remove" style="color:#ef4444;border-color:#fecaca">✕</button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="bill-totals-row">
                            <td colspan="2" style="text-align:right">Total Items: <span id="totalItemsCount">1</span></td>
                            <td style="text-align:right">Grand Total:</td>
                            <td colspan="2"><span id="grandTotalDisplay" style="color:#0284c7;font-size:1.15rem">₹0.00</span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Step 3: Payment & Date -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:1.25rem;margin-bottom:1.75rem">
            <h3 style="font-size:1rem;margin-bottom:1rem;color:#0f172a">3. Payment & Details</h3>
            <div class="form-grid-3">
                <div class="form-group" style="margin-bottom:0">
                    <label>Billing Date *</label>
                    <input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label>Payment Mode *</label>
                    <select name="payment_mode" class="form-control" required>
                        <option value="cash">Cash</option>
                        <option value="upi">UPI / Online</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label>Remarks / Notes</label>
                    <input type="text" name="note" class="form-control" placeholder="Optional reference or remarks">
                </div>
            </div>
        </div>

        <div style="display:flex;gap:1rem;align-items:center">
            <button type="submit" class="btn btn-primary" style="padding:0.75rem 1.75rem;font-size:1rem">
                Confirm & Issue Bill
            </button>
            <a href="index.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
    </div>
</div>

<template id="rowTemplate">
    <tr class="item-row">
        <td>
            <select name="products[]" class="form-control prod-select" required>
                <option value="">-- Choose Product --</option>
                <?php foreach ($availableStock as $s): ?>
                    <option value="<?= (int) $s['product_id'] ?>" data-price="<?= (float) $s['price'] ?>" data-max="<?= (int) $s['qty'] ?>">
                        <?= e($s['name']) ?> (Stock: <?= (int) $s['qty'] ?>) - ₹<?= number_format((float) $s['price'], 2) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="text" class="form-control prod-price" readonly value="0.00" style="background:#f1f5f9">
        </td>
        <td>
            <input type="number" name="qtys[]" class="form-control prod-qty" min="1" max="1" value="1" required>
        </td>
        <td>
            <input type="text" class="form-control prod-amount" readonly value="0.00" style="background:#f1f5f9;font-weight:600">
        </td>
        <td style="text-align:center">
            <button type="button" class="btn btn-outline btn-sm remove-row-btn" title="Remove" style="color:#ef4444;border-color:#fecaca">✕</button>
        </td>
    </tr>
</template>

<script>
// Member live lookup
var memInput = document.getElementById('memCodeInput');
var memName = document.getElementById('memNameDisplay');
var memPhone = document.getElementById('memPhoneDisplay');
var memHint = document.getElementById('memHint');
var lookupBtn = document.getElementById('lookupMemberBtn');
var lookupTimer = null;

function checkMember() {
    var val = (memInput.value || '').trim();
    if (!val) {
        memName.value = '';
        memPhone.value = '';
        memHint.textContent = 'Type Member ID to verify';
        memHint.style.color = '#64748b';
        return;
    }
    memHint.textContent = 'Looking up member...';
    memHint.style.color = '#0284c7';

    fetch('billing.php?ajax=member&code=' + encodeURIComponent(val))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && d.ok) {
                memName.value = d.name;
                memPhone.value = d.phone;
                memHint.textContent = 'Verified: ' + d.name + ' (' + d.status.toUpperCase() + ')';
                memHint.style.color = d.status === 'active' ? '#15803d' : '#b45309';
            } else {
                memName.value = '';
                memPhone.value = '';
                memHint.textContent = (d && d.error) ? d.error : 'Member not found';
                memHint.style.color = '#dc2626';
            }
        })
        .catch(function () {
            memHint.textContent = 'Error verifying member';
            memHint.style.color = '#dc2626';
        });
}

if (lookupBtn && memInput) {
    lookupBtn.addEventListener('click', checkMember);
    memInput.addEventListener('blur', checkMember);
    memInput.addEventListener('input', function () {
        clearTimeout(lookupTimer);
        lookupTimer = setTimeout(checkMember, 500);
    });
    if (memInput.value.trim()) {
        checkMember();
    }
}

// Line Items calculation
var itemsBody = document.getElementById('itemsBody');
var addRowBtn = document.getElementById('addRowBtn');
var rowTemplate = document.getElementById('rowTemplate');

function updateTotals() {
    var rows = document.querySelectorAll('.item-row');
    var grandTotal = 0;
    var totalItems = 0;

    rows.forEach(function (row) {
        var sel = row.querySelector('.prod-select');
        var opt = sel.options[sel.selectedIndex];
        var priceInput = row.querySelector('.prod-price');
        var qtyInput = row.querySelector('.prod-qty');
        var amtInput = row.querySelector('.prod-amount');

        if (opt && opt.value) {
            var price = parseFloat(opt.getAttribute('data-price')) || 0;
            var maxStock = parseInt(opt.getAttribute('data-max'), 10) || 1;
            qtyInput.max = maxStock;
            if (parseInt(qtyInput.value, 10) > maxStock) {
                qtyInput.value = maxStock;
            }
            priceInput.value = price.toFixed(2);
            var qty = parseInt(qtyInput.value, 10) || 0;
            var lineTotal = price * qty;
            amtInput.value = lineTotal.toFixed(2);
            grandTotal += lineTotal;
            totalItems += qty;
        } else {
            priceInput.value = '0.00';
            amtInput.value = '0.00';
        }
    });

    document.getElementById('grandTotalDisplay').textContent = '₹' + grandTotal.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('totalItemsCount').textContent = totalItems;
}

if (itemsBody) {
    itemsBody.addEventListener('change', function (e) {
        if (e.target.classList.contains('prod-select') || e.target.classList.contains('prod-qty')) {
            updateTotals();
        }
    });
    itemsBody.addEventListener('input', function (e) {
        if (e.target.classList.contains('prod-qty')) {
            updateTotals();
        }
    });
    itemsBody.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-row-btn')) {
            var rows = document.querySelectorAll('.item-row');
            if (rows.length > 1) {
                e.target.closest('.item-row').remove();
                updateTotals();
            } else {
                alert('A bill must contain at least one item.');
            }
        }
    });

    if (addRowBtn && rowTemplate) {
        addRowBtn.addEventListener('click', function () {
            var clone = rowTemplate.content.cloneNode(true);
            itemsBody.appendChild(clone);
            updateTotals();
        });
    }

    updateTotals();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
