<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/report_helpers.php';
$pageTitle = 'Overview';

[$from, $to] = report_parse_dates();

$showBinaryClosing = feature_module_allowed('binary_closing');
$showPackages = feature_module_allowed('packages');
$showTpin = feature_module_allowed('tpin');
$showProducts = feature_module_allowed('products');
$showWithdrawals = feature_module_allowed('withdrawals');

$joinsStmt = $pdo->prepare('SELECT COUNT(*) FROM members WHERE DATE(join_date) BETWEEN ? AND ?');
$joinsStmt->execute([$from, $to]);
$joins = (int) $joinsStmt->fetchColumn();

require_once __DIR__ . '/../includes/income_tables.php';
income_tables_ensure($pdo);

$commStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status != 'cancelled' AND DATE(created_at) BETWEEN ? AND ?");
$commStmt->execute([$from, $to]);
$commTotal = (float) $commStmt->fetchColumn();
$mStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM income_matching WHERE status != 'cancelled' AND DATE(created_at) BETWEEN ? AND ?");
$mStmt->execute([$from, $to]);
$commTotal += (float) $mStmt->fetchColumn();
$dStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM income_dsi WHERE status != 'cancelled' AND DATE(created_at) BETWEEN ? AND ?");
$dStmt->execute([$from, $to]);
$commTotal += (float) $dStmt->fetchColumn();

$wdTotal = 0.0;
$pendingWdCount = 0;
$pendingWdSum = 0.0;
if ($showWithdrawals) {
    require_once __DIR__ . '/../includes/withdrawal.php';
    wd_ensure_columns($pdo);
    $netExpr = wd_net_sql_expr('w');
    $wdStmt = $pdo->prepare("SELECT COALESCE(SUM({$netExpr}),0) FROM withdrawals w WHERE w.status IN ('approved','paid') AND DATE(COALESCE(w.processed_at, w.requested_at)) BETWEEN ? AND ?");
    $wdStmt->execute([$from, $to]);
    $wdTotal = (float) $wdStmt->fetchColumn();

    $pendingWdStmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount),0) FROM withdrawals WHERE status = 'pending' AND DATE(requested_at) BETWEEN ? AND ?");
    $pendingWdStmt->execute([$from, $to]);
    $pendingWdRow = $pendingWdStmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
    $pendingWdCount = (int) $pendingWdRow[0];
    $pendingWdSum = (float) $pendingWdRow[1];
}
$net = $commTotal - $wdTotal;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>Reports Overview</h2>
            <p class="tpin-panel-sub">Period summary — open detailed reports from the links below</p>
        </div>
    </div>
    <div class="panel-body">
        <?php report_filter_form('reports.php', $from, $to); ?>

        <div class="stats-grid tpin-stats">
            <div class="stat-card accent">
                <div class="label">New Joins</div>
                <div class="value"><?= $joins ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Commissions</div>
                <div class="value" style="font-size:1.25rem"><?= currency($commTotal) ?></div>
            </div>
            <?php if ($showWithdrawals): ?>
            <div class="stat-card">
                <div class="label">Net Payouts</div>
                <div class="value" style="font-size:1.25rem"><?= currency($wdTotal) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Net</div>
                <div class="value" style="font-size:1.25rem"><?= currency($net) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($showWithdrawals): ?>
        <div class="alert alert-info" style="margin-top:1rem">
            Pending withdrawals: <strong><?= $pendingWdCount ?></strong> · <?= currency($pendingWdSum) ?>
            · <a href="withdrawals.php?status=pending">Review payouts</a>
        </div>
        <?php endif; ?>

        <div class="tpin-gen-grid" style="margin-top:1rem">
            <a class="btn btn-outline" href="report-commission.php?from=<?= e($from) ?>&to=<?= e($to) ?>">Commission Report</a>
            <?php if (feature_enabled('feature_dsi_income')): ?>
            <a class="btn btn-outline" href="report-dsi.php?from=<?= e($from) ?>&to=<?= e($to) ?>">DSI Report</a>
            <?php endif; ?>
            <?php if (feature_enabled('feature_matching_income') && plan_uses_binary()): ?>
            <a class="btn btn-outline" href="report-matching.php?from=<?= e($from) ?>&to=<?= e($to) ?>">Matching Report</a>
            <?php endif; ?>
            <a class="btn btn-outline" href="report-joining.php?from=<?= e($from) ?>&to=<?= e($to) ?>">Joining Report</a>
            <?php if ($showPackages): ?>
            <a class="btn btn-outline" href="report-package-sales.php?from=<?= e($from) ?>&to=<?= e($to) ?>">Package Sales</a>
            <?php endif; ?>
            <a class="btn btn-outline" href="report-top-earners.php?from=<?= e($from) ?>&to=<?= e($to) ?>">Top Earners</a>
            <?php if ($showBinaryClosing): ?>
            <a class="btn btn-outline" href="report-binary-closing.php?from=<?= e($from) ?>&to=<?= e($to) ?>">Binary Closing</a>
            <?php endif; ?>
            <a class="btn btn-outline" href="weekly-reconciliation.php">Weekly Reconciliation</a>
            <a class="btn btn-outline" href="tds-report.php?from=<?= e($from) ?>&to=<?= e($to) ?>">TDS Report</a>
            <?php if ($showTpin): ?>
            <a class="btn btn-outline" href="tpin-report.php">T-Pin Report</a>
            <?php endif; ?>
            <?php if ($showProducts): ?>
            <a class="btn btn-outline" href="stock-report.php">Stock Report</a>
            <a class="btn btn-outline" href="product-orders.php">Product Orders</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
