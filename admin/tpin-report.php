<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/tpin.php';

$pageTitle = 'T-Pin Report';
tpin_ensure_tables($pdo);

$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$pkgFilter = (int) ($_GET['package_id'] ?? 0);
$fromDate = trim((string) ($_GET['from'] ?? ''));
$toDate = trim((string) ($_GET['to'] ?? ''));
$tab = trim((string) ($_GET['tab'] ?? 'pins'));
if (!in_array($tab, ['pins', 'transfers'], true)) {
    $tab = 'pins';
}

$packages = $pdo->query("SELECT id, name, amount FROM packages WHERE status = 'active' ORDER BY amount ASC")->fetchAll();

$counts = ['unused' => 0, 'used' => 0, 'blocked' => 0];
try {
    foreach ($pdo->query('SELECT status, COUNT(*) c FROM topup_pins GROUP BY status') as $c) {
        $counts[(string) $c['status']] = (int) $c['c'];
    }
} catch (Throwable $e) {
    // ignore
}
$totalPins = $counts['unused'] + $counts['used'] + $counts['blocked'];
$transferTotal = 0;
try {
    $transferTotal = (int) $pdo->query('SELECT COUNT(*) FROM topup_pin_transfers')->fetchColumn();
} catch (Throwable $e) {
    $transferTotal = 0;
}

$companyStock = 0;
try {
    $companyStock = (int) $pdo->query("SELECT COUNT(*) FROM topup_pins WHERE status = 'unused' AND assigned_to IS NULL")->fetchColumn();
} catch (Throwable $e) {
    $companyStock = 0;
}

// Pin report rows
$where = ['1=1'];
$params = [];
if (in_array($status, ['unused', 'used', 'blocked'], true)) {
    $where[] = 'tp.status = ?';
    $params[] = $status;
}
if ($pkgFilter > 0) {
    $where[] = 'tp.package_id = ?';
    $params[] = $pkgFilter;
}
if ($fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $where[] = 'DATE(tp.created_at) >= ?';
    $params[] = $fromDate;
}
if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $where[] = 'DATE(tp.created_at) <= ?';
    $params[] = $toDate;
}
if ($q !== '') {
    $where[] = '(tp.pin_code LIKE ? OR tp.batch_code LIKE ? OR am.member_id LIKE ? OR um.member_id LIKE ? OR am.full_name LIKE ? OR um.full_name LIKE ?)';
    $likeCode = '%' . tpin_normalize_code($q) . '%';
    $like = '%' . $q . '%';
    array_push($params, $likeCode, $like, $like, $like, $like, $like);
}

$pinSql = '
    SELECT tp.*, p.name AS package_name, p.amount AS package_amount,
           am.member_id AS assigned_code, am.full_name AS assigned_name,
           um.member_id AS used_code, um.full_name AS used_name
    FROM topup_pins tp
    JOIN packages p ON p.id = tp.package_id
    LEFT JOIN members am ON am.id = tp.assigned_to
    LEFT JOIN members um ON um.id = tp.used_by
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY tp.id DESC
    LIMIT 500
';
$pinStmt = $pdo->prepare($pinSql);
$pinStmt->execute($params);
$pinRows = $pinStmt->fetchAll();

$transferRows = tpin_admin_transfers($pdo, [
    'q' => $q,
    'from' => $fromDate,
    'to' => $toDate,
    'package_id' => $pkgFilter,
], 500);

$hasFilter = $q !== '' || $status !== '' || $pkgFilter > 0 || $fromDate !== '' || $toDate !== '';

require_once __DIR__ . '/../includes/header.php';
$flash = get_flash();
?>

