<?php
$pageTitle = 'Withdrawal Report';
require_once __DIR__ . '/../includes/withdrawal.php';
require_once __DIR__ . '/includes/header.php';

wd_ensure_columns($pdo);
$uid = (int) $user['id'];
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = ['w.member_id = ?'];
$params = [$uid];
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
    SELECT w.*
    FROM withdrawals w
    WHERE $whereSql
    ORDER BY w.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pendingCount = wd_pending_count($pdo, $uid);
$pendingSum = wd_pending_sum($pdo, $uid);

$ps = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE member_id = ? AND status IN ('approved','paid')");
$ps->execute([$uid]);
$paidSum = (float) $ps->fetchColumn();

$ac = $pdo->prepare('SELECT COUNT(*) FROM withdrawals WHERE member_id = ?');
$ac->execute([$uid]);
$allCount = (int) $ac->fetchColumn();
?>
<div class="up-page-head">
    <div>
        <h1>Withdrawal Report</h1>
        <p>History of your payout requests and their status.</p>
    </div>
    <a href="withdrawal-fund.php" class="up-btn up-btn-primary">New Withdrawal</a>
</div>

<div class="wd-stats">
    <article class="wd-stat g-orange">
        <span class="wd-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
        <div>
            <span class="wd-stat-label">Pending</span>
            <strong><?= $pendingCount ?></strong>
            <small><?= currency($pendingSum) ?></small>
        </div>
    </article>
    <article class="wd-stat g-green">
        <span class="wd-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
        <div>
            <span class="wd-stat-label">Approved / Paid</span>
            <strong class="is-sm"><?= currency($paidSum) ?></strong>
        </div>
    </article>
    <article class="wd-stat g-blue">
        <span class="wd-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
        <div>
            <span class="wd-stat-label">Total Requests</span>
            <strong><?= $allCount ?></strong>
        </div>
    </article>
    <article class="wd-stat g-purple">
        <span class="wd-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></span>
        <div>
            <span class="wd-stat-label">Wallet Now</span>
            <strong class="is-sm"><?= currency((float) $user['wallet_balance']) ?></strong>
        </div>
    </article>
</div>

<section class="wd-card">
    <div class="wd-banner is-report">
        <div>
            <span class="wd-kicker">History</span>
            <h2>Your Withdrawals</h2>
            <p>Filter by status to review pending or completed payouts.</p>
        </div>
        <form method="get" class="wd-filters">
            <select name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach (['pending', 'approved', 'rejected', 'paid'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="wd-table-wrap">
        <table class="wd-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Gross</th>
                    <th>Deductions</th>
                    <th>Net</th>
                    <th>Method</th>
                    <th>Account</th>
                    <th>Status</th>
                    <th>Requested</th>
                    <th>Processed</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="10">
                        <div class="wd-empty">
                            <strong>No withdrawals found</strong>
                            <p>Submit a request from Withdrawal Fund to get started.</p>
                            <a href="withdrawal-fund.php" class="up-btn up-btn-primary">Withdrawal Fund</a>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><strong>#<?= (int) $r['id'] ?></strong></td>
                    <td><strong class="wd-amt"><?= currency((float) $r['amount']) ?></strong></td>
                    <td>
                        <small>
                            TDS <?= currency((float) ($r['tds_amount'] ?? 0)) ?><br>
                            Fee <?= currency((float) ($r['fee_amount'] ?? 0) + (float) ($r['other_deduction'] ?? 0)) ?>
                        </small>
                    </td>
                    <td><strong><?= currency(wd_net_display($r)) ?></strong></td>
                    <td><?= e($r['payment_method'] ?? '—') ?></td>
                    <td><div class="wd-account"><?= nl2br(e($r['account_details'] ?? '—')) ?></div></td>
                    <td><?= wd_status_pill((string) $r['status']) ?></td>
                    <td><?= e(date('d M Y, h:i A', strtotime($r['requested_at']))) ?></td>
                    <td><?= !empty($r['processed_at']) ? e(date('d M Y', strtotime($r['processed_at']))) : '—' ?></td>
                    <td><small class="wd-note"><?= e($r['admin_note'] ?? '—') ?></small></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="wd-pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a class="<?= $i === $page ? 'is-on' : '' ?>" href="?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
