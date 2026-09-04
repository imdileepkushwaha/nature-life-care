<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/report_helpers.php';
require_once __DIR__ . '/../includes/income_tables.php';
$pageTitle = 'Matching Report';

if (!feature_enabled('feature_matching_income') || !plan_uses_binary()) {
    flash('error', 'Matching income is disabled.');
    header('Location: reports.php');
    exit;
}

[$from, $to] = report_parse_dates();
$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

$data = income_split_admin_report($pdo, 'matching', $from, $to, $q, $status);
$rows = $data['rows'];
$totalAmt = $data['total'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>Matching Report</h2>
            <p class="tpin-panel-sub">Matching bonus from dedicated income_matching table</p>
        </div>
    </div>
    <div class="panel-body">
        <?php
        report_filter_form('report-matching.php', $from, $to, [
            ['name' => 'q', 'label' => 'Search', 'type' => 'text', 'value' => $q, 'placeholder' => 'Member ID / name / note', 'wide' => true],
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
                <th>From</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Description</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" style="text-align:center;padding:1.2rem;color:#64748b">No matching entries found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= e(date('d M Y H:i', strtotime((string) $r['created_at']))) ?></td>
                    <td>
                        <strong><?= e($r['full_name']) ?></strong>
                        <span class="tpin-meta"><?= e($r['member_id']) ?></span>
                    </td>
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
