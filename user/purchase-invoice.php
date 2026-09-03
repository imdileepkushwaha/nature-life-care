<?php
$pageTitle = 'Purchase Invoice';
require_once __DIR__ . '/../includes/product_orders.php';
require_once __DIR__ . '/includes/header.php';

product_orders_ensure_tables($pdo);
$uid = (int) $user['id'];
$orderId = (int) ($_GET['id'] ?? 0);
$company = setting('company_name', 'Binary MLM');
$support = setting('support_email', setting('contact_email', ''));
$supportPhone = setting('contact_phone', '');
$signatureUrl = company_signature_url();

$order = null;
$items = [];
if ($orderId > 0) {
    $order = product_order_get($pdo, $orderId, $uid);
    if ($order) {
        $items = product_order_items($pdo, $orderId);
    }
}

$recent = [];
if (!$order) {
    $recent = product_orders_for_member($pdo, $uid, 20, 0);
}

$deliveryStatuses = product_delivery_statuses();
$timelineSteps = product_delivery_timeline_steps();
$justActivated = !empty($_GET['activated']);
if ($justActivated) {
    $user = current_user($pdo, true) ?: $user;
}
?>
<div class="doc-page shop-invoice-page">
    <div class="doc-toolbar no-print">
        <div>
            <h1 class="doc-title">Purchase Invoice</h1>
            <p class="doc-sub"><?= $order ? 'Invoice ' . e($order['invoice_no']) . ' · track shipping below' : 'Select an order to view invoice & delivery' ?></p>
        </div>
        <div class="doc-toolbar-actions">
            <?php if ($order): ?>
                <button type="button" class="up-btn up-btn-primary" onclick="window.print()">Print / Save PDF</button>
            <?php endif; ?>
            <a href="purchase-report.php" class="up-btn up-btn-outline">Purchase Report</a>
            <a href="purchase-product.php" class="up-btn up-btn-outline">Buy more</a>
        </div>
    </div>

    <?php
    $flash = get_flash();
    if ($flash): ?>
        <div class="up-alert up-alert-<?= $flash['type'] === 'error' ? 'err' : 'ok' ?> no-print"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($order && $justActivated): ?>
        <div class="ck-activated no-print">
            <strong>Account activated</strong>
            <p>Payment successful. Your ID is now active<?= !empty($user['package_name']) ? ' with package <strong>' . e((string) $user['package_name']) . '</strong>' : '' ?>.</p>
        </div>
    <?php endif; ?>
    <?php if (!$order): ?>
        <section class="shop-card no-print">
            <div class="shop-banner">
                <div>
                    <span class="shop-kicker">Invoices</span>
                    <h2>Select invoice</h2>
                    <p>Open any paid order to see shipping &amp; delivery status.</p>
                </div>
            </div>
            <?php if (!$recent): ?>
                <div class="shop-empty">
                    <strong>No invoices yet</strong>
                    <p><a href="purchase-product.php">Purchase a product</a> to generate your first invoice.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="shop-table">
                        <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Payment</th>
                            <th>Delivery</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent as $r):
                            $ds = (string) ($r['delivery_status'] ?? 'pending');
                        ?>
                            <tr>
                                <td><code class="shop-inv"><?= e($r['invoice_no']) ?></code></td>
                                <td><?= !empty($r['created_at']) ? e(date('d M Y', strtotime((string) $r['created_at']))) : '—' ?></td>
                                <td><?= currency((float) $r['total_amount']) ?></td>
                                <td><span class="shop-pill is-<?= e($r['status']) ?>"><?= e(ucfirst((string) $r['status'])) ?></span></td>
                                <td><span class="shop-pill is-del-<?= e($ds) ?>"><?= e(product_delivery_label($ds)) ?></span></td>
                                <td class="shop-td-actions">
                                    <a class="up-btn up-btn-primary up-btn-sm" href="purchase-invoice.php?id=<?= (int) $r['id'] ?>">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php else:
        $ds = strtolower((string) ($order['delivery_status'] ?? 'pending'));
        if ($ds === '') {
            $ds = 'pending';
        }
        $stepIdx = product_delivery_step_index($ds);
        $isCancelled = ($ds === 'cancelled');
        $cityLine = trim(implode(', ', array_filter([
            (string) ($order['shipping_city'] ?? ''),
            (string) ($order['shipping_state'] ?? ''),
        ])));
        if (!empty($order['shipping_pincode'])) {
            $cityLine = trim($cityLine . ' - ' . $order['shipping_pincode'], ' -');
        }
        $logoUrl = company_logo_url();
        $paidStatus = strtolower((string) ($order['status'] ?? 'paid'));
        $itemCount = count($items);
    ?>
        <div class="shop-track no-print">
            <div class="shop-track-head">
                <div>
                    <span class="shop-kicker">Delivery status</span>
                    <h2><?= e(product_delivery_label($ds)) ?></h2>
                    <p>
                        <?php if (!empty($order['tracking_no'])): ?>
                            Tracking <strong><?= e((string) $order['tracking_no']) ?></strong>
                            <?= !empty($order['courier_name']) ? ' · ' . e((string) $order['courier_name']) : '' ?>
                        <?php else: ?>
                            <?= $isCancelled ? 'This order delivery was cancelled.' : 'We will update courier & tracking once your order is shipped.' ?>
                        <?php endif; ?>
                    </p>
                </div>
                <span class="shop-pill is-del-<?= e($ds) ?>"><?= e(product_delivery_label($ds)) ?></span>
            </div>
            <?php if (!$isCancelled): ?>
            <ol class="shop-track-steps">
                <?php foreach ($timelineSteps as $i => $key):
                    $cls = '';
                    if ($i < $stepIdx) {
                        $cls = 'is-done';
                    } elseif ($i === $stepIdx) {
                        $cls = 'is-current';
                    }
                ?>
                <li class="<?= $cls ?>">
                    <span class="shop-track-dot" aria-hidden="true"></span>
                    <strong><?= e($deliveryStatuses[$key]) ?></strong>
                    <?php if ($key === 'pending' && !empty($order['created_at'])): ?>
                        <small><?= e(date('d M Y H:i', strtotime((string) $order['created_at']))) ?></small>
                    <?php elseif ($key === 'shipped' && !empty($order['shipped_at']) && $stepIdx >= $i): ?>
                        <small><?= e(date('d M Y H:i', strtotime((string) $order['shipped_at']))) ?></small>
                    <?php elseif ($key === 'delivered' && !empty($order['delivered_at']) && $stepIdx >= $i): ?>
                        <small><?= e(date('d M Y H:i', strtotime((string) $order['delivered_at']))) ?></small>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php endif; ?>
            <?php if (!empty($order['delivery_note'])): ?>
                <p class="shop-track-note"><?= e((string) $order['delivery_note']) ?></p>
            <?php endif; ?>
        </div>

        <article class="shop-invoice" id="shopInvoicePrint">
            <div class="shop-inv-accent" aria-hidden="true"></div>

            <header class="shop-inv-top">
                <div class="shop-inv-brand-block">
                    <?php if ($logoUrl): ?>
                        <img src="<?= e($logoUrl) ?>" alt="<?= e($company) ?>" class="shop-inv-logo">
                    <?php else: ?>
                        <div class="shop-inv-logo-fallback"><?= e(strtoupper(substr($company, 0, 1))) ?></div>
                    <?php endif; ?>
                    <div>
                        <strong class="shop-inv-brand"><?= e($company) ?></strong>
                        <p class="shop-inv-doc-type">TAX / PURCHASE INVOICE</p>
                        <?php if ($support !== '' || $supportPhone !== ''): ?>
                            <p class="shop-inv-contact">
                                <?= $support !== '' ? e($support) : '' ?>
                                <?= ($support !== '' && $supportPhone !== '') ? ' · ' : '' ?>
                                <?= $supportPhone !== '' ? e($supportPhone) : '' ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="shop-inv-right">
                    <div class="shop-inv-no">
                        <span>Invoice</span>
                        <strong><?= e($order['invoice_no']) ?></strong>
                    </div>
                    <div class="shop-inv-badges">
                        <span class="shop-inv-badge is-<?= e($paidStatus) ?>"><?= e(ucfirst($paidStatus)) ?></span>
                        <span class="shop-inv-badge is-del"><?= e(product_delivery_label($ds)) ?></span>
                    </div>
                </div>
            </header>

            <div class="shop-inv-info-strip">
                <div>
                    <span>Invoice date</span>
                    <strong><?= !empty($order['created_at']) ? e(date('d M Y, h:i A', strtotime((string) $order['created_at']))) : '—' ?></strong>
                </div>
                <div>
                    <span>Payment mode</span>
                    <strong>Shopping Wallet</strong>
                </div>
                <div>
                    <span>Items</span>
                    <strong><?= (int) $itemCount ?></strong>
                </div>
                <?php if (!empty($order['tracking_no'])): ?>
                <div>
                    <span>Tracking</span>
                    <strong><?= e((string) $order['tracking_no']) ?></strong>
                </div>
                <?php endif; ?>
            </div>

            <div class="shop-inv-parties">
                <div class="shop-inv-party">
                    <span class="shop-inv-label">Bill To</span>
                    <strong><?= e($user['full_name']) ?></strong>
                    <p class="shop-inv-id"><?= e($user['member_id']) ?><?= !empty($user['username']) ? ' · @' . e($user['username']) : '' ?></p>
                    <?php if (!empty($user['email'])): ?><p><?= e($user['email']) ?></p><?php endif; ?>
                    <?php if (!empty($user['phone'])): ?><p><?= e($user['phone']) ?></p><?php endif; ?>
                </div>
                <div class="shop-inv-party">
                    <span class="shop-inv-label">Ship To</span>
                    <strong><?= e($order['shipping_name'] ?? $user['full_name']) ?></strong>
                    <?php if (!empty($order['shipping_phone'])): ?><p><?= e($order['shipping_phone']) ?></p><?php endif; ?>
                    <p><?= nl2br(e((string) ($order['shipping_address'] ?? '—'))) ?></p>
                    <?php if ($cityLine !== ''): ?><p><?= e($cityLine) ?></p><?php endif; ?>
                    <?php if (!empty($order['courier_name'])): ?><p class="shop-inv-courier">Courier: <?= e((string) $order['courier_name']) ?></p><?php endif; ?>
                </div>
            </div>

            <table class="shop-inv-table">
                <thead>
                <tr>
                    <th class="is-num">#</th>
                    <th>Product</th>
                    <th>SKU</th>
                    <th class="is-num">Qty</th>
                    <th class="is-num">Unit</th>
                    <th class="is-num">PV</th>
                    <th class="is-num">Amount</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $i => $it): ?>
                    <tr>
                        <td class="is-num"><?= $i + 1 ?></td>
                        <td class="is-name"><?= e($it['product_name']) ?></td>
                        <td><?= e($it['sku'] ?: '—') ?></td>
                        <td class="is-num"><?= (int) $it['qty'] ?></td>
                        <td class="is-num"><?= currency((float) $it['unit_price']) ?></td>
                        <td class="is-num"><?= number_format((float) $it['line_bv'], 2) ?></td>
                        <td class="is-num is-amt"><?= currency((float) $it['line_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="shop-inv-totals">
                <div class="shop-inv-notes">
                    <?php if (!empty($order['note'])): ?>
                        <span class="shop-inv-label">Order note</span>
                        <p><?= e($order['note']) ?></p>
                    <?php else: ?>
                        <span class="shop-inv-label">Thank you</span>
                        <p>Thank you for your purchase. Keep this invoice for your records.</p>
                    <?php endif; ?>
                    <?php if (!empty($order['delivery_note'])): ?>
                        <span class="shop-inv-label" style="margin-top:0.75rem">Delivery note</span>
                        <p><?= e((string) $order['delivery_note']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="shop-inv-sum">
                    <div><span>Subtotal</span><strong><?= currency((float) $order['subtotal']) ?></strong></div>
                    <?php if ((float) ($order['discount_amount'] ?? 0) > 0): ?>
                    <div><span>Discount</span><strong>−<?= currency((float) $order['discount_amount']) ?></strong></div>
                    <?php endif; ?>
                    <div><span>Total PV</span><strong><?= number_format((float) $order['total_bv'], 2) ?></strong></div>
                    <div class="is-grand"><span>Grand Total</span><strong><?= currency((float) $order['total_amount']) ?></strong></div>
                    <div class="is-paid"><span>Amount Paid</span><strong><?= currency((float) $order['total_amount']) ?></strong></div>
                </div>
            </div>

            <footer class="shop-inv-foot">
                <div>
                    <strong>Payment received</strong>
                    <p>Paid via Shopping Wallet · Computer-generated invoice</p>
                </div>
                <div class="shop-inv-stamp">
                    <?php if ($signatureUrl): ?>
                        <img class="shop-inv-sign" src="<?= e($signatureUrl) ?>" alt="Authorized signature">
                    <?php endif; ?>
                    <span>Authorized Signatory</span>
                    <strong><?= e($company) ?></strong>
                </div>
            </footer>
        </article>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
