<?php
$pageTitle = 'Transaction Report';
require_once __DIR__ . '/../includes/transactions.php';
require_once __DIR__ . '/includes/header.php';

$uid = (int) $user['id'];
$kind = $_GET['kind'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));

$data = txn_fetch($pdo, $uid, $kind, $statusFilter, $page, 15);
$rows = $data['rows'];
$total = $data['total'];
$totalPages = $data['total_pages'];
$page = $data['page'];
$creditSum = $data['credit_sum'];
$debitSum = $data['debit_sum'];
$net = $creditSum - $debitSum;
$showWithdrawUi = feature_module_allowed('withdrawals');

$queryBase = http_build_query(array_filter([
    'kind' => $kind !== '' ? $kind : null,
    'status' => $statusFilter !== '' ? $statusFilter : null,
]));
?>
<div class="up-page-head">
    <div>
        <h1>Transaction Report</h1>
        <p>Complete ledger of income credits and withdrawal debits on your account.</p>
    </div>
    <?php if ($showWithdrawUi): ?>
    <a href="withdrawal-fund.php" class="up-btn up-btn-primary">Withdraw</a>
    <?php endif; ?>
</div>

<div class="txn-stats">
    <article class="txn-stat g-green">
        <span class="txn-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12l7 7 7-7"/></svg></span>
        <div>
            <span class="txn-stat-label">Total Credit</span>
            <strong class="is-sm"><?= currency($creditSum) ?></strong>
            <small>Income in</small>
        </div>
    </article>
    <article class="txn-stat g-orange">
        <span class="txn-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 19V5M5 12l7-7 7 7"/></svg></span>
        <div>
            <span class="txn-stat-label">Total Debit</span>
            <strong class="is-sm"><?= currency($debitSum) ?></strong>
            <small>Withdrawals</small>
        </div>
    </article>
    <article class="txn-stat g-blue">
        <span class="txn-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg></span>
        <div>
            <span class="txn-stat-label">Net</span>
            <strong class="is-sm"><?= currency($net) ?></strong>
            <small><?= (int) $total ?> records</small>
        </div>
    </article>
    <article class="txn-stat g-purple">
        <span class="txn-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></span>
        <div>
            <span class="txn-stat-label">Wallet Now</span>
            <strong class="is-sm"><?= currency((float) $user['wallet_balance']) ?></strong>
        </div>
    </article>
</div>

<section class="txn-card">
    <div class="txn-banner">
        <div>
            <span class="txn-kicker">Account ledger</span>
            <h2>All Transactions</h2>
            <!-- <p>Filter by type and status to review every credit and debit.</p> -->
        </div>
        <form method="get" class="txn-filters">
            <select name="kind" onchange="this.form.submit()" aria-label="Filter type">
                <option value="">All types</option>
                <option value="income" <?= $kind === 'income' ? 'selected' : '' ?>>Income only</option>
                <option value="withdrawal" <?= $kind === 'withdrawal' ? 'selected' : '' ?>>Withdrawals only</option>
            </select>
            <select name="status" onchange="this.form.submit()" aria-label="Filter status">
                <option value="">All statuses</option>
                <?php foreach (['pending', 'paid', 'approved', 'rejected', 'cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="txn-table-wrap">
        <table class="txn-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Direction</th>
                    <th>Amount</th>
                    <th>Details</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="7">
                        <div class="txn-empty">
                            <strong>No transactions yet</strong>
                            <p>Income credits and withdrawal requests will appear here.</p>
                            <div class="txn-empty-actions">
                                <a href="income-summary.php" class="up-btn up-btn-outline">My Income</a>
                                <?php if ($showWithdrawUi): ?>
                                <a href="withdrawal-fund.php" class="up-btn up-btn-primary">Withdraw</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($rows as $r):
                $isOut = ($r['direction'] ?? '') === 'out';
                $label = txn_type_label((string) $r['source'], (string) $r['type']);
            ?>
                <tr>
                    <td><strong>#<?= (int) $r['id'] ?></strong></td>
                    <td>
                        <span class="txn-type <?= $isOut ? 'is-out' : 'is-in' ?>"><?= e($label) ?></span>
                    </td>
                    <td>
                        <span class="txn-dir <?= $isOut ? 'is-out' : 'is-in' ?>">
                            <?= $isOut ? 'Debit' : 'Credit' ?>
                        </span>
                    </td>
                    <td>
                        <strong class="txn-amt <?= $isOut ? 'is-out' : 'is-in' ?>">
                            <?= $isOut ? '−' : '+' ?><?= currency((float) $r['amount']) ?>
                        </strong>
                    </td>
                    <td><div class="txn-desc"><?= e($r['description'] ?: '—') ?></div></td>
                    <td><?= txn_status_pill((string) $r['status']) ?></td>
                    <td><?= e(date('d M Y, h:i A', strtotime((string) $r['txn_at']))) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="txn-pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++):
            $href = '?' . ($queryBase !== '' ? $queryBase . '&' : '') . 'page=' . $i;
        ?>
            <a class="<?= $i === $page ? 'is-on' : '' ?>" href="<?= e($href) ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
