<?php
$pageTitle = 'Purchase Report';
require_once __DIR__ . '/../includes/product_orders.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/includes/header.php';

product_orders_ensure_tables($pdo);
$uid = (int) $user['id'];
$q = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$total = product_orders_count_for_member($pdo, $uid, $q);
$totalPages = max(1, (int) ceil($total / $perPage));
$rows = product_orders_for_member($pdo, $uid, $perPage, $offset, $q);
$stats = product_orders_stats($pdo, $uid);
$shopBal = wallet_balance($pdo, $uid, 'shopping');
?>
<div class="up-page-head">
    <div>
        <h1>Purchase Report</h1>
        <p>History of your product orders — payment, shipping &amp; delivery status.</p>
    </div>
    <div class="team-head-actions">
        <a href="purchase-tracking.php" class="up-btn up-btn-outline">Purchase Tracking</a>
        <a href="purchase-invoice.php" class="up-btn up-btn-outline">Invoices</a>
        <a href="purchase-product.php" class="up-btn up-btn-primary">Purchase Product</a>
    </div>
</div>

<div class="shop-stats">
    <article class="shop-stat g-blue">
        <span class="shop-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
        <div>
            <span class="shop-stat-label">Orders</span>
            <strong><?= (int) $stats['order_count'] ?></strong>
        </div>
    </article>
    <article class="shop-stat g-green">
        <span class="shop-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg></span>
        <div>
            <span class="shop-stat-label">Total Paid</span>
            <strong class="is-sm"><?= currency((float) $stats['paid_total']) ?></strong>
        </div>
    </article>
    <article class="shop-stat g-orange">
        <span class="shop-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M8 12h8M12 8v8"/></svg></span>
        <div>
            <span class="shop-stat-label">Total PV</span>
            <strong><?= number_format((float) $stats['paid_bv'], 0) ?></strong>
        </div>
    </article>
    <article class="shop-stat g-purple">
        <span class="shop-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 7H4a2 2 0 00-2 2v10a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/></svg></span>
        <div>
            <span class="shop-stat-label">Shopping Wallet</span>
            <strong class="is-sm"><?= currency($shopBal) ?></strong>
        </div>
    </article>
</div>

<section class="shop-card">
    <div class="shop-banner">
        <div>
            <span class="shop-kicker">History</span>
            <h2>Your Purchases</h2>
            <p>Open any invoice for print / PDF.</p>
        </div>
        <form method="get" class="shop-search">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Invoice no.">
            <button type="submit" class="up-btn up-btn-primary">Search</button>
            <?php if ($q !== ''): ?>
                <a href="purchase-report.php" class="up-btn up-btn-outline">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-wrap">
        <table class="shop-table">
            <thead>
            <tr>
                <th>Invoice</th>
                <th>Date</th>
                <th>Amount</th>
                <th>PV</th>
                <th>Payment</th>
                <th>Delivery</th>
                <th>Ship to</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="shop-td-empty">No purchases yet. <a href="purchase-product.php">Buy products</a></td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r):
                    $ds = (string) ($r['delivery_status'] ?? 'pending');
                ?>
                    <tr>
                        <td><code class="shop-inv"><?= e($r['invoice_no']) ?></code></td>
                        <td><?= !empty($r['created_at']) ? e(date('d M Y H:i', strtotime((string) $r['created_at']))) : '—' ?></td>
                        <td><strong><?= currency((float) $r['total_amount']) ?></strong></td>
                        <td><?= number_format((float) $r['total_bv'], 2) ?></td>
                        <td><span class="shop-pill is-<?= e($r['status']) ?>"><?= e(ucfirst((string) $r['status'])) ?></span></td>
                        <td><span class="shop-pill is-del-<?= e($ds) ?>"><?= e(product_delivery_label($ds)) ?></span></td>
                        <td>
                            <?= e($r['shipping_name'] ?? '—') ?>
                            <?php if (!empty($r['shipping_city'])): ?>
                                <br><small><?= e((string) $r['shipping_city']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="shop-td-actions">
                            <a href="purchase-invoice.php?id=<?= (int) $r['id'] ?>" class="up-btn up-btn-outline up-btn-sm">Invoice</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="shop-pager">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?>" class="up-btn up-btn-outline">Prev</a>
            <?php endif; ?>
            <span>Page <?= $page ?> / <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?>" class="up-btn up-btn-outline">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
