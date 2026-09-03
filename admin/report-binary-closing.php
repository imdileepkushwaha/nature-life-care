<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/report_helpers.php';
$pageTitle = 'Binary Closing';

[$from, $to] = report_parse_dates();
$q = trim((string) ($_GET['q'] ?? ''));

$where = ['DATE(cr.created_at) BETWEEN ? AND ?'];
$params = [$from, $to];
if ($q !== '') {
    $where[] = '(cr.id = ? OR cr.notes LIKE ?)';
    $params[] = (int) $q;
    $params[] = '%' . $q . '%';
}

$closingSum = ['runs' => 0, 'pairs' => 0.0, 'binary_net' => 0.0, 'matching' => 0.0];
$rows = [];
try {
    $agg = $pdo->prepare('
        SELECT COUNT(*) AS runs,
               COALESCE(SUM(pairs_total),0) AS pairs,
               COALESCE(SUM(binary_net_total),0) AS binary_net,
               COALESCE(SUM(matching_total),0) AS matching
        FROM closing_runs cr
        WHERE ' . implode(' AND ', $where)
    );
    $agg->execute($params);
    $a = $agg->fetch() ?: [];
    $closingSum = [
        'runs' => (int) ($a['runs'] ?? 0),
        'pairs' => (float) ($a['pairs'] ?? 0),
        'binary_net' => (float) ($a['binary_net'] ?? 0),
        'matching' => (float) ($a['matching'] ?? 0),
    ];

    $stmt = $pdo->prepare('
        SELECT cr.*
        FROM closing_runs cr
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY cr.id DESC
        LIMIT 300
    ');
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = [];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>Binary Closing Report</h2>
            <p class="tpin-panel-sub">Closing runs by date — search by run ID or notes</p>
        </div>
        <a href="binary-closing.php" class="btn btn-outline btn-sm">Closing desk</a>
    </div>
    <div class="panel-body">
        <?php
        report_filter_form('report-binary-closing.php', $from, $to, [
            ['name' => 'q', 'label' => 'Search', 'type' => 'text', 'value' => $q, 'placeholder' => 'Run ID / notes'],
        ]);
        ?>
        <div class="stats-grid tpin-stats">
            <div class="stat-card accent"><div class="label">Runs</div><div class="value"><?= (int) $closingSum['runs'] ?></div></div>
            <div class="stat-card"><div class="label">Pairs</div><div class="value" style="font-size:1.2rem"><?= number_format((float) $closingSum['pairs'], 2) ?></div></div>
            <div class="stat-card"><div class="label">Binary net</div><div class="value" style="font-size:1.1rem"><?= currency((float) $closingSum['binary_net']) ?></div></div>
            <div class="stat-card"><div class="label">Matching</div><div class="value" style="font-size:1.1rem"><?= currency((float) $closingSum['matching']) ?></div></div>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data tpin-table">
            <thead>
            <tr>
                <th>Run #</th>
                <th>Date</th>
                <th>Members paid</th>
                <th>Pairs</th>
                <th>Binary net</th>
                <th>Matching</th>
                <th>Notes</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" style="text-align:center;padding:1.2rem;color:#64748b">No closings found.</td></tr>
            <?php else: foreach ($rows as $cr): ?>
                <tr>
                    <td><strong>#<?= (int) $cr['id'] ?></strong></td>
                    <td><?= e(date('d M Y H:i', strtotime((string) $cr['created_at']))) ?></td>
                    <td><?= (int) $cr['members_paid'] ?></td>
                    <td><?= number_format((float) $cr['pairs_total'], 2) ?></td>
                    <td><strong><?= currency((float) $cr['binary_net_total']) ?></strong></td>
                    <td><?= currency((float) $cr['matching_total']) ?></td>
                    <td><?= e((string) ($cr['notes'] ?? '—')) ?></td>
                    <td><a class="btn btn-outline btn-sm" href="binary-closing.php?run=<?= (int) $cr['id'] ?>">View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
