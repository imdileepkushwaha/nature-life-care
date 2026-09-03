<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/activation.php';

$pageTitle = 'Activations';
activation_ensure_requests_table($pdo);

$adminId = (int) ($_SESSION['admin_id'] ?? 0);

/**
 * Keep current list filters in query string (for redirects / pagination).
 * @return array<string, string>
 */
function activations_filter_qs(array $extra = []): array
{
    $qs = [];
    $status = (string) ($_GET['status'] ?? '');
    if ($status !== '') {
        $qs['status'] = $status;
    }
    $reqId = trim((string) ($_GET['req_id'] ?? ''));
    if ($reqId !== '') {
        $qs['req_id'] = $reqId;
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $qs['q'] = $q;
    }
    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $qs['date_from'] = $dateFrom;
    }
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    if ($dateTo !== '') {
        $qs['date_to'] = $dateTo;
    }
    $type = trim((string) ($_GET['type'] ?? ''));
    if ($type !== '') {
        $qs['type'] = $type;
    }
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '') {
            unset($qs[$k]);
        } else {
            $qs[$k] = (string) $v;
        }
    }
    return $qs;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $note = trim((string) ($_POST['admin_note'] ?? ''));

    if ($action === 'approve') {
        $result = activation_approve_request($pdo, $id, $adminId, $note);
        if ($result['ok']) {
            log_activity('activation_approve', "Approved activation request #$id");
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
    } elseif ($action === 'reject') {
        $result = activation_reject_request($pdo, $id, $adminId, $note !== '' ? $note : 'Rejected by admin');
        if ($result['ok']) {
            log_activity('activation_reject', "Rejected activation request #$id");
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
    }

    $qs = activations_filter_qs();
    if (!empty($_GET['page'])) {
        $qs['page'] = (string) (int) $_GET['page'];
    }
    $redir = 'activations.php';
    if ($qs) {
        $redir .= '?' . http_build_query($qs);
    }
    header('Location: ' . $redir);
    exit;
}

$statusFilter = (string) ($_GET['status'] ?? 'pending');
if (!in_array($statusFilter, ['', 'pending', 'approved', 'rejected'], true)) {
    $statusFilter = 'pending';
}

$typeFilter = (string) ($_GET['type'] ?? '');
if (!in_array($typeFilter, ['', 'activation', 'upgrade'], true)) {
    $typeFilter = '';
}

$reqIdFilter = trim((string) ($_GET['req_id'] ?? ''));
$nameFilter = trim((string) ($_GET['q'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$where = ['1=1'];
$params = [];

if ($statusFilter !== '') {
    $where[] = 'r.status = ?';
    $params[] = $statusFilter;
}

if ($typeFilter !== '') {
    $where[] = 'COALESCE(r.request_type, \'activation\') = ?';
    $params[] = $typeFilter;
}

if ($reqIdFilter !== '' && ctype_digit($reqIdFilter)) {
    $where[] = 'r.id = ?';
    $params[] = (int) $reqIdFilter;
}

if ($nameFilter !== '') {
    $like = '%' . $nameFilter . '%';
    $where[] = '(m.full_name LIKE ? OR m.member_id LIKE ? OR m.username LIKE ? OR m.email LIKE ? OR m.phone LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}

if ($dateFrom !== '') {
    $where[] = 'DATE(r.created_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'DATE(r.created_at) <= ?';
    $params[] = $dateTo;
}

$whereSql = implode(' AND ', $where);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM activation_requests r
    JOIN members m ON m.id = r.member_id
    WHERE $whereSql
");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $pdo->prepare("
    SELECT r.*, m.full_name, m.member_id AS mid, m.email, m.phone, m.package_id AS current_package_id,
           p.name AS package_name, fp.name AS from_package_name
    FROM activation_requests r
    JOIN members m ON m.id = r.member_id
    LEFT JOIN packages p ON p.id = r.package_id
    LEFT JOIN packages fp ON fp.id = r.from_package_id
    WHERE $whereSql
    ORDER BY FIELD(r.status, 'pending', 'approved', 'rejected'), r.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pendingCount = activation_pending_count($pdo);
$pendingSum = 0.0;
try {
    $pendingSum = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM activation_requests WHERE status = 'pending'")->fetchColumn();
} catch (Throwable $e) {
    $pendingSum = 0.0;
}
$approvedCount = 0;
try {
    $approvedCount = (int) $pdo->query("SELECT COUNT(*) FROM activation_requests WHERE status = 'approved'")->fetchColumn();
} catch (Throwable $e) {
    $approvedCount = 0;
}

$baseQs = activations_filter_qs([
    'status' => $statusFilter,
    'type' => $typeFilter,
    'req_id' => $reqIdFilter,
    'q' => $nameFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
]);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card accent"><div class="label">Pending Requests</div><div class="value"><?= $pendingCount ?></div></div>
    <div class="stat-card"><div class="label">Pending Amount</div><div class="value"><?= currency($pendingSum) ?></div></div>
    <div class="stat-card"><div class="label">Approved (all time)</div><div class="value"><?= $approvedCount ?></div></div>
</div>

<div class="panel">
    <div class="panel-header">
        <h2>Activations &amp; Upgrades (<?= $total ?>)</h2>
    </div>
    <div class="panel-body">
        <form class="filters" method="get">
            <div class="form-group">
                <label>Request ID</label>
                <input type="text" name="req_id" value="<?= e($reqIdFilter) ?>" placeholder="e.g. 12" inputmode="numeric">
            </div>
            <div class="form-group">
                <label>Name / Member ID</label>
                <input type="text" name="q" value="<?= e($nameFilter) ?>" placeholder="Name, ID, phone, email">
            </div>
            <div class="form-group">
                <label>From date</label>
                <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
            </div>
            <div class="form-group">
                <label>To date</label>
                <input type="date" name="date_to" value="<?= e($dateTo) ?>">
            </div>
            <div class="form-group">
                <label>Type</label>
                <select name="type">
                    <option value="">All</option>
                    <option value="activation" <?= $typeFilter === 'activation' ? 'selected' : '' ?>>Activation</option>
                    <option value="upgrade" <?= $typeFilter === 'upgrade' ? 'selected' : '' ?>>Upgrade</option>
                </select>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="">All</option>
                    <?php foreach (['pending', 'approved', 'rejected'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="activations.php" class="btn btn-outline">Reset</a>
        </form>
        <p class="muted" style="margin:0.75rem 0 0;font-size:0.85rem">Approve only after verifying UTR / payment. Activation assigns the package; upgrade takes difference amount only and credits difference BV / commissions.</p>
    </div>
    <?php
    $hasSearchFilters = $reqIdFilter !== '' || $nameFilter !== '' || $dateFrom !== '' || $dateTo !== '' || $typeFilter !== '';
    $hasAnyFilter = $hasSearchFilters || $statusFilter !== '';
    ?>
    <?php if (!$rows): ?>
    <div class="act-empty">
        <div class="act-empty-visual" aria-hidden="true">
            <span class="act-empty-ring"></span>
            <span class="act-empty-ico">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/></svg>
            </span>
        </div>
        <strong><?= $hasAnyFilter ? 'No matching requests' : 'No requests yet' ?></strong>
        <p><?= $hasAnyFilter
            ? 'Nothing matches your ID, name, date or status filters. Try adjusting or clearing them.'
            : 'When members submit activation or upgrade payments, their requests will appear here for approval.' ?></p>
        <?php if ($hasAnyFilter): ?>
            <a href="activations.php" class="btn btn-primary btn-sm">Clear filters</a>
        <?php else: ?>
            <a href="activations.php?status=pending" class="btn btn-outline btn-sm">View pending</a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Member</th>
                    <th>Package</th>
                    <th>Payable</th>
                    <th>Payment</th>
                    <th>UTR / Ref</th>
                    <th>Slip</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $isUpg = (($r['request_type'] ?? 'activation') === 'upgrade');
            ?>
                <tr>
                    <td>#<?= (int) $r['id'] ?></td>
                    <td>
                        <span class="badge badge-<?= $isUpg ? 'approved' : 'pending' ?>"><?= $isUpg ? 'Upgrade' : 'Activation' ?></span>
                    </td>
                    <td>
                        <a href="member-view.php?id=<?= (int) $r['member_id'] ?>"><?= e($r['full_name']) ?></a><br>
                        <small><?= e($r['mid']) ?><?= !empty($r['phone']) ? ' · ' . e($r['phone']) : '' ?></small>
                    </td>
                    <td>
                        <?php if ($isUpg && !empty($r['from_package_name'])): ?>
                            <small><?= e($r['from_package_name']) ?> →</small><br>
                        <?php endif; ?>
                        <?= e($r['package_name'] ?? '—') ?>
                    </td>
                    <td>
                        <strong><?= currency((float) $r['amount']) ?></strong>
                        <?php if ($isUpg): ?><br><small>difference</small><?php endif; ?>
                    </td>
                    <td><?= e($r['payment_method'] ?? '—') ?></td>
                    <td>
                        <strong><?= e($r['utr_reference'] ?? '—') ?></strong>
                        <?php if (!empty($r['note'])): ?>
                            <br><small><?= e($r['note']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $slipUrl = activation_slip_url($r['payment_slip'] ?? null);
                        if ($slipUrl):
                        ?>
                        <a href="<?= e($slipUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn-icon" title="View slip" aria-label="View slip"><?= icon_svg('view') ?></a>
                        <?php else: ?>
                        —
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $r['status']) ?></td>
                    <td><?= !empty($r['created_at']) ? date('d M Y H:i', strtotime((string) $r['created_at'])) : '—' ?></td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                        <form method="post" class="action-icons" style="flex-wrap:nowrap">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <input type="text" name="admin_note" placeholder="Note" class="act-note-input" style="width:90px;padding:0.3rem;font-size:0.8rem;border:1px solid var(--border);border-radius:6px">
                            <?= action_approve_btn($isUpg ? 'Approve upgrade and update package?' : 'Approve and activate this member?') ?>
                            <?= action_reject_btn($isUpg ? 'Reject this upgrade request?' : 'Reject this activation request?') ?>
                        </form>
                        <?php else: ?>
                        <small><?= e($r['admin_note'] ?? '') ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++):
            $pageQs = $baseQs;
            $pageQs['page'] = (string) $i;
            $pageHref = '?' . http_build_query($pageQs);
        ?>
            <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
            <?php else: ?><a href="<?= e($pageHref) ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
