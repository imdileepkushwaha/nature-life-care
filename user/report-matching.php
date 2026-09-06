<?php
/**
 * User Matching Report — pair (binary) + matching bonus (same scope as Matching Income).
 */
$pageTitle = 'Matching Report';
require_once __DIR__ . '/../includes/income.php';
require_once __DIR__ . '/../includes/income_tables.php';
require_once __DIR__ . '/includes/header.php';

if (!plan_uses_binary()) {
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

$mWhere = ['c.member_id = ?', 'DATE(c.created_at) BETWEEN ? AND ?'];
$bWhere = ['c.member_id = ?', "c.type = 'binary'", 'DATE(c.created_at) BETWEEN ? AND ?'];
$mParams = [$uid, $from, $to];
$bParams = [$uid, $from, $to];
if (in_array($statusFilter, ['pending', 'paid', 'cancelled'], true)) {
    $mWhere[] = 'c.status = ?';
    $bWhere[] = 'c.status = ?';
    $mParams[] = $statusFilter;
    $bParams[] = $statusFilter;
} else {
    $mWhere[] = "c.status != 'cancelled'";
    $bWhere[] = "c.status != 'cancelled'";
}
$mSql = implode(' AND ', $mWhere);
$bSql = implode(' AND ', $bWhere);
$allParams = array_merge($mParams, $bParams);

$countStmt = $pdo->prepare("
    SELECT (
        (SELECT COUNT(*) FROM income_matching c WHERE {$mSql})
        + (SELECT COUNT(*) FROM commissions c WHERE {$bSql})
    )
");
$countStmt->execute($allParams);
$totalRows = (int) $countStmt->fetchColumn();

$sumStmt = $pdo->prepare("
    SELECT (
        (SELECT COALESCE(SUM(c.amount),0) FROM income_matching c WHERE {$mSql})
        + (SELECT COALESCE(SUM(c.amount),0) FROM commissions c WHERE {$bSql})
    )
");
$sumStmt->execute($allParams);
$totalAmt = (float) $sumStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT * FROM (
        SELECT c.id, 'matching' AS type, c.amount, c.description, c.status, c.created_at,
               fm.member_id AS from_mid, fm.full_name AS from_name
        FROM income_matching c
        LEFT JOIN members fm ON fm.id = c.from_member_id
        WHERE {$mSql}
        UNION ALL
        SELECT c.id, c.type, c.amount, c.description, c.status, c.created_at,
               fm.member_id AS from_mid, fm.full_name AS from_name
        FROM commissions c
        LEFT JOIN members fm ON fm.id = c.from_member_id
        WHERE {$bSql}
    ) AS u
    ORDER BY u.created_at DESC, u.id DESC
    LIMIT 200
");
$stmt->execute($allParams);
$rows = $stmt->fetchAll();
$paidSum = income_sum($pdo, $uid, 'matching', 'paid');
?>
<div class="up-page-head">
    <div>
        <h1>Matching Report</h1>
        <p>Pair income and matching bonus for the selected period.</p>
    </div>
    <a href="income-matching.php" class="up-btn up-btn-outline">Matching Income</a>
</div>

<div class="inc-stats">
    <article class="inc-stat g-orange">
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
    <div class="inc-banner is-orange">
        <div class="inc-banner-main">
            <span class="inc-banner-ico" aria-hidden="true"><?= income_type_icon('matching') ?></span>
            <div>
                <span class="inc-kicker">Reports</span>
                <h2>Matching Report</h2>
                <p>Same income as Matching Income — filter by date below.</p>
            </div>
        </div>
    </div>
    <form method="get" class="inc-filter-bar">
        <div class="inc-filter-field">
            <label for="matchFrom">From</label>
            <input type="date" id="matchFrom" name="from" value="<?= e($from) ?>" required>
        </div>
        <div class="inc-filter-field">
            <label for="matchTo">To</label>
            <input type="date" id="matchTo" name="to" value="<?= e($to) ?>" required>
        </div>
        <div class="inc-filter-field">
            <label for="matchStatus">Status</label>
            <select id="matchStatus" name="status">
                <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All (excl. cancelled)</option>
                <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </div>
        <div class="inc-filter-actions">
            <button type="submit" class="up-btn up-btn-primary">Search</button>
            <a href="report-matching.php" class="up-btn up-btn-outline">Reset</a>
        </div>
    </form>
    <div class="inc-table-wrap">
        <table class="inc-table">
            <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>From</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Description</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="inc-empty">No matching entries in this period.</td></tr>
            <?php else: foreach ($rows as $r):
                $typeKey = (string) ($r['type'] ?? 'matching');
                $typeLabel = $typeKey === 'binary' ? 'Pair' : 'Bonus';
            ?>
                <tr>
                    <td><?= e(date('d M Y H:i', strtotime((string) $r['created_at']))) ?></td>
                    <td><?= e($typeLabel) ?></td>
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
