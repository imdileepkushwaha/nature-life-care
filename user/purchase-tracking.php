<?php
$pageTitle = 'Purchase Tracking';
require_once __DIR__ . '/../includes/product_orders.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/includes/header.php';

product_orders_ensure_tables($pdo);
$uid = (int) $user['id'];
$q = trim((string) ($_GET['q'] ?? ''));
$delivery = strtolower(trim((string) ($_GET['delivery'] ?? '')));
$deliveryMap = product_delivery_statuses();
if ($delivery !== '' && !isset($deliveryMap[$delivery])) {
    $delivery = '';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$allRows = product_orders_for_member($pdo, $uid, 200, 0, $q);
$filtered = [];
foreach ($allRows as $r) {
    $ds = strtolower((string) ($r['delivery_status'] ?? 'pending'));
    if ($ds === '') {
        $ds = 'pending';
    }
    if ($delivery !== '' && $ds !== $delivery) {
        continue;
    }
    $filtered[] = $r;
}
$total = count($filtered);
$totalPages = max(1, (int) ceil($total / $perPage));
$rows = array_slice($filtered, $offset, $perPage);

$counts = [
    'all' => 0,
    'pending' => 0,
    'processing' => 0,
    'shipped' => 0,
    'out_for_delivery' => 0,
    'delivered' => 0,
    'cancelled' => 0,
];
foreach ($allRows as $r) {
    $ds = strtolower((string) ($r['delivery_status'] ?? 'pending'));
    if ($ds === '') {
        $ds = 'pending';
    }
    $counts['all']++;
    if (isset($counts[$ds])) {
        $counts[$ds]++;
    }
}

$timelineSteps = product_delivery_timeline_steps();
?>
<div class="up-page-head">
    <div>
        <h1>Purchase Tracking</h1>
        <p>Track delivery status, courier and tracking number for your product orders.</p>
    </div>
    <div class="team-head-actions">
        <a href="purchase-report.php" class="up-btn up-btn-outline">Purchase Report</a>
        <a href="purchase-product.php" class="up-btn up-btn-primary">Buy Products</a>
    </div>
</div>

<div class="shop-stats">
    <article class="shop-stat g-blue">
        <span class="shop-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
        <div>
            <span class="shop-stat-label">All Orders</span>
            <strong><?= (int) $counts['all'] ?></strong>
        </div>
    </article>
    <article class="shop-stat g-orange">
        <span class="shop-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
        <div>
            <span class="shop-stat-label">In Process</span>
            <strong><?= (int) ($counts['pending'] + $counts['processing']) ?></strong>
        </div>
    </article>
    <article class="shop-stat g-purple">
        <span class="shop-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></span>
        <div>
            <span class="shop-stat-label">In Transit</span>
            <strong><?= (int) ($counts['shipped'] + $counts['out_for_delivery']) ?></strong>
        </div>
    </article>
    <article class="shop-stat g-green">
        <span class="shop-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 6L9 17l-5-5"/></svg></span>
        <div>
            <span class="shop-stat-label">Delivered</span>
            <strong><?= (int) $counts['delivered'] ?></strong>
        </div>
    </article>
</div>

<section class="shop-card">
    <div class="shop-banner">
        <div>
            <span class="shop-kicker">Tracking</span>
            <h2>Your shipments</h2>
            <p>Filter by delivery status or search invoice number.</p>
        </div>
        <form method="get" class="shop-search">
            <?php if ($delivery !== ''): ?><input type="hidden" name="delivery" value="<?= e($delivery) ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Invoice no.">
            <button type="submit" class="up-btn up-btn-primary">Search</button>
            <?php if ($q !== '' || $delivery !== ''): ?>
                <a href="purchase-tracking.php" class="up-btn up-btn-outline">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="trk-filters">
        <a class="trk-chip<?= $delivery === '' ? ' is-on' : '' ?>" href="purchase-tracking.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>">All (<?= (int) $counts['all'] ?>)</a>
        <?php foreach ($deliveryMap as $key => $lab): ?>
            <a class="trk-chip<?= $delivery === $key ? ' is-on' : '' ?>" href="?delivery=<?= e($key) ?><?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?>">
                <?= e($lab) ?> (<?= (int) ($counts[$key] ?? 0) ?>)
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$rows): ?>
        <div class="shop-empty">
            <strong>No orders to track</strong>
            <p><?= $counts['all'] < 1 ? 'Place a product order first.' : 'No orders match this filter.' ?></p>
            <a href="purchase-product.php" class="up-btn up-btn-primary">Purchase Product</a>
        </div>
    <?php else: ?>
        <div class="trk-list">
            <?php foreach ($rows as $r):
                $oid = (int) $r['id'];
                $ds = strtolower((string) ($r['delivery_status'] ?? 'pending'));
                if ($ds === '') {
                    $ds = 'pending';
                }
                $stepIdx = product_delivery_step_index($ds);
                $isCancelled = ($ds === 'cancelled');
                $shipLine = trim(implode(', ', array_filter([
                    (string) ($r['shipping_name'] ?? ''),
                    (string) ($r['shipping_city'] ?? ''),
                    (string) ($r['shipping_state'] ?? ''),
                ])));
            ?>
            <article class="trk-card">
                <div class="trk-card-top">
                    <div>
                        <code class="shop-inv"><?= e((string) $r['invoice_no']) ?></code>
                        <p class="trk-meta">
                            Ordered <?= !empty($r['created_at']) ? e(date('d M Y, h:i A', strtotime((string) $r['created_at']))) : '—' ?>
                            · <?= currency((float) $r['total_amount']) ?>
                        </p>
                    </div>
                    <span class="shop-pill is-del-<?= e($ds) ?>"><?= e(product_delivery_label($ds)) ?></span>
                </div>

                <?php if (!$isCancelled): ?>
                <ol class="trk-mini-steps">
                    <?php foreach ($timelineSteps as $i => $key):
                        $cls = '';
                        if ($i < $stepIdx) {
                            $cls = 'is-done';
                        } elseif ($i === $stepIdx) {
                            $cls = 'is-current';
                        }
                    ?>
                    <li class="<?= $cls ?>"><span></span><?= e($deliveryMap[$key]) ?></li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>

                <div class="trk-card-grid">
                    <div>
                        <span>Ship to</span>
                        <strong><?= e($shipLine !== '' ? $shipLine : ((string) ($r['shipping_name'] ?? '—'))) ?></strong>
                        <?php if (!empty($r['shipping_phone'])): ?>
                            <small><?= e((string) $r['shipping_phone']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span>Courier</span>
                        <strong><?= e((string) ($r['courier_name'] ?? '—')) ?></strong>
                    </div>
                    <div>
                        <span>Tracking no.</span>
                        <strong><?= e((string) ($r['tracking_no'] ?? 'Not assigned yet')) ?></strong>
                    </div>
                </div>

                <div class="trk-card-actions">
                    <a href="purchase-invoice.php?id=<?= $oid ?>" class="up-btn up-btn-primary up-btn-sm">View invoice &amp; track</a>
                    <?php if (!empty($r['shipped_at'])): ?>
                        <small>Shipped <?= e(date('d M Y', strtotime((string) $r['shipped_at']))) ?></small>
                    <?php endif; ?>
                    <?php if (!empty($r['delivered_at'])): ?>
                        <small>Delivered <?= e(date('d M Y', strtotime((string) $r['delivered_at']))) ?></small>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="shop-pager">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?><?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?><?= $delivery !== '' ? '&delivery=' . rawurlencode($delivery) : '' ?>" class="up-btn up-btn-outline">Prev</a>
                <?php endif; ?>
                <span>Page <?= $page ?> / <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?><?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?><?= $delivery !== '' ? '&delivery=' . rawurlencode($delivery) : '' ?>" class="up-btn up-btn-outline">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
