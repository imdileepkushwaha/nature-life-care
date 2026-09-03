<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/report_helpers.php';
$pageTitle = 'Commission Report';

[$from, $to] = report_parse_dates();
$q = trim((string) ($_GET['q'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

$enabledTypes = ['other'];
if (feature_enabled('feature_binary_income') && plan_uses_binary()) {
    $enabledTypes[] = 'binary';
}
if (feature_enabled('feature_referral_income')) {
    $enabledTypes[] = 'referral';
}
if (feature_enabled('feature_matching_income') && plan_uses_binary()) {
    $enabledTypes[] = 'matching';
}
if (feature_enabled('feature_level_income') && plan_uses_level()) {
    $enabledTypes[] = 'level';
}
$typeFilterOptions = ['' => 'All types'];
foreach ($enabledTypes as $t) {
    $typeFilterOptions[$t] = ucfirst($t);
}

$where = ["DATE(c.created_at) BETWEEN ? AND ?", "c.status != 'cancelled'"];
$params = [$from, $to];

if ($status !== '' && in_array($status, ['pending', 'paid', 'cancelled'], true)) {
    $where = ["DATE(c.created_at) BETWEEN ? AND ?", 'c.status = ?'];
    $params = [$from, $to, $status];
}
if ($type !== '' && in_array($type, $enabledTypes, true)) {
    $where[] = 'c.type = ?';
    $params[] = $type;
}
if ($q !== '') {
    $where[] = '(m.member_id LIKE ? OR m.full_name LIKE ? OR m.username LIKE ? OR c.description LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$sql = '
    SELECT c.*, m.member_id, m.full_name, m.username,
           fm.member_id AS from_code, fm.full_name AS from_name
    FROM commissions c
    JOIN members m ON m.id = c.member_id
    LEFT JOIN members fm ON fm.id = c.from_member_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY c.id DESC
    LIMIT 500
';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$sumSql = 'SELECT COALESCE(SUM(c.amount),0) FROM commissions c JOIN members m ON m.id = c.member_id WHERE ' . implode(' AND ', $where);
$sumStmt = $pdo->prepare($sumSql);
$sumStmt->execute($params);
$totalAmt = (float) $sumStmt->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>Commission Report</h2>
            <p class="tpin-panel-sub">Filter by date, type, status and member</p>
        </div>
        <a href="commissions.php" class="btn btn-outline btn-sm">Manage commissions</a>
    </div>
    <div class="panel-body">
        <?php
        report_filter_form('report-commission.php', $from, $to, [
            ['name' => 'q', 'label' => 'Search', 'type' => 'text', 'value' => $q, 'placeholder' => 'Member ID / name / note', 'wide' => true],
            ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'value' => $type, 'options' => $typeFilterOptions],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'value' => $status, 'options' => [
                '' => 'All (excl. cancelled)', 'pending' => 'Pending', 'paid' => 'Paid', 'cancelled' => 'Cancelled',
            ]],
        ]);
        ?>
        <div class="stats-grid tpin-stats">
            <div class="stat-card accent"><div class="label">Rows</div><div class="value"><?= count($rows) ?></div></div>
            <div class="stat-card"><div class="label">Total amount</div><div class="value" style="font-size:1.2rem"><?= currency($totalAmt) ?></div></div>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data tpin-table">
            <thead>
            <tr>
                <th>Date</th>
                <th>Member</th>
                <th>Type</th>
                <th>From</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Description</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" style="text-align:center;padding:1.2rem;color:#64748b">No commissions found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= e(date('d M Y H:i', strtotime((string) $r['created_at']))) ?></td>
                    <td>
                        <strong><?= e($r['full_name']) ?></strong>
                        <span class="tpin-meta"><?= e($r['member_id']) ?></span>
                    </td>
                    <td><?= e(ucfirst((string) $r['type'])) ?></td>
                    <td><?= !empty($r['from_code']) ? e($r['from_name'] . ' · ' . $r['from_code']) : '—' ?></td>
                    <td><strong><?= currency((float) $r['amount']) ?></strong></td>
                    <td><?= status_badge((string) $r['status']) ?></td>
                    <td><?= e((string) ($r['description'] ?? '')) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
