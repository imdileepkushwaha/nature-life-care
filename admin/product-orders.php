<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/product_orders.php';
$pageTitle = 'Product Orders';

product_orders_ensure_tables($pdo);

$viewId = (int) ($_GET['id'] ?? 0);
$slipId = (int) ($_GET['slip'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_delivery') {
    $oid = (int) ($_POST['order_id'] ?? 0);
    $res = product_orders_admin_update_fulfillment($pdo, $oid, [
        'delivery_status' => (string) ($_POST['delivery_status'] ?? 'pending'),
        'courier_name' => (string) ($_POST['courier_name'] ?? ''),
        'tracking_no' => (string) ($_POST['tracking_no'] ?? ''),
        'delivery_note' => (string) ($_POST['delivery_note'] ?? ''),
    ]);
    if ($res['ok']) {
        flash('success', 'Delivery status updated for order #' . $oid . '.');
    } else {
        flash('error', $res['error'] ?? 'Update failed.');
    }
    header('Location: product-orders.php?id=' . $oid);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$delivery = trim((string) ($_GET['delivery'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$company = setting('company_name', 'Binary MLM');
$supportPhone = setting('contact_phone', '');
$supportEmail = setting('support_email', setting('contact_email', ''));
$signatureUrl = company_signature_url();

$slipOrder = null;
$slipItems = [];
$slipMember = [];
if ($slipId > 0) {
    $slipOrder = product_order_get($pdo, $slipId);
    if ($slipOrder) {
        $slipItems = product_order_items($pdo, $slipId);
        $mem = $pdo->prepare('SELECT member_id, full_name, username, phone, email FROM members WHERE id = ? LIMIT 1');
        $mem->execute([(int) $slipOrder['member_id']]);
        $slipMember = $mem->fetch() ?: [];
        $pageTitle = 'Address Slip · ' . ($slipOrder['invoice_no'] ?? '');
    }
}

$viewOrder = null;
$viewItems = [];
if ($viewId > 0 && $slipId < 1) {
    $viewOrder = product_order_get($pdo, $viewId);
    if ($viewOrder) {
        $viewItems = product_order_items($pdo, $viewId);
        $mem = $pdo->prepare('SELECT member_id, full_name, username, phone, email FROM members WHERE id = ? LIMIT 1');
        $mem->execute([(int) $viewOrder['member_id']]);
        $viewOrder['_member'] = $mem->fetch() ?: [];
    }
}

$total = product_orders_admin_count($pdo, $q, $status, $delivery);
$totalPages = max(1, (int) ceil($total / $perPage));
$rows = product_orders_admin_list($pdo, $perPage, $offset, $q, $status, $delivery);
$stats = product_orders_admin_stats($pdo);
$deliveryMap = product_delivery_statuses();
require_once __DIR__ . '/../includes/header.php';

if ($slipOrder):
    $cityLine = trim(implode(', ', array_filter([
        (string) ($slipOrder['shipping_city'] ?? ''),
        (string) ($slipOrder['shipping_state'] ?? ''),
    ])));
    if (!empty($slipOrder['shipping_pincode'])) {
        $cityLine = trim($cityLine . ' - ' . $slipOrder['shipping_pincode'], ' -');
    }
    $qtyTotal = 0;
    foreach ($slipItems as $it) {
        $qtyTotal += (int) ($it['qty'] ?? 0);
    }
?>
<style>
.po-slip-wrap { max-width: 720px; margin: 0 auto 1.5rem; }
.po-slip-actions { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1rem; }
.po-slip {
    background:#fff;
    border:2px solid #0f172a;
    border-radius:8px;
    overflow:hidden;
    box-shadow:0 10px 28px rgba(15,23,42,.08);
}
.po-slip-bar {
    background:#0f172a;
    color:#fff;
    padding:0.65rem 0.9rem;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:0.75rem;
    flex-wrap:wrap;
}
.po-slip-bar strong { font-size:1rem; letter-spacing:0.04em; text-transform:uppercase; }
.po-slip-bar span { font-size:0.82rem; opacity:.9; }
.po-slip-body { padding:1rem 1.1rem 1.15rem; }
.po-slip-fromto {
    display:grid;
    grid-template-columns:1fr 1.4fr;
    gap:0.85rem;
    margin-bottom:0.9rem;
}
.po-slip-box {
    border:1.5px solid #cbd5e1;
    border-radius:6px;
    padding:0.75rem 0.85rem;
    min-height:120px;
}
.po-slip-box.is-to {
    border-width:2.5px;
    border-color:#0f172a;
    background:#f8fafc;
}
.po-slip-box .lbl {
    display:block;
    font-size:0.68rem;
    font-weight:800;
    letter-spacing:0.08em;
    text-transform:uppercase;
    color:#64748b;
    margin-bottom:0.35rem;
}
.po-slip-box.is-to .lbl { color:#0f172a; }
.po-slip-box .name {
    display:block;
    font-size:1.2rem;
    font-weight:800;
    color:#0f172a;
    line-height:1.25;
    margin-bottom:0.35rem;
}
.po-slip-box p { margin:0.15rem 0; color:#334155; font-size:0.95rem; line-height:1.45; }
.po-slip-box .phone {
    margin-top:0.45rem;
    font-size:1.05rem;
    font-weight:800;
    color:#0f172a;
}
.po-slip-meta {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:0.55rem;
    margin-bottom:0.85rem;
}
.po-slip-meta div {
    border:1px solid #e2e8f0;
    border-radius:6px;
    padding:0.5rem 0.65rem;
}
.po-slip-meta span {
    display:block;
    font-size:0.65rem;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:0.05em;
    color:#94a3b8;
}
.po-slip-meta strong {
    display:block;
    margin-top:0.15rem;
    font-size:0.88rem;
    color:#0f172a;
    word-break:break-word;
}
.po-slip-items {
    width:100%;
    border-collapse:collapse;
    font-size:0.82rem;
}
.po-slip-items th,
.po-slip-items td {
    border-bottom:1px solid #e2e8f0;
    padding:0.4rem 0.35rem;
    text-align:left;
}
.po-slip-items th {
    background:#f1f5f9;
    font-size:0.68rem;
    text-transform:uppercase;
    letter-spacing:0.04em;
    color:#64748b;
}
.po-slip-foot {
    margin-top:0.75rem;
    padding-top:0.55rem;
    border-top:1px dashed #cbd5e1;
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    gap:0.75rem;
    flex-wrap:wrap;
    font-size:0.78rem;
    color:#64748b;
}
.po-slip-foot strong { color:#0f172a; }
.po-slip-sign {
    text-align:right;
    min-width:120px;
}
.po-slip-sign img {
    display:block;
    max-width:130px;
    max-height:48px;
    margin:0 0 0.2rem auto;
    object-fit:contain;
}
.po-slip-sign span {
    display:block;
    font-size:0.65rem;
    text-transform:uppercase;
    letter-spacing:0.05em;
    color:#94a3b8;
}
.po-slip-sign strong {
    display:block;
    margin-top:0.1rem;
    font-size:0.78rem;
}
@media (max-width:640px) {
    .po-slip-fromto, .po-slip-meta { grid-template-columns:1fr; }
}
@media print {
    @page { size: A6 portrait; margin: 6mm; }
    body { background:#fff !important; }
    .sidebar, .topbar, .flash, .alert,
    .page-banner, .breadcrumbs, .stats-grid, .panel,
    .po-slip-actions, .no-print { display:none !important; }
    .main, .content, .page-content, main, .wrapper {
        margin:0 !important;
        padding:0 !important;
        width:100% !important;
        max-width:none !important;
    }
    .po-slip-wrap { max-width:none; margin:0; }
    .po-slip {
        box-shadow:none;
        border-radius:0;
        border-width:2px;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }
    .po-slip-bar {
        background:#0f172a !important;
        color:#fff !important;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }
}
</style>

<div class="po-slip-wrap">
    <div class="po-slip-actions no-print">
        <button type="button" class="btn btn-primary" onclick="window.print()">Print Address Slip</button>
        <a href="product-orders.php?id=<?= (int) $slipOrder['id'] ?>" class="btn btn-outline">Back to order</a>
        <a href="product-orders.php" class="btn btn-outline">All orders</a>
    </div>

    <div class="po-slip" id="poAddressSlip">
        <div class="po-slip-bar">
            <strong>Shipping Address Slip</strong>
            <span><?= e((string) $slipOrder['invoice_no']) ?></span>
        </div>
        <div class="po-slip-body">
            <div class="po-slip-fromto">
                <div class="po-slip-box">
                    <span class="lbl">From</span>
                    <span class="name"><?= e($company) ?></span>
                    <?php if ($supportPhone !== ''): ?><p><?= e($supportPhone) ?></p><?php endif; ?>
                    <?php if ($supportEmail !== ''): ?><p><?= e($supportEmail) ?></p><?php endif; ?>
                </div>
                <div class="po-slip-box is-to">
                    <span class="lbl">Deliver To / Ship To</span>
                    <span class="name"><?= e((string) ($slipOrder['shipping_name'] ?? ($slipMember['full_name'] ?? ''))) ?></span>
                    <p><?= nl2br(e((string) ($slipOrder['shipping_address'] ?? ''))) ?></p>
                    <?php if ($cityLine !== ''): ?><p><strong><?= e($cityLine) ?></strong></p><?php endif; ?>
                    <?php if (!empty($slipOrder['shipping_phone'])): ?>
                        <div class="phone">☎ <?= e((string) $slipOrder['shipping_phone']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="po-slip-meta">
                <div>
                    <span>Invoice</span>
                    <strong><?= e((string) $slipOrder['invoice_no']) ?></strong>
                </div>
                <div>
                    <span>Member</span>
                    <strong><?= e((string) ($slipMember['member_id'] ?? '—')) ?></strong>
                </div>
                <div>
                    <span>Qty / Amount</span>
                    <strong><?= (int) $qtyTotal ?> pcs · <?= currency((float) $slipOrder['total_amount']) ?></strong>
                </div>
            </div>

            <?php if ($slipItems): ?>
            <table class="po-slip-items">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Qty</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($slipItems as $i => $it): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= e((string) $it['product_name']) ?></td>
                        <td><?= (int) $it['qty'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <div class="po-slip-foot">
                <div>
                    Stick this slip on the package · Handle with care<br>
                    Order date: <strong><?= !empty($slipOrder['created_at']) ? e(date('d M Y', strtotime((string) $slipOrder['created_at']))) : '—' ?></strong>
                </div>
                <div class="po-slip-sign">
                    <?php if ($signatureUrl): ?>
                        <img src="<?= e($signatureUrl) ?>" alt="Authorized signature">
                    <?php endif; ?>
                    <span>Authorized Signatory</span>
                    <strong><?= e($company) ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
window.addEventListener('load', function () {
    if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
        setTimeout(function () { window.print(); }, 250);
    }
});
</script>
<?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
endif;
?>

<div class="stats-grid no-print">
    <div class="stat-card accent"><div class="label">All Orders</div><div class="value"><?= (int) $stats['order_count'] ?></div></div>
    <div class="stat-card"><div class="label">Paid Total</div><div class="value" style="font-size:1.25rem"><?= currency((float) $stats['paid_total']) ?></div></div>
    <div class="stat-card"><div class="label">Awaiting Ship</div><div class="value"><?= (int) $stats['pending_ship'] ?></div></div>
    <div class="stat-card"><div class="label">In Transit</div><div class="value"><?= (int) $stats['in_transit'] ?></div></div>
    <div class="stat-card"><div class="label">Delivered</div><div class="value"><?= (int) $stats['delivered_count'] ?></div></div>
</div>

<?php if ($viewOrder):
    $m = $viewOrder['_member'] ?? [];
    $ds = (string) ($viewOrder['delivery_status'] ?? 'pending');
?>
<div class="panel" style="margin-bottom:1.25rem">
    <div class="panel-header">
        <div>
            <h2>Order <?= e((string) $viewOrder['invoice_no']) ?></h2>
            <p class="members-sub">
                <?= e(($m['member_id'] ?? '') . ' — ' . ($m['full_name'] ?? '')) ?>
                · Paid <?= currency((float) $viewOrder['total_amount']) ?>
                · <?= e(date('d M Y H:i', strtotime((string) $viewOrder['created_at']))) ?>
            </p>
        </div>
        <div style="display:flex;gap:0.4rem;flex-wrap:wrap">
            <a href="product-orders.php?slip=<?= (int) $viewOrder['id'] ?>&autoprint=1" class="btn btn-primary btn-sm" target="_blank">Print Address Slip</a>
            <a href="product-orders.php?slip=<?= (int) $viewOrder['id'] ?>" class="btn btn-outline btn-sm" target="_blank">View Slip</a>
            <a href="product-orders.php" class="btn btn-outline btn-sm">Back to list</a>
        </div>
    </div>
    <div class="panel-body" style="display:grid;grid-template-columns:1.2fr 1fr;gap:1.25rem">
        <div>
            <h3 style="margin:0 0 0.5rem;font-size:0.95rem">Shipping</h3>
            <p style="margin:0;line-height:1.55">
                <strong><?= e((string) ($viewOrder['shipping_name'] ?? '')) ?></strong><br>
                <?= e((string) ($viewOrder['shipping_phone'] ?? '')) ?><br>
                <?= nl2br(e((string) ($viewOrder['shipping_address'] ?? ''))) ?><br>
                <?php if (!empty($viewOrder['shipping_city']) || !empty($viewOrder['shipping_state']) || !empty($viewOrder['shipping_pincode'])): ?>
                    <?= e(trim(($viewOrder['shipping_city'] ?? '') . ', ' . ($viewOrder['shipping_state'] ?? '') . ' - ' . ($viewOrder['shipping_pincode'] ?? ''), ' ,-')) ?>
                <?php endif; ?>
            </p>
            <div class="table-wrap" style="margin-top:1rem">
                <table class="data">
                    <thead>
                        <tr><th>Product</th><th>Qty</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($viewItems as $it): ?>
                        <tr>
                            <td><?= e($it['product_name']) ?><?= !empty($it['sku']) ? ' <small>(' . e($it['sku']) . ')</small>' : '' ?></td>
                            <td><?= (int) $it['qty'] ?></td>
                            <td><?= currency((float) $it['line_total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div>
            <h3 style="margin:0 0 0.75rem;font-size:0.95rem">Update delivery</h3>
            <form method="post" class="form-grid" style="display:grid;gap:0.75rem">
                <input type="hidden" name="action" value="update_delivery">
                <input type="hidden" name="order_id" value="<?= (int) $viewOrder['id'] ?>">
                <div class="form-group">
                    <label>Delivery status</label>
                    <select name="delivery_status" required>
                        <?php foreach ($deliveryMap as $k => $lab): ?>
                        <option value="<?= e($k) ?>" <?= $ds === $k ? 'selected' : '' ?>><?= e($lab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Courier</label>
                    <input type="text" name="courier_name" value="<?= e((string) ($viewOrder['courier_name'] ?? '')) ?>" placeholder="e.g. BlueDart / Delhivery">
                </div>
                <div class="form-group">
                    <label>Tracking no.</label>
                    <input type="text" name="tracking_no" value="<?= e((string) ($viewOrder['tracking_no'] ?? '')) ?>" placeholder="AWB / tracking ID">
                </div>
                <div class="form-group">
                    <label>Note (optional)</label>
                    <input type="text" name="delivery_note" value="<?= e((string) ($viewOrder['delivery_note'] ?? '')) ?>" placeholder="Shown to member on invoice">
                </div>
                <button type="submit" class="btn btn-primary">Save delivery status</button>
            </form>
            <p class="muted" style="margin:0.75rem 0 0;font-size:0.8rem">
                Payment: <strong><?= e(ucfirst((string) $viewOrder['status'])) ?></strong>
                · Delivery: <strong><?= e(product_delivery_label($ds)) ?></strong>
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2>Product Orders (<?= $total ?>)</h2>
            <p class="members-sub">Manage shipping &amp; delivery · print address slip for packages</p>
        </div>
    </div>
    <div class="panel-body">
        <form class="filters" method="get">
            <div class="form-group">
                <label>Search</label>
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="Invoice / member / tracking">
            </div>
            <div class="form-group">
                <label>Payment</label>
                <select name="status">
                    <option value="">All</option>
                    <?php foreach (['pending', 'paid', 'cancelled', 'refunded'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Delivery</label>
                <select name="delivery">
                    <option value="">All</option>
                    <?php foreach ($deliveryMap as $k => $lab): ?>
                    <option value="<?= e($k) ?>" <?= $delivery === $k ? 'selected' : '' ?>><?= e($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Member</th>
                    <th>Total</th>
                    <th>Ship to</th>
                    <th>Payment</th>
                    <th>Delivery</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="empty-state">No product orders found.</td></tr>
            <?php else: foreach ($rows as $r):
                $ds = (string) ($r['delivery_status'] ?? 'pending');
            ?>
                <tr>
                    <td><strong><?= e((string) $r['invoice_no']) ?></strong></td>
                    <td>
                        <a href="member-view.php?id=<?= (int) $r['member_id'] ?>"><?= e($r['mid'] . ' — ' . $r['full_name']) ?></a>
                    </td>
                    <td><?= currency((float) $r['total_amount']) ?></td>
                    <td>
                        <?= e((string) ($r['shipping_name'] ?? '—')) ?>
                        <?php if (!empty($r['shipping_city'])): ?>
                            <br><small><?= e((string) $r['shipping_city']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge"><?= e(ucfirst((string) $r['status'])) ?></span></td>
                    <td><span class="badge"><?= e(product_delivery_label($ds)) ?></span></td>
                    <td><?= e(date('d M Y H:i', strtotime((string) $r['created_at']))) ?></td>
                    <td style="white-space:nowrap">
                        <a class="btn btn-outline btn-sm" href="product-orders.php?id=<?= (int) $r['id'] ?>">Manage</a>
                        <a class="btn btn-primary btn-sm" href="product-orders.php?slip=<?= (int) $r['id'] ?>&autoprint=1" target="_blank" title="Print shipping address slip">Address Slip</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="panel-body" style="display:flex;gap:0.5rem;flex-wrap:wrap">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?>
                <strong><?= $i ?></strong>
            <?php else: ?>
                <a href="?page=<?= $i ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>&delivery=<?= urlencode($delivery) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
