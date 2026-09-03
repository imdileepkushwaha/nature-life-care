<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/report_helpers.php';
$pageTitle = 'Top Earners';

[$from, $to] = report_parse_dates();
$q = trim((string) ($_GET['q'] ?? ''));
$packageId = (int) ($_GET['package_id'] ?? 0);
$status = trim((string) ($_GET['status'] ?? ''));
$minEarn = trim((string) ($_GET['min_earn'] ?? ''));
$minEarnVal = is_numeric($minEarn) ? (float) $minEarn : null;

$packages = $pdo->query('SELECT id, name FROM packages ORDER BY amount ASC')->fetchAll();

$where = ['1=1'];
$params = [$from, $to]; // for period commissions subquery

if ($packageId > 0) {
    $where[] = 'm.package_id = ?';
    $params[] = $packageId;
}
if ($status !== '' && in_array($status, ['active', 'inactive', 'blocked'], true)) {
    $where[] = 'm.status = ?';
    $params[] = $status;
}
if ($minEarnVal !== null) {
    $where[] = 'm.total_earnings >= ?';
    $params[] = $minEarnVal;
}
if ($q !== '') {
    $where[] = '(m.member_id LIKE ? OR m.full_name LIKE ? OR m.username LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$sql = '
    SELECT m.id, m.member_id, m.full_name, m.wallet_balance, m.status, m.package_id,
           p.name AS package_name,
           m.total_earnings,
           COALESCE(pe.period_earn, 0) AS period_earn
    FROM members m
    LEFT JOIN packages p ON p.id = m.package_id
    LEFT JOIN (
        SELECT member_id, COALESCE(SUM(amount),0) AS period_earn
        FROM commissions
        WHERE status != \'cancelled\' AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY member_id
    ) pe ON pe.member_id = m.id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY period_earn DESC, m.total_earnings DESC
    LIMIT 200
';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>Top Earners</h2>
            <p class="tpin-panel-sub">Leaderboard with search, package and minimum earnings filters</p>
        </div>
    </div>
    <div class="panel-body">
        <?php
        $pkgOpts = ['' => 'All packages'];
        foreach ($packages as $p) {
            $pkgOpts[(string) $p['id']] = $p['name'];
        }
        report_filter_form('report-top-earners.php', $from, $to, [
            ['name' => 'q', 'label' => 'Search', 'type' => 'text', 'value' => $q, 'placeholder' => 'Member ID / name', 'wide' => true],
            ['name' => 'package_id', 'label' => 'Package', 'type' => 'select', 'value' => $packageId > 0 ? (string) $packageId : '', 'options' => $pkgOpts],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'value' => $status, 'options' => [
                '' => 'All status', 'active' => 'Active', 'inactive' => 'Inactive', 'blocked' => 'Blocked',
            ]],
            ['name' => 'min_earn', 'label' => 'Min earnings', 'type' => 'number', 'value' => $minEarn, 'placeholder' => '0'],
        ]);
        ?>
        <div class="stats-grid tpin-stats">
            <div class="stat-card accent"><div class="label">Showing</div><div class="value"><?= count($rows) ?></div></div>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data tpin-table">
            <thead>
            <tr>
                <th>#</th>
                <th>Member</th>
                <th>Package</th>
                <th>Period earn</th>
                <th>Total earnings</th>
                <th>Wallet</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" style="text-align:center;padding:1.2rem;color:#64748b">No members found.</td></tr>
            <?php else: foreach ($rows as $i => $e): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td>
                        <a href="member-view.php?id=<?= (int) $e['id'] ?>"><strong><?= e($e['full_name']) ?></strong></a>
                        <span class="tpin-meta"><?= e($e['member_id']) ?></span>
                    </td>
                    <td><?= e($e['package_name'] ?? '—') ?></td>
                    <td><strong><?= currency((float) ($e['period_earn'] ?? 0)) ?></strong></td>
                    <td><?= currency((float) $e['total_earnings']) ?></td>
                    <td><?= currency((float) $e['wallet_balance']) ?></td>
                    <td><?= status_badge((string) $e['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
