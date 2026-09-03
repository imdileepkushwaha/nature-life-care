<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/wallet_topup.php';

$pageTitle = 'Topup Requests';
wallet_topup_ensure_requests_table($pdo);
$adminId = (int) ($_SESSION['admin_id'] ?? 0);

function wtr_filter_qs(array $extra = []): array
{
    $qs = [];
    foreach (['status', 'q', 'date_from', 'date_to', 'hpage'] as $k) {
        $v = trim((string) ($_GET[$k] ?? ''));
        if ($v !== '') {
            $qs[$k] = $v;
        }
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
        $result = wallet_topup_approve_request($pdo, $id, $adminId, $note !== '' ? $note : null);
        if ($result['ok']) {
            log_activity('wallet_topup_approve', "Approved topup request #$id");
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
    } elseif ($action === 'reject') {
        $result = wallet_topup_reject_request($pdo, $id, $adminId, $note !== '' ? $note : 'Rejected by admin');
        if ($result['ok']) {
            log_activity('wallet_topup_reject', "Rejected topup request #$id");
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
    }

    $qs = wtr_filter_qs();
    if (!empty($_GET['page'])) {
        $qs['page'] = (string) (int) $_GET['page'];
    }
    $redir = 'wallet-topup-requests.php';
    if ($qs) {
        $redir .= '?' . http_build_query($qs);
    }
    header('Location: ' . $redir);
    exit;
}

$statusFilter = (string) ($_GET['status'] ?? 'pending');
if (!in_array($statusFilter, ['', 'pending', 'approved', 'rejected', 'history'], true)) {
    $statusFilter = 'pending';
}
$q = trim((string) ($_GET['q'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$hpage = max(1, (int) ($_GET['hpage'] ?? 1));
$perPage = 20;

function wtr_build_where(string $statusFilter, string $q, string $dateFrom, string $dateTo, bool $historyOnly = false): array
{
    $where = ['1=1'];
    $params = [];

    if ($historyOnly) {
        $where[] = "r.status IN ('approved','rejected')";
    } elseif ($statusFilter === 'history') {
        $where[] = "r.status IN ('approved','rejected')";
    } elseif ($statusFilter !== '') {
        $where[] = 'r.status = ?';
        $params[] = $statusFilter;
    }

    if ($q !== '') {
        $where[] = '(m.member_id LIKE ? OR m.username LIKE ? OR m.full_name LIKE ? OR m.phone LIKE ? OR r.utr_reference LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where[] = 'DATE(r.created_at) >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $where[] = 'DATE(r.created_at) <= ?';
        $params[] = $dateTo;
    }

    return [implode(' AND ', $where), $params];
}

// Pending / filtered list
[$whereSql, $params] = wtr_build_where($statusFilter, $q, $dateFrom, $dateTo, false);
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM wallet_topup_requests r
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
    SELECT r.*, m.member_id AS mid, m.full_name, m.username, m.phone, m.email,
           m.topup_wallet_balance, a.full_name AS admin_name
    FROM wallet_topup_requests r
    JOIN members m ON m.id = r.member_id
    LEFT JOIN admins a ON a.id = r.processed_by
    WHERE $whereSql
    ORDER BY FIELD(r.status, 'pending', 'approved', 'rejected'), r.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Always load history (approved + rejected), unless already filtering only history in main list
$showHistoryPanel = ($statusFilter === 'pending');
$historyRows = [];
$historyTotal = 0;
$historyPages = 1;

if ($showHistoryPanel) {
    [$hWhereSql, $hParams] = wtr_build_where('history', $q, $dateFrom, $dateTo, true);
    $hOffset = ($hpage - 1) * $perPage;

    $hCount = $pdo->prepare("
        SELECT COUNT(*) FROM wallet_topup_requests r
        JOIN members m ON m.id = r.member_id
        WHERE $hWhereSql
    ");
    $hCount->execute($hParams);
    $historyTotal = (int) $hCount->fetchColumn();
    $historyPages = max(1, (int) ceil($historyTotal / $perPage));
    if ($hpage > $historyPages) {
        $hpage = $historyPages;
        $hOffset = ($hpage - 1) * $perPage;
    }

    $hStmt = $pdo->prepare("
        SELECT r.*, m.member_id AS mid, m.full_name, m.username, m.phone,
               m.topup_wallet_balance, a.full_name AS admin_name
        FROM wallet_topup_requests r
        JOIN members m ON m.id = r.member_id
        LEFT JOIN admins a ON a.id = r.processed_by
        WHERE $hWhereSql
        ORDER BY COALESCE(r.processed_at, r.created_at) DESC, r.id DESC
        LIMIT $perPage OFFSET $hOffset
    ");
    $hStmt->execute($hParams);
    $historyRows = $hStmt->fetchAll();
}

$pendingCount = wallet_topup_pending_count($pdo);
$approvedCount = (int) $pdo->query("SELECT COUNT(*) FROM wallet_topup_requests WHERE status = 'approved'")->fetchColumn();
$rejectedCount = (int) $pdo->query("SELECT COUNT(*) FROM wallet_topup_requests WHERE status = 'rejected'")->fetchColumn();
$pendingSum = 0.0;
try {
    $pendingSum = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM wallet_topup_requests WHERE status = 'pending'")->fetchColumn();
} catch (Throwable $e) {
    $pendingSum = 0.0;
}

/**
 * @param array $r
 */
function wtr_render_row(array $r, bool $isHistory = false): void
{
    $st = (string) $r['status'];
    $proofUrl = wallet_topup_proof_url($r['payment_proof'] ?? null);
    ?>
    <tr>
        <td>#<?= (int) $r['id'] ?></td>
        <td>
            <a href="member-view.php?id=<?= (int) $r['member_id'] ?>"><?= e($r['full_name']) ?></a><br>
            <small><?= e($r['mid']) ?> · <?= e($r['username']) ?><?= !empty($r['phone']) ? ' · ' . e($r['phone']) : '' ?></small>
        </td>
        <td><strong><?= currency((float) $r['amount']) ?></strong></td>
        <td><?= e($r['payment_mode'] ?? '—') ?></td>
        <td>
            <strong><?= e($r['utr_reference'] ?? '—') ?></strong>
            <?php if (!empty($r['note'])): ?>
                <br><small><?= e($r['note']) ?></small>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($proofUrl): ?>
                <a href="<?= e($proofUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn-icon" title="View proof" aria-label="View proof"><?= icon_svg('view') ?></a>
            <?php else: ?>
                —
            <?php endif; ?>
        </td>
        <td><?= currency((float) ($r['topup_wallet_balance'] ?? 0)) ?></td>
        <td><?= status_badge($st) ?></td>
        <td>
            <?= !empty($r['created_at']) ? date('d M Y H:i', strtotime((string) $r['created_at'])) : '—' ?>
            <?php if ($isHistory && !empty($r['processed_at'])): ?>
                <br><small>Processed: <?= date('d M Y H:i', strtotime((string) $r['processed_at'])) ?></small>
            <?php endif; ?>
        </td>
        <td>
            <?php if (!$isHistory && $st === 'pending'): ?>
            <form method="post" class="action-icons" style="flex-wrap:nowrap">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <input type="text" name="admin_note" placeholder="Note" class="act-note-input" style="width:90px;padding:0.3rem;font-size:0.8rem;border:1px solid var(--border);border-radius:6px">
                <?= action_approve_btn('Credit Topup Wallet for this request?') ?>
                <?= action_reject_btn('Reject this topup request?') ?>
            </form>
            <?php else: ?>
                <small><?= e($r['admin_note'] ?: '—') ?></small>
                <?php if (!empty($r['admin_name'])): ?>
                    <br><small>By: <?= e($r['admin_name']) ?></small>
                <?php endif; ?>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card accent"><div class="label">Pending Requests</div><div class="value"><?= $pendingCount ?></div></div>
    <div class="stat-card"><div class="label">Pending Amount</div><div class="value"><?= currency($pendingSum) ?></div></div>
    <div class="stat-card"><div class="label">Approved</div><div class="value"><?= $approvedCount ?></div></div>
    <div class="stat-card"><div class="label">Rejected</div><div class="value"><?= $rejectedCount ?></div></div>
</div>

<div class="panel">
    <div class="panel-header">
        <h2>
            <?php if ($statusFilter === 'history'): ?>
                Topup History (<?= $total ?>)
            <?php elseif ($statusFilter === 'approved'): ?>
                Approved Requests (<?= $total ?>)
            <?php elseif ($statusFilter === 'rejected'): ?>
                Rejected Requests (<?= $total ?>)
            <?php elseif ($statusFilter === ''): ?>
                All Topup Requests (<?= $total ?>)
            <?php else: ?>
                Pending Topup Requests (<?= $total ?>)
            <?php endif; ?>
        </h2>
    </div>
    <div class="panel-body">
        <form class="filters" method="get">
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="history" <?= $statusFilter === 'history' ? 'selected' : '' ?>>History (Approved + Rejected)</option>
                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All</option>
                </select>
            </div>
            <div class="form-group">
                <label>Search</label>
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="Member / UTR / phone">
            </div>
            <div class="form-group">
                <label>From date</label>
                <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
            </div>
            <div class="form-group">
                <label>To date</label>
                <input type="date" name="date_to" value="<?= e($dateTo) ?>">
            </div>
            <div class="form-group" style="align-self:end">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="wallet-topup-requests.php" class="btn btn-outline">Reset</a>
            </div>
        </form>

        <?php
        $hasAnyFilter = $q !== '' || $dateFrom !== '' || $dateTo !== '' || !in_array($statusFilter, ['pending', ''], true);
        ?>
        <?php if (!$rows): ?>
        <div class="act-empty">
            <div class="act-empty-visual" aria-hidden="true">
                <span class="act-empty-ring"></span>
                <span class="act-empty-ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M12 14v-3M12 8h.01"/></svg>
                </span>
            </div>
            <strong><?= $hasAnyFilter || $statusFilter !== 'pending' ? 'No matching requests' : 'No pending requests' ?></strong>
            <p><?= $hasAnyFilter || $statusFilter !== 'pending'
                ? 'Nothing matches your filters. Try adjusting or clearing them.'
                : 'When members submit Add Money requests, pending ones appear here for approval.' ?></p>
            <?php if ($hasAnyFilter || $statusFilter !== 'pending'): ?>
                <a href="wallet-topup-requests.php" class="btn btn-primary btn-sm">View pending</a>
            <?php else: ?>
                <a href="wallet-topup-requests.php?status=history" class="btn btn-outline btn-sm">View history</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Member</th>
                        <th>Amount</th>
                        <th>Mode</th>
                        <th>UTR / Ref</th>
                        <th>Proof</th>
                        <th>Topup bal</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th><?= in_array($statusFilter, ['pending', ''], true) ? 'Actions' : 'Note / By' ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    wtr_render_row($r, $statusFilter !== 'pending' && $statusFilter !== '');
                endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++):
                $qs = wtr_filter_qs(['page' => (string) $i]);
                ?>
                <a class="<?= $i === $page ? 'active' : '' ?>" href="?<?= e(http_build_query($qs)) ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($showHistoryPanel): ?>
<div class="panel" style="margin-top:1.25rem">
    <div class="panel-header">
        <h2>Request History (<?= $historyTotal ?>)</h2>
        <a href="wallet-topup-requests.php?status=history" class="btn btn-outline btn-sm">View full history</a>
    </div>
    <div class="panel-body">
        <?php if (!$historyRows): ?>
            <div class="act-empty" style="padding:1.5rem">
                <strong>No history yet</strong>
                <p>Approved and rejected topup requests will appear here.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Member</th>
                        <th>Amount</th>
                        <th>Mode</th>
                        <th>UTR / Ref</th>
                        <th>Proof</th>
                        <th>Topup bal</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Note / By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($historyRows as $r):
                    wtr_render_row($r, true);
                endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($historyPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $historyPages; $i++):
                $qs = wtr_filter_qs(['hpage' => (string) $i, 'page' => null]);
                // keep page for pending list if set
                if ($page > 1) {
                    $qs['page'] = (string) $page;
                }
                ?>
                <a class="<?= $i === $hpage ? 'active' : '' ?>" href="?<?= e(http_build_query($qs)) ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
