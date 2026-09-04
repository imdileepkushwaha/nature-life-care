<?php
/**
 * User DSI Report — dedicated income_dsi table.
 */
$pageTitle = 'DSI Report';
require_once __DIR__ . '/../includes/income.php';
require_once __DIR__ . '/../includes/income_tables.php';
require_once __DIR__ . '/includes/header.php';

if (!feature_enabled('feature_dsi_income')) {
    header('Location: income-summary.php');
    exit;
}

$uid = (int) $user['id'];
income_tables_ensure($pdo);

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$statusFilter = trim((string) ($_GET['status'] ?? ''));

$where = ['c.member_id = ?', 'DATE(c.created_at) BETWEEN ? AND ?'];
$params = [$uid, $from, $to];
if ($statusFilter === 'paid') {
    $where[] = "c.status = 'paid'";
} elseif ($statusFilter === 'cancelled') {
    $where[] = "c.status = 'cancelled'";
} elseif ($statusFilter === 'pending') {
    $where[] = '1=0'; // held until closing — not shown
} else {
    $where[] = "c.status = 'paid'"; // default: paid only (excl. pending + cancelled)
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM income_dsi c WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();

$sumStmt = $pdo->prepare("SELECT COALESCE(SUM(c.amount),0) FROM income_dsi c WHERE {$whereSql}");
$sumStmt->execute($params);
$totalAmt = (float) $sumStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT c.*, fm.member_id AS from_mid, fm.full_name AS from_name
    FROM income_dsi c
    LEFT JOIN members fm ON fm.id = c.from_member_id
    WHERE {$whereSql}
    ORDER BY c.id DESC
    LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$paidSum = income_sum($pdo, $uid, 'dsi', 'paid');
?>
<div class="up-page-head">
    <div>
        <h1>DSI Report</h1>
        <p>Direct Sponsor Incentive credited after binary closing.</p>
    </div>
    <a href="income-dsi.php" class="up-btn up-btn-outline">DSI Income</a>
</div>

<div class="inc-stats">
    <article class="inc-stat g-green">
        <div>
            <span class="inc-stat-label">Period Total</span>
            <strong class="is-sm"><?= currency($totalAmt) ?></strong>
            <small><?= $totalRows ?> entr<?= $totalRows === 1 ? 'y' : 'ies' ?><?= $totalRows > count($rows) ? ' · showing ' . count($rows) : '' ?></small>
        </div>
    </article>
    <article class="inc-stat g-blue">
        <div>
            <span class="inc-stat-label">All-time Paid</span>
            <strong class="is-sm"><?= currency($paidSum) ?></strong>
        </div>
    </article>
</div>

<section class="inc-card">
    <div class="inc-banner is-green">
        <div class="inc-banner-main">
            <span class="inc-banner-ico" aria-hidden="true"><?= income_type_icon('dsi') ?></span>
            <div>
                <span class="inc-kicker">Reports</span>
                <h2>DSI Report</h2>
                <p>Filter by date range. Pending DSI is hidden until closing.</p>
            </div>
        </div>
        <form method="get" class="inc-filters" style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:end">
            <label>From <input type="date" name="from" value="<?= e($from) ?>"></label>
            <label>To <input type="date" name="to" value="<?= e($to) ?>"></label>
            <select name="status" aria-label="Status">
                <option value="">Paid only</option>
                <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
            <button type="submit" class="up-btn up-btn-primary">Filter</button>
        </form>
    </div>
    <div class="inc-table-wrap">
        <table class="inc-table">
            <thead>
            <tr>
                <th>Date</th>
                <th>From</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Description</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="inc-empty">No DSI entries in this period.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= e(date('d M Y H:i', strtotime((string) $r['created_at']))) ?></td>
                    <td><?= !empty($r['from_mid']) ? e(($r['from_name'] ?? '') . ' · ' . $r['from_mid']) : '—' ?></td>
                    <td><strong><?= currency((float) $r['amount']) ?></strong></td>
                    <td><?= income_status_pill((string) $r['status']) ?></td>
                    <td><?= e((string) ($r['description'] ?? '')) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
