<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/report_helpers.php';
$pageTitle = 'Package Sales';

[$from, $to] = report_parse_dates();
$packageId = (int) ($_GET['package_id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));

$allPackages = $pdo->query("SELECT id, name, amount, status FROM packages ORDER BY amount ASC")->fetchAll();

$where = ['1=1'];
$params = [];
if ($packageId > 0) {
    $where[] = 'p.id = ?';
    $params[] = $packageId;
}
if ($q !== '') {
    $where[] = 'p.name LIKE ?';
    $params[] = '%' . $q . '%';
}

$sql = '
    SELECT p.id, p.name, p.amount, p.status,
           COUNT(m.id) AS cnt,
           COALESCE(SUM(p.amount),0) AS revenue
    FROM packages p
    LEFT JOIN members m ON m.package_id = p.id AND DATE(m.join_date) BETWEEN ? AND ?
    WHERE ' . implode(' AND ', $where) . '
    GROUP BY p.id, p.name, p.amount, p.status
    ORDER BY revenue DESC, p.amount ASC
';
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$from, $to], $params));
$rows = $stmt->fetchAll();

$totalMembers = 0;
$totalRevenue = 0.0;
foreach ($rows as $r) {
    $totalMembers += (int) $r['cnt'];
    $totalRevenue += (float) $r['revenue'];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>Package Sales</h2>
            <p class="tpin-panel-sub">Members joined on each package in the period</p>
        </div>
        <a href="packages.php" class="btn btn-outline btn-sm">Packages</a>
    </div>
    <div class="panel-body">
        <?php
        $pkgOpts = ['' => 'All packages'];
        foreach ($allPackages as $p) {
            $pkgOpts[(string) $p['id']] = $p['name'];
        }
        report_filter_form('report-package-sales.php', $from, $to, [
            ['name' => 'q', 'label' => 'Search package', 'type' => 'text', 'value' => $q, 'placeholder' => 'Package name'],
            ['name' => 'package_id', 'label' => 'Package', 'type' => 'select', 'value' => $packageId > 0 ? (string) $packageId : '', 'options' => $pkgOpts],
        ]);
        ?>
        <div class="stats-grid tpin-stats">
            <div class="stat-card accent"><div class="label">Members</div><div class="value"><?= $totalMembers ?></div></div>
            <div class="stat-card"><div class="label">Revenue</div><div class="value" style="font-size:1.2rem"><?= currency($totalRevenue) ?></div></div>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data tpin-table">
            <thead>
            <tr>
                <th>Package</th>
                <th>Price</th>
                <th>Status</th>
                <th>Members (period)</th>
                <th>Revenue</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" style="text-align:center;padding:1.2rem;color:#64748b">No packages found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= currency((float) $r['amount']) ?></td>
                    <td><?= status_badge((string) $r['status']) ?></td>
                    <td><?= (int) $r['cnt'] ?></td>
                    <td><strong><?= currency((float) $r['revenue']) ?></strong></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
