<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/procedures.php';
require_once __DIR__ . '/../includes/withdrawal.php';
require_once __DIR__ . '/../includes/wallet.php';
$pageTitle = 'Withdrawals';

wd_ensure_columns($pdo);
wd_ensure_payout_log_table($pdo);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $status = $_GET['status'] ?? 'approved';
    if (!in_array($status, ['pending', 'approved', 'rejected', 'paid', 'payout'], true)) {
        $status = 'approved';
    }
    $where = '1=1';
    $params = [];
    if ($status === 'payout') {
        $where = "w.status IN ('approved','paid')";
    } else {
        $where = 'w.status = ?';
        $params[] = $status;
    }

    $stmt = $pdo->prepare("
        SELECT w.*, m.full_name, m.member_id AS mid, m.email, m.phone
        FROM withdrawals w
        JOIN members m ON m.id = w.member_id
        WHERE $where
        ORDER BY w.id ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $filename = 'withdrawals-' . $status . '-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, [
        'ID', 'Member ID', 'Name', 'Email', 'Phone', 'Gross', 'TDS', 'Fee', 'Other Deduction',
        'Net Payout', 'Method', 'Account Details', 'Status', 'Requested', 'Processed', 'Admin Note',
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            (int) $r['id'],
            $r['mid'],
            $r['full_name'],
            $r['email'] ?? '',
            $r['phone'] ?? '',
            number_format((float) $r['amount'], 2, '.', ''),
            number_format((float) ($r['tds_amount'] ?? 0), 2, '.', ''),
            number_format((float) ($r['fee_amount'] ?? 0), 2, '.', ''),
            number_format((float) ($r['other_deduction'] ?? 0), 2, '.', ''),
            number_format(wd_net_display($r), 2, '.', ''),
            $r['payment_method'] ?? '',
            preg_replace("/\r\n|\r|\n/", ' | ', (string) ($r['account_details'] ?? '')),
            $r['status'],
            $r['requested_at'] ?? '',
            $r['processed_at'] ?? '',
            $r['admin_note'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note = trim($_POST['admin_note'] ?? '');
    $payoutRef = trim((string) ($_POST['payout_ref'] ?? ''));

    $stmt = $pdo->prepare('SELECT * FROM withdrawals WHERE id = ?');
    $stmt->execute([$id]);
    $wd = $stmt->fetch();

    if ($wd && $wd['status'] === 'pending') {
        if ($action === 'approve') {
            $sp = sp_call_approve_withdrawal($pdo, $id, $note);
            if ($sp['message'] !== 'Procedure unavailable' && $sp['ok']) {
                wd_payout_log_append($pdo, wd_payout_log_from_row($wd, 'approve', $note));
                log_activity('withdrawal_approve', "Approved withdrawal #$id");
                flash('success', $sp['message']);
            } elseif ($sp['message'] !== 'Procedure unavailable' && !$sp['ok']) {
                flash('error', $sp['message']);
            } else {
                try {
                    $pdo->beginTransaction();
                    $upd = $pdo->prepare("UPDATE withdrawals SET status = 'approved', admin_note = ?, processed_at = NOW() WHERE id = ? AND status = 'pending'");
                    $upd->execute([$note, $id]);
                    if ($upd->rowCount() < 1) {
                        $pdo->rollBack();
                        flash('error', 'Withdrawal is not pending.');
                    } else {
                        $ded = wallet_debit(
                            $pdo,
                            (int) $wd['member_id'],
                            'income',
                            (float) $wd['amount'],
                            'withdrawal',
                            $id,
                            'Withdrawal #' . $id . ' approved'
                        );
                        if (!$ded['ok']) {
                            $pdo->rollBack();
                            flash('error', $ded['error'] ?: 'Insufficient income wallet balance.');
                        } else {
                            $pdo->commit();
                            $wd['admin_note'] = $note;
                            wd_payout_log_append($pdo, wd_payout_log_from_row($wd, 'approve', $note));
                            log_activity('withdrawal_approve', "Approved withdrawal #$id");
                            flash('success', 'Withdrawal approved. Income wallet deducted. Pay net ' . strip_tags(currency(wd_net_display($wd))) . '.');
                        }
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    flash('error', 'Approval failed.');
                }
            }
        } elseif ($action === 'reject') {
            $sp = sp_call_reject_withdrawal($pdo, $id, $note);
            if ($sp['message'] !== 'Procedure unavailable' && $sp['ok']) {
                wd_payout_log_append($pdo, wd_payout_log_from_row($wd, 'reject', $note));
                log_activity('withdrawal_reject', "Rejected withdrawal #$id");
                flash('success', $sp['message']);
            } elseif ($sp['message'] !== 'Procedure unavailable' && !$sp['ok']) {
                flash('error', $sp['message']);
            } else {
                $pdo->prepare("UPDATE withdrawals SET status = 'rejected', admin_note = ?, processed_at = NOW() WHERE id = ? AND status = 'pending'")
                    ->execute([$note, $id]);
                wd_payout_log_append($pdo, wd_payout_log_from_row($wd, 'reject', $note));
                log_activity('withdrawal_reject', "Rejected withdrawal #$id");
                flash('success', 'Withdrawal rejected.');
            }
        } elseif ($action === 'paid') {
            flash('error', 'Approve the withdrawal first, then mark it paid.');
        }
    }

    // Only approved → paid (never pending → paid)
    if ($wd && $wd['status'] === 'approved' && $action === 'paid') {
        $finalNote = $note !== '' ? $note : ($wd['admin_note'] ?? null);
        $pdo->prepare("UPDATE withdrawals SET status = 'paid', admin_note = ?, processed_at = NOW() WHERE id = ? AND status = 'approved'")
            ->execute([$finalNote, $id]);
        $wd['admin_note'] = $finalNote;
        wd_payout_log_append($pdo, wd_payout_log_from_row($wd, 'paid', (string) $finalNote, $payoutRef));
        log_activity('withdrawal_paid', "Marked withdrawal #$id paid" . ($payoutRef !== '' ? " ref=$payoutRef" : ''));
        flash('success', 'Marked as paid. Net remitted: ' . strip_tags(currency(wd_net_display($wd))) . '.');
    }

    $redir = 'withdrawals.php';
    if (!empty($_GET['status'])) {
        $redir .= '?status=' . urlencode((string) $_GET['status']);
    }
    header('Location: ' . $redir);
    exit;
}

$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
if (in_array($statusFilter, ['pending', 'approved', 'rejected', 'paid'], true)) {
    $where[] = 'w.status = ?';
    $params[] = $statusFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM withdrawals w WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $pdo->prepare("
    SELECT w.*, m.full_name, m.member_id AS mid, m.wallet_balance
    FROM withdrawals w
    JOIN members m ON m.id = w.member_id
    WHERE $whereSql
    ORDER BY w.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
$pendingSum = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
$approvedCount = (int) $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'approved'")->fetchColumn();
$approvedNet = (float) $pdo->query("SELECT COALESCE(SUM(COALESCE(net_amount, amount)),0) FROM withdrawals WHERE status = 'approved'")->fetchColumn();
$tdsTotal = (float) $pdo->query("SELECT COALESCE(SUM(tds_amount),0) FROM withdrawals WHERE status IN ('approved','paid')")->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card accent"><div class="label">Pending Requests</div><div class="value"><?= $pendingCount ?></div></div>
    <div class="stat-card"><div class="label">Pending Gross</div><div class="value"><?= currency($pendingSum) ?></div></div>
    <div class="stat-card"><div class="label">Approved (pay net)</div><div class="value"><?= currency($approvedNet) ?> <small style="font-size:0.7rem;font-weight:600;color:#8392ab"><?= $approvedCount ?> req</small></div></div>
    <div class="stat-card"><div class="label">TDS Collected</div><div class="value"><?= currency($tdsTotal) ?></div></div>
</div>

<div class="panel">
    <div class="panel-header" style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;justify-content:space-between">
        <h2>Withdrawals (<?= $total ?>)</h2>
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem">
            <a class="btn btn-outline btn-sm" href="tds-report.php">TDS Report</a>
            <a class="btn btn-outline btn-sm" href="?export=csv&status=payout">Export payout CSV</a>
            <a class="btn btn-primary btn-sm" href="?export=csv&status=<?= urlencode($statusFilter !== '' ? $statusFilter : 'approved') ?>">Export filtered CSV</a>
        </div>
    </div>
    <div class="panel-body">
        <form class="filters" method="get">
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="">All</option>
                    <?php foreach (['pending','approved','rejected','paid'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
        </form>
        <p class="muted" style="margin:0.75rem 0 0;font-size:0.85rem">Wallet deducts <strong>gross</strong> on approve. Remit <strong>net</strong> to member. Pending cannot be marked paid.</p>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Member</th>
                    <th>Gross</th>
                    <th>TDS / Fee</th>
                    <th>Net payout</th>
                    <th>Method</th>
                    <th>Account</th>
                    <th>Status</th>
                    <th>Requested</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="10">No withdrawal requests.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td>#<?= (int)$r['id'] ?></td>
                    <td>
                        <?= e($r['full_name']) ?><br>
                        <small><?= e($r['mid']) ?> · Wallet: <?= currency((float)$r['wallet_balance']) ?></small>
                    </td>
                    <td><strong><?= currency((float)$r['amount']) ?></strong></td>
                    <td>
                        <small>
                            TDS <?= currency((float)($r['tds_amount'] ?? 0)) ?><br>
                            Fee <?= currency((float)($r['fee_amount'] ?? 0) + (float)($r['other_deduction'] ?? 0)) ?>
                        </small>
                    </td>
                    <td><strong class="rpt-amt"><?= currency(wd_net_display($r)) ?></strong></td>
                    <td><?= e($r['payment_method'] ?? '—') ?></td>
                    <td style="max-width:160px;font-size:0.8rem"><?= e($r['account_details'] ?? '—') ?></td>
                    <td><?= status_badge((string) $r['status']) ?></td>
                    <td><?= date('d M Y H:i', strtotime($r['requested_at'])) ?></td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                        <form method="post" class="action-icons" style="flex-wrap:nowrap">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="text" name="admin_note" placeholder="Note" style="width:90px;padding:0.3rem;font-size:0.8rem;border:1px solid var(--border);border-radius:6px">
                            <?= action_approve_btn('Approve and deduct gross from wallet?') ?>
                            <?= action_reject_btn('Reject this request?') ?>
                        </form>
                        <?php elseif ($r['status'] === 'approved'): ?>
                        <form method="post" class="action-icons" style="flex-wrap:wrap;max-width:220px">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="text" name="payout_ref" placeholder="UTR / ref" style="width:100%;padding:0.3rem;font-size:0.8rem;border:1px solid var(--border);border-radius:6px;margin-bottom:0.25rem" maxlength="120">
                            <?= action_paid_btn('Confirm bank remittance of net amount?') ?>
                        </form>
                        <?php else: ?>
                        <small><?= e($r['admin_note'] ?? '') ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
            <?php else: ?><a href="?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$payoutLogs = wd_payout_logs_recent($pdo, 40);
?>
<div class="panel" style="margin-top:1.25rem">
    <div class="panel-header">
        <h2>Immutable payout log</h2>
        <span class="muted" style="font-size:0.85rem">Append-only · approve / reject / paid with admin + UTR</span>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Event</th>
                    <th>WD #</th>
                    <th>Member</th>
                    <th>Gross / Net</th>
                    <th>UTR / Ref</th>
                    <th>Admin</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$payoutLogs): ?>
                <tr><td colspan="8" class="empty-state">No payout events yet.</td></tr>
            <?php else: foreach ($payoutLogs as $pl): ?>
                <tr>
                    <td><span class="muted"><?= !empty($pl['created_at']) ? e(date('d M Y H:i', strtotime((string) $pl['created_at']))) : '—' ?></span></td>
                    <td><span class="pkg-chip"><?= e((string) $pl['event_type']) ?></span></td>
                    <td>#<?= (int) $pl['withdrawal_id'] ?></td>
                    <td>
                        <?= e((string) ($pl['full_name'] ?? '—')) ?><br>
                        <small><?= e((string) ($pl['mid'] ?? '')) ?></small>
                    </td>
                    <td>
                        <strong><?= currency((float) $pl['gross_amount']) ?></strong><br>
                        <small>Net <?= currency((float) $pl['net_amount']) ?></small>
                    </td>
                    <td><?= e((string) ($pl['payout_ref'] ?? '—')) ?></td>
                    <td><?= e((string) ($pl['admin_username'] ?? '—')) ?></td>
                    <td style="max-width:160px;font-size:0.8rem"><?= e((string) ($pl['admin_note'] ?? '')) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
