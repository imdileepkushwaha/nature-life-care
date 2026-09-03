<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/withdrawal.php';
require_once __DIR__ . '/../includes/report_helpers.php';
$pageTitle = 'TDS Report';

wd_ensure_columns($pdo);

[$from, $to] = report_parse_dates();
$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$minTds = trim((string) ($_GET['min_tds'] ?? ''));
$minTdsVal = is_numeric($minTds) ? (float) $minTds : null;

$where = [
    "w.status IN ('approved','paid')",
    'DATE(COALESCE(w.processed_at, w.requested_at)) BETWEEN ? AND ?',
];
$params = [$from, $to];

if ($status !== '' && in_array($status, ['approved', 'paid'], true)) {
    $where = [
        'w.status = ?',
        'DATE(COALESCE(w.processed_at, w.requested_at)) BETWEEN ? AND ?',
    ];
    $params = [$status, $from, $to];
}
if ($q !== '') {
    $where[] = '(m.member_id LIKE ? OR m.full_name LIKE ? OR m.username LIKE ? OR CAST(w.id AS CHAR) LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($minTdsVal !== null) {
    $where[] = 'w.tds_amount >= ?';
    $params[] = $minTdsVal;
} else {
    // Default list can include zero TDS; export still focuses on TDS > 0 when exporting
}

$whereSql = implode(' AND ', $where);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportWhere = $where;
    $exportParams = $params;
    if ($minTdsVal === null) {
        $exportWhere[] = 'w.tds_amount > 0';
    }
    $stmt = $pdo->prepare('
        SELECT w.*, m.full_name, m.member_id AS mid
        FROM withdrawals w
        JOIN members m ON m.id = w.member_id
        WHERE ' . implode(' AND ', $exportWhere) . '
        ORDER BY w.id ASC
    ');
    $stmt->execute($exportParams);
    $rows = $stmt->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tds-report-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['ID', 'Date', 'Member ID', 'Name', 'Gross', 'TDS', 'Fee', 'Other', 'Net', 'Status']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (int) $r['id'],
            $r['processed_at'] ?? $r['requested_at'],
            $r['mid'],
            $r['full_name'],
            number_format((float) $r['amount'], 2, '.', ''),
            number_format((float) $r['tds_amount'], 2, '.', ''),
            number_format((float) $r['fee_amount'], 2, '.', ''),
            number_format((float) $r['other_deduction'], 2, '.', ''),
            number_format(wd_net_display($r), 2, '.', ''),
            $r['status'],
        ]);
    }
    fclose($out);
    exit;
}

$sumStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS cnt,
        COALESCE(SUM(w.amount),0) AS gross,
        COALESCE(SUM(w.tds_amount),0) AS tds,
        COALESCE(SUM(w.fee_amount),0) AS fee,
        COALESCE(SUM(w.other_deduction),0) AS other_ded,
        COALESCE(SUM(COALESCE(w.net_amount, w.amount)),0) AS net
    FROM withdrawals w
    JOIN members m ON m.id = w.member_id
    WHERE {$whereSql}
");
$sumStmt->execute($params);
$sums = $sumStmt->fetch() ?: [];

$list = $pdo->prepare("
    SELECT w.*, m.full_name, m.member_id AS mid
    FROM withdrawals w
    JOIN members m ON m.id = w.member_id
    WHERE {$whereSql}
    ORDER BY w.id DESC
    LIMIT 300
");
$list->execute($params);
$rows = $list->fetchAll();

$exportQs = http_build_query(array_filter([
    'from' => $from,
    'to' => $to,
    'q' => $q !== '' ? $q : null,
    'status' => $status !== '' ? $status : null,
    'min_tds' => $minTds !== '' ? $minTds : null,
    'export' => 'csv',
], static fn ($v) => $v !== null && $v !== ''));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>TDS &amp; Deduction Report</h2>
            <p class="tpin-panel-sub">Approved / paid withdrawals — filter by member, status, min TDS</p>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
            <a class="btn btn-outline btn-sm" href="withdrawals.php">← Withdrawals</a>
            <a class="btn btn-primary btn-sm" href="?<?= e($exportQs) ?>">Export CSV</a>
        </div>
    </div>
    <div class="panel-body">
        <?php
        report_filter_form('tds-report.php', $from, $to, [
            ['name' => 'q', 'label' => 'Search', 'type' => 'text', 'value' => $q, 'placeholder' => 'Member / name / WD #', 'wide' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'value' => $status, 'options' => [
                '' => 'Approved + Paid',
                'approved' => 'Approved',
                'paid' => 'Paid',
            ]],
            ['name' => 'min_tds', 'label' => 'Min TDS', 'type' => 'number', 'value' => $minTds, 'placeholder' => '0'],
        ]);
        ?>

        <div class="stats-grid tpin-stats">
            <div class="stat-card"><div class="label">Payouts</div><div class="value"><?= (int) ($sums['cnt'] ?? 0) ?></div></div>
            <div class="stat-card"><div class="label">Gross</div><div class="value" style="font-size:1.1rem"><?= currency((float) ($sums['gross'] ?? 0)) ?></div></div>
            <div class="stat-card accent"><div class="label">TDS</div><div class="value" style="font-size:1.1rem"><?= currency((float) ($sums['tds'] ?? 0)) ?></div></div>
            <div class="stat-card"><div class="label">Fees + Other</div><div class="value" style="font-size:1.1rem"><?= currency((float) ($sums['fee'] ?? 0) + (float) ($sums['other_ded'] ?? 0)) ?></div></div>
            <div class="stat-card"><div class="label">Net Remitted</div><div class="value" style="font-size:1.1rem"><?= currency((float) ($sums['net'] ?? 0)) ?></div></div>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data tpin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Member</th>
                    <th>Gross</th>
                    <th>TDS</th>
                    <th>Fee</th>
                    <th>Other</th>
                    <th>Net</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="9" style="text-align:center;padding:1.2rem;color:#64748b">No matching withdrawals in this period.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td>#<?= (int) $r['id'] ?></td>
                    <td>
                        <strong><?= e($r['full_name']) ?></strong>
                        <span class="tpin-meta"><?= e($r['mid']) ?></span>
                    </td>
                    <td><?= currency((float) $r['amount']) ?></td>
                    <td><strong><?= currency((float) $r['tds_amount']) ?></strong></td>
                    <td><?= currency((float) $r['fee_amount']) ?></td>
                    <td><?= currency((float) $r['other_deduction']) ?></td>
                    <td><strong><?= currency(wd_net_display($r)) ?></strong></td>
                    <td><?= status_badge((string) $r['status']) ?></td>
                    <td><?= e(date('d M Y', strtotime((string) ($r['processed_at'] ?? $r['requested_at'])))) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
