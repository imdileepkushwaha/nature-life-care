<?php
$pageTitle = 'My Wallets';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/includes/header.php';

wallet_ensure_schema($pdo);
$uid = (int) $user['id'];
$balances = wallet_get_balances($pdo, $uid);
$types = wallet_types_enabled();
$totalAll = 0.0;
foreach (array_keys($types) as $wk) {
    $totalAll += (float) ($balances[$wk] ?? 0);
}
$recent = wallet_ledger_rows($pdo, $uid, null, 8);
$transfers = wallet_transfer_rows($pdo, $uid, 5);
$incomeAvail = wallet_income_available($pdo, $user);
$showWithdrawUi = feature_module_allowed('withdrawals');

$cardIco = [
    'income' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
    'topup' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
    'shopping' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M6 6L5 3H2"/></svg>',
];
$toneGrad = ['green' => 'g-green', 'blue' => 'g-blue', 'purple' => 'g-purple'];
?>
<div class="up-page-head">
    <div>
        <h1>My Wallets</h1>
        <p>Manage your wallet balances — transfer funds and track ledger.</p>
    </div>
    <div class="up-head-actions">
        <a href="wallet-transfer.php" class="up-btn up-btn-primary">Transfer</a>
        <?php if ($showWithdrawUi): ?>
        <a href="withdrawal-fund.php" class="up-btn up-btn-outline">Withdraw</a>
        <?php endif; ?>
    </div>
</div>

<div class="wal-stats">
    <article class="wal-stat g-navy">
        <span class="wal-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
        </span>
        <div class="wal-stat-copy">
            <span class="wal-stat-label">Total Balance</span>
            <strong><?= currency($totalAll) ?></strong>
            <small>Across all wallets</small>
        </div>
    </article>
    <article class="wal-stat g-green">
        <span class="wal-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        </span>
        <div class="wal-stat-copy">
            <span class="wal-stat-label">Withdrawable</span>
            <strong><?= currency($incomeAvail) ?></strong>
            <small>Income − pending withdrawals</small>
        </div>
    </article>
</div>

<div class="wal-grid">
    <?php foreach ($types as $key => $meta): ?>
        <a href="<?= e($meta['page']) ?>" class="wal-card tone-<?= e($meta['tone']) ?>">
            <div class="wal-card-top">
                <span class="wal-card-kicker"><?= e($meta['short']) ?></span>
                <span class="wal-card-ico" aria-hidden="true"><?= $cardIco[$key] ?? $cardIco['topup'] ?></span>
            </div>
            <strong class="wal-card-bal"><?= currency($balances[$key] ?? 0) ?></strong>
            <p><?= e($meta['desc']) ?></p>
            <span class="wal-card-cta">Open <?= e($meta['short']) ?> →</span>
        </a>
    <?php endforeach; ?>
</div>

<div class="wal-split">
    <section class="wal-panel">
        <div class="wal-banner is-navy">
            <div class="wal-banner-main">
                <span class="wal-banner-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8M16 17H8M10 9H8"/></svg>
                </span>
                <div>
                    <span class="wal-kicker">Activity</span>
                    <h2>Recent ledger</h2>
                    <!-- <p>Latest credits &amp; debits across wallets</p> -->
                </div>
            </div>
            <a href="wallet-income.php" class="up-btn up-btn-outline">View income</a>
        </div>
        <div class="wal-table-wrap">
            <table class="wal-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Wallet</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$recent): ?>
                    <tr><td colspan="5" class="wal-empty">No wallet transactions yet.</td></tr>
                <?php else: foreach ($recent as $r): ?>
                    <tr>
                        <td><?= e(date('d M Y, h:i A', strtotime($r['created_at']))) ?></td>
                        <td><?= e(wallet_label($r['wallet_type'])) ?></td>
                        <td><?= e(wallet_ref_label((string) $r['ref_type'])) ?></td>
                        <td class="<?= $r['direction'] === 'credit' ? 'is-in' : 'is-out' ?>">
                            <?= $r['direction'] === 'credit' ? '+' : '-' ?><?= currency((float) $r['amount']) ?>
                        </td>
                        <td><?= currency((float) $r['balance_after']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="wal-panel">
        <div class="wal-banner is-blue">
            <div class="wal-banner-main">
                <span class="wal-banner-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 014-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                </span>
                <div>
                    <span class="wal-kicker">Transfers</span>
                    <h2>Recent moves</h2>
                    <!-- <p>Between your wallets</p> -->
                </div>
            </div>
            <a href="wallet-transfer.php" class="up-btn">New transfer</a>
        </div>
        <div class="wal-table-wrap">
            <table class="wal-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>From → To</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$transfers): ?>
                    <tr><td colspan="3" class="wal-empty">No transfers yet.</td></tr>
                <?php else: foreach ($transfers as $t): ?>
                    <tr>
                        <td><?= e(date('d M Y, h:i A', strtotime($t['created_at']))) ?></td>
                        <td><?= e(wallet_label($t['from_wallet'])) ?> → <?= e(wallet_label($t['to_wallet'])) ?></td>
                        <td><?= currency((float) $t['amount']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
