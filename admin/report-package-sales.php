<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/report_helpers.php';
require_once __DIR__ . '/../includes/activation.php';
$pageTitle = 'Package Sales';

[$from, $to] = report_parse_dates();
$packageId = (int) ($_GET['package_id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));

activation_ensure_requests_table($pdo);

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

// Sales dated by activation/upgrade approval; legacy members without request fall back to join_date.
$sql = '
    SELECT p.id, p.name, p.amount, p.status,
           COUNT(s.sale_id) AS cnt,
           COALESCE(COUNT(s.sale_id), 0) * p.amount AS revenue
    FROM packages p
    LEFT JOIN (
        SELECT ar.id AS sale_id, ar.package_id, COALESCE(ar.processed_at, ar.created_at) AS sale_at
        FROM activation_requests ar
        WHERE ar.status = \'approved\' AND ar.package_id IS NOT NULL
        UNION ALL
        SELECT m.id AS sale_id, m.package_id, m.join_date AS sale_at
        FROM members m
        WHERE m.package_id IS NOT NULL
          AND NOT EXISTS (
              SELECT 1 FROM activation_requests ar2
              WHERE ar2.member_id = m.id AND ar2.status = \'approved\' AND ar2.package_id IS NOT NULL
          )
    ) s ON s.package_id = p.id AND DATE(s.sale_at) BETWEEN ? AND ?
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
            <p class="tpin-panel-sub">Approved activations / upgrades in the period (catalog price × sales)</p>
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
            <div class="stat-card accent"><div class="label">Sales</div><div class="value"><?= $totalMembers ?></div></div>
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
                <th>Sales (period)</th>
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