<div class="stats-grid tpin-stats">
    <div class="stat-card accent">
        <div class="label">Unused</div>
        <div class="value"><?= (int) $counts['unused'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Used</div>
        <div class="value"><?= (int) $counts['used'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Blocked</div>
        <div class="value"><?= (int) $counts['blocked'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Company stock</div>
        <div class="value"><?= (int) $companyStock ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Total pins</div>
        <div class="value"><?= (int) $totalPins ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Transfers</div>
        <div class="value"><?= (int) $transferTotal ?></div>
    </div>
</div>

<div class="panel tpin-panel">
    <div class="panel-header">
        <div>
            <h2>T-Pin Report</h2>
            <p class="tpin-panel-sub">Stock status, usage and transfer history</p>
        </div>
        <div class="tpin-status-pills">
            <a class="tpin-pill<?= $tab === 'pins' ? ' is-on' : '' ?>" href="tpin-report.php?<?= e(http_build_query(array_filter(['tab' => 'pins', 'q' => $q, 'status' => $status, 'package_id' => $pkgFilter ?: null, 'from' => $fromDate, 'to' => $toDate]))) ?>">Pin stock</a>
            <a class="tpin-pill<?= $tab === 'transfers' ? ' is-on' : '' ?>" href="tpin-report.php?<?= e(http_build_query(array_filter(['tab' => 'transfers', 'q' => $q, 'package_id' => $pkgFilter ?: null, 'from' => $fromDate, 'to' => $toDate]))) ?>">Transfers</a>
        </div>
    </div>
    <div class="panel-body tpin-stock-body">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <form class="tpin-filters" method="get">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <div class="form-group tpin-filter-search">
                <label for="tpin_q">Search</label>
                <input type="text" name="q" id="tpin_q" value="<?= e($q) ?>" placeholder="Pin, batch, member ID or name">
            </div>
            <?php if ($tab === 'pins'): ?>
            <div class="form-group">
                <label for="tpin_status">Status</label>
                <select name="status" id="tpin_status">
                    <option value="">All</option>
                    <option value="unused" <?= $status === 'unused' ? 'selected' : '' ?>>Unused</option>
                    <option value="used" <?= $status === 'used' ? 'selected' : '' ?>>Used</option>
                    <option value="blocked" <?= $status === 'blocked' ? 'selected' : '' ?>>Blocked</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label for="tpin_pkg">Package</label>
                <select name="package_id" id="tpin_pkg">
                    <option value="0">All packages</option>
                    <?php foreach ($packages as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $pkgFilter === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="from">From date</label>
                <input type="date" name="from" id="from" value="<?= e($fromDate) ?>">
            </div>
            <div class="form-group">
                <label for="to">To date</label>
                <input type="date" name="to" id="to" value="<?= e($toDate) ?>">
            </div>
            <div class="tpin-filter-actions">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="tpin-report.php?tab=<?= e($tab) ?>" class="btn btn-outline">Reset</a>
            </div>
        </form>
    </div>

    <?php if ($tab === 'transfers'): ?>
        <?php if (!$transferRows): ?>
            <div class="act-empty">
                <strong><?= $hasFilter ? 'No matching transfers' : 'No transfers yet' ?></strong>
                <p><?= $hasFilter ? 'Try clearing filters.' : 'Transfers appear when pins move between members.' ?></p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="data tpin-table">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>T-Pin</th>
                    <th>Package</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Pin status now</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($transferRows as $t): ?>
                    <tr>
                        <td><?= e(date('d M Y H:i', strtotime((string) $t['created_at']))) ?></td>
                        <td><code class="tpin-batch-code"><?= e(tpin_format_code((string) $t['pin_code'])) ?></code></td>
                        <td>
                            <strong class="tpin-pkg-name"><?= e($t['package_name']) ?></strong>
                            <span class="tpin-pkg-amt"><?= currency((float) $t['package_amount']) ?></span>
                        </td>
                        <td>
                            <strong><?= e($t['from_name']) ?></strong>
                            <span class="tpin-meta"><?= e($t['from_code']) ?></span>
                        </td>
                        <td>
                            <strong><?= e($t['to_name']) ?></strong>
                            <span class="tpin-meta"><?= e($t['to_code']) ?></span>
                        </td>
                        <td><span class="tpin-badge tpin-badge-<?= e((string) $t['pin_status']) ?>"><?= e((string) $t['pin_status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="tpin-panel-sub" style="padding:.75rem 1.25rem 1.25rem">Showing <?= count($transferRows) ?> transfer(s)</p>
        <?php endif; ?>
    <?php else: ?>
        <?php if (!$pinRows): ?>
            <div class="act-empty">
                <strong><?= $hasFilter ? 'No matching T-Pins' : 'No T-Pins yet' ?></strong>
                <p><?= $hasFilter ? 'Try clearing filters.' : 'Generate pins from Add T-Pin.' ?></p>
                <?php if (!$hasFilter): ?>
                    <a href="tpin.php" class="btn btn-primary btn-sm">Add T-Pin</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="data tpin-table">
                <thead>
                <tr>
                    <th>Pin</th>
                    <th>Package</th>
                    <th>Status</th>
                    <th>Assigned</th>
                    <th>Used by</th>
                    <th>Batch</th>
                    <th>Created</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pinRows as $r):
                    $code = tpin_format_code((string) $r['pin_code']);
                    $st = (string) $r['status'];
                ?>
                    <tr>
                        <td><code class="tpin-batch-code"><?= e($code) ?></code></td>
                        <td>
                            <strong class="tpin-pkg-name"><?= e($r['package_name']) ?></strong>
                            <span class="tpin-pkg-amt"><?= currency((float) $r['package_amount']) ?></span>
                        </td>
                        <td><span class="tpin-badge tpin-badge-<?= e($st) ?>"><?= e($st) ?></span></td>
                        <td>
                            <?php if (!empty($r['assigned_code'])): ?>
                                <strong><?= e($r['assigned_name']) ?></strong>
                                <span class="tpin-meta"><?= e($r['assigned_code']) ?></span>
                            <?php else: ?>
                                <span class="tpin-stock-tag">Company stock</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($r['used_code'])): ?>
                                <strong><?= e($r['used_name']) ?></strong>
                                <span class="tpin-meta"><?= e($r['used_code']) ?><?= !empty($r['used_at']) ? ' · ' . e(date('d M Y H:i', strtotime((string) $r['used_at']))) : '' ?></span>
                            <?php else: ?>
                                <span class="tpin-dash">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="tpin-batch-code"><?= e($r['batch_code'] ?? '—') ?></span></td>
                        <td><?= e(date('d M Y', strtotime((string) $r['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="tpin-panel-sub" style="padding:.75rem 1.25rem 1.25rem">Showing <?= count($pinRows) ?> pin(s)</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
