<?php
/**
 * Shared income report UI.
 * Expects $incomeType (binary|referral|matching|level|other) set before include.
 */
require_once __DIR__ . '/../../includes/income.php';

$meta = income_type_meta($incomeType ?? '');
if (!$meta) {
    header('Location: income-binary.php');
    exit;
}

$pageTitle = $meta['label'];
require_once __DIR__ . '/header.php';

$uid = (int) $user['id'];
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$data = income_fetch_rows($pdo, $uid, $meta['key'], $statusFilter, $page, 15);
$rows = $data['rows'];
$total = $data['total'];
$totalPages = $data['total_pages'];
$page = $data['page'];

$paidSum = income_sum($pdo, $uid, $meta['key'], 'paid');
$pendingSum = income_sum($pdo, $uid, $meta['key'], 'pending');
$paidCount = income_count($pdo, $uid, $meta['key'], 'paid');
$pendingCount = income_count($pdo, $uid, $meta['key'], 'pending');
$allCount = income_count($pdo, $uid, $meta['key']);
$grandTotal = $paidSum + $pendingSum;

$toneClass = 'is-' . ($meta['tone'] ?? 'blue');
$types = income_types();
?>
<div class="up-page-head">
    <div>
        <h1><?= e($meta['label']) ?></h1>
        <p><?= e($meta['desc']) ?></p>
    </div>
    <a href="income-summary.php" class="up-btn up-btn-outline">All Income</a>
</div>

<nav class="inc-tabs" aria-label="Income types">
    <a href="income-summary.php" class="inc-tab<?= ($currentPage ?? '') === 'income-summary' ? ' is-on' : '' ?>">Summary</a>
    <?php foreach ($types as $t): ?>
        <a href="<?= e($t['file']) ?>" class="inc-tab<?= $meta['key'] === $t['key'] ? ' is-on' : '' ?>"><?= e($t['short']) ?></a>
    <?php endforeach; ?>
</nav>

<div class="inc-stats">
    <article class="inc-stat g-green">
        <span class="inc-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
        <div>
            <span class="inc-stat-label">Paid</span>
            <strong class="is-sm"><?= currency($paidSum) ?></strong>
            <small><?= $paidCount ?> entries</small>
        </div>
    </article>
    <article class="inc-stat g-orange">
        <span class="inc-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
        <div>
            <span class="inc-stat-label">Pending</span>
            <strong class="is-sm"><?= currency($pendingSum) ?></strong>
            <small><?= $pendingCount ?> entries</small>
        </div>
    </article>
    <article class="inc-stat g-blue">
        <span class="inc-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg></span>
        <div>
            <span class="inc-stat-label">Type Total</span>
            <strong class="is-sm"><?= currency($grandTotal) ?></strong>
            <small><?= $allCount ?> records</small>
        </div>
    </article>
    <article class="inc-stat g-purple">
        <span class="inc-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></span>
        <div>
            <span class="inc-stat-label">Wallet Now</span>
            <strong class="is-sm"><?= currency((float) $user['wallet_balance']) ?></strong>
        </div>
    </article>
</div>

<section class="inc-card">
    <div class="inc-banner <?= e($toneClass) ?>">
        <div class="inc-banner-main">
            <span class="inc-banner-ico" aria-hidden="true"><?= income_type_icon($meta['key']) ?></span>
            <div>
                <span class="inc-kicker"><?= e($meta['kicker']) ?></span>
                <h2><?= e($meta['label']) ?> Report</h2>
                <p>Filter by status to review paid or pending credits.</p>
            </div>
        </div>
        <form method="get" class="inc-filters">
            <select name="status" onchange="this.form.submit()" aria-label="Filter status">
                <option value="">All statuses</option>
                <?php foreach (['paid', 'pending', 'cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="inc-table-wrap">
        <table class="inc-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Amount</th>
                    <th>From</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="6">
                        <div class="inc-empty">
                            <strong>No <?= e(strtolower($meta['short'])) ?> income yet</strong>
                            <p>When this income type is credited to your account, it will appear here.</p>
                            <a href="income-summary.php" class="up-btn up-btn-outline">View Summary</a>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong>#<?= (int) $r['id'] ?></strong></td>
                    <td><strong class="inc-amt"><?= currency((float) $r['amount']) ?></strong></td>
                    <td>
                        <?php if (!empty($r['from_mid'])): ?>
                            <div class="inc-from">
                                <strong><?= e($r['from_name'] ?? '—') ?></strong>
                                <small><?= e($r['from_mid']) ?><?= !empty($r['from_username']) ? ' · @' . e($r['from_username']) : '' ?></small>
                            </div>
                        <?php else: ?>
                            <span class="inc-muted">System / Admin</span>
                        <?php endif; ?>
                    </td>
                    <td><div class="inc-desc"><?= e($r['description'] ?: '—') ?></div></td>
                    <td><?= income_status_pill((string) $r['status']) ?></td>
                    <td><?= e(date('d M Y, h:i A', strtotime($r['created_at']))) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="inc-pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a class="<?= $i === $page ? 'is-on' : '' ?>" href="?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
