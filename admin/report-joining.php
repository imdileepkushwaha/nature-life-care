<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/report_helpers.php';
$pageTitle = 'Joining Report';

[$from, $to] = report_parse_dates();
$q = trim((string) ($_GET['q'] ?? ''));
$packageId = (int) ($_GET['package_id'] ?? 0);
$status = trim((string) ($_GET['status'] ?? ''));

$packages = $pdo->query("SELECT id, name FROM packages ORDER BY amount ASC")->fetchAll();

$where = ['DATE(m.join_date) BETWEEN ? AND ?'];
$params = [$from, $to];
if ($packageId > 0) {
    $where[] = 'm.package_id = ?';
    $params[] = $packageId;
}
if ($status !== '' && in_array($status, ['active', 'inactive', 'blocked'], true)) {
    $where[] = 'm.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(m.member_id LIKE ? OR m.full_name LIKE ? OR m.username LIKE ? OR m.email LIKE ? OR m.phone LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sql = '
    SELECT m.*, p.name AS package_name, p.amount AS package_amount,
           s.member_id AS sponsor_code, s.full_name AS sponsor_name
    FROM members m
    LEFT JOIN packages p ON p.id = m.package_id
    LEFT JOIN members s ON s.id = m.sponsor_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY m.join_date DESC, m.id DESC
    LIMIT 500
';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>Joining Report</h2>
            <p class="tpin-panel-sub">New members by date, package and status</p>
        </div>
        <a href="members.php" class="btn btn-outline btn-sm">Members</a>
    </div>
    <div class="panel-body">
        <?php
        $pkgOpts = ['' => 'All packages'];
        foreach ($packages as $p) {
            $pkgOpts[(string) $p['id']] = $p['name'];
        }
        report_filter_form('report-joining.php', $from, $to, [
            ['name' => 'q', 'label' => 'Search', 'type' => 'text', 'value' => $q, 'placeholder' => 'Member ID / name / email / phone', 'wide' => true],
            ['name' => 'package_id', 'label' => 'Package', 'type' => 'select', 'value' => $packageId > 0 ? (string) $packageId : '', 'options' => $pkgOpts],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'value' => $status, 'options' => [
                '' => 'All status', 'active' => 'Active', 'inactive' => 'Inactive', 'blocked' => 'Blocked',
            ]],
        ]);
        ?>
        <div class="stats-grid tpin-stats">
            <div class="stat-card accent"><div class="label">Joins</div><div class="value"><?= count($rows) ?></div></div>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data tpin-table">
            <thead>
            <tr>
                <th>Join date</th>
                <th>Member</th>
                <th>Package</th>
                <th>Sponsor</th>
                <th>Phone</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" style="text-align:center;padding:1.2rem;color:#64748b">No joins found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= e(date('d M Y H:i', strtotime((string) $r['join_date']))) ?></td>
                    <td>
                        <a href="member-view.php?id=<?= (int) $r['id'] ?>"><strong><?= e($r['full_name']) ?></strong></a>
                        <span class="tpin-meta"><?= e($r['member_id']) ?></span>
                    </td>
                    <td>
                        <?= e($r['package_name'] ?? '—') ?>
                        <?php if (!empty($r['package_amount'])): ?>
                            <span class="tpin-meta"><?= currency((float) $r['package_amount']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= !empty($r['sponsor_code']) ? e($r['sponsor_name'] . ' · ' . $r['sponsor_code']) : '—' ?></td>
                    <td><?= e((string) ($r['phone'] ?? '—')) ?></td>
                    <td><?= status_badge((string) $r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
