<?php
/**
 * Shared single-wallet detail page.
 * Expects $walletKey before include (income|topup|shopping).
 */
require_once __DIR__ . '/../../includes/wallet.php';

$types = wallet_types();
$enabled = wallet_types_enabled();
if (empty($walletKey) || !isset($types[$walletKey]) || !isset($enabled[$walletKey])) {
    header('Location: wallet.php');
    exit;
}
$meta = $types[$walletKey];
$pageTitle = $meta['label'];

require_once __DIR__ . '/header.php';

wallet_ensure_schema($pdo);
$uid = (int) $user['id'];
$balances = wallet_get_balances($pdo, $uid);
$balance = (float) ($balances[$walletKey] ?? 0);
$showWithdrawUi = feature_module_allowed('withdrawals');

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$totalRows = wallet_ledger_count($pdo, $uid, $walletKey);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$rows = wallet_ledger_rows($pdo, $uid, $walletKey, $perPage, $offset);
$incomeAvail = $walletKey === 'income' ? wallet_income_available($pdo, $user) : null;

$toneClass = [
    'green' => 'g-green',
    'blue' => 'g-blue',
    'purple' => 'g-purple',
][$meta['tone']] ?? 'g-blue';

$bannerClass = [
    'green' => 'is-green',
    'blue' => 'is-blue',
    'purple' => 'is-purple',
][$meta['tone']] ?? 'is-blue';

$quickIcons = [
    'income' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
    'topup' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
    'shopping' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M6 6L5 3H2"/></svg>',
];
$quickTone = ['income' => 'green', 'topup' => 'blue', 'shopping' => 'purple'];
?>
<div class="up-page-head">
    <div>
        <h1><?= e($meta['label']) ?></h1>
        <p><?= e($meta['desc']) ?></p>
    </div>
    <div class="up-head-actions">
        <a href="wallet.php" class="up-btn up-btn-outline">All Wallets</a>
        <a href="wallet-transfer.php" class="up-btn up-btn-primary">Transfer</a>
        <?php if ($walletKey === 'income' && $showWithdrawUi): ?>
            <a href="withdrawal-fund.php" class="up-btn up-btn-outline">Withdraw</a>
        <?php endif; ?>
    </div>
</div>

<div class="wal-stats">
    <article class="wal-stat <?= e($toneClass) ?>">
        <span class="wal-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
        </span>
        <div class="wal-stat-copy">
            <span class="wal-stat-label">Current Balance</span>
            <strong><?= currency($balance) ?></strong>
            <small><?= e($meta['label']) ?></small>
        </div>
    </article>
    <?php if ($walletKey === 'income'): ?>
    <article class="wal-stat g-green">
        <span class="wal-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        </span>
        <div class="wal-stat-copy">
            <span class="wal-stat-label">Available to withdraw</span>
            <strong><?= currency((float) $incomeAvail) ?></strong>
            <small>After pending requests</small>
        </div>
    </article>
    <?php endif; ?>
    <article class="wal-stat g-navy">
        <span class="wal-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/></svg>
        </span>
        <div class="wal-stat-copy">
            <span class="wal-stat-label">Ledger entries</span>
            <strong><?= (int) $totalRows ?></strong>
            <small>Credits &amp; debits</small>
        </div>
    </article>
</div>

<div class="wal-quick">
    <?php foreach ($types as $key => $t): ?>
        <a href="<?= e($t['page']) ?>" class="wal-quick-card tone-<?= e($quickTone[$key] ?? 'blue') ?><?= $key === $walletKey ? ' is-on' : '' ?>">
            <span class="wal-quick-ico" aria-hidden="true"><?= $quickIcons[$key] ?? $quickIcons['topup'] ?></span>
            <span class="wal-quick-meta">
                <span class="wal-quick-label"><?= e($t['label']) ?></span>
                <strong><?= currency($balances[$key] ?? 0) ?></strong>
            </span>
            <span class="wal-quick-go" aria-hidden="true">→</span>
        </a>
    <?php endforeach; ?>
</div>

<section class="wal-panel">
    <div class="wal-banner <?= e($bannerClass) ?>">
        <div class="wal-banner-main">
            <span class="wal-banner-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8M16 17H8M10 9H8"/></svg>
            </span>
            <div>
                <span class="wal-kicker"><?= e($meta['short']) ?></span>
                <h2><?= e($meta['short']) ?> ledger</h2>
                <!-- <p>All credits and debits for this wallet</p> -->
            </div>
        </div>
    </div>
    <div class="wal-table-wrap">
        <table class="wal-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Particular</th>
                    <th>Note</th>
                    <th>Credit</th>
                    <th>Debit</th>
                    <th>Balance</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="wal-empty">No transactions in this wallet yet.</td></tr>
            <?php else: foreach ($rows as $r):
                $isCredit = ($r['direction'] ?? '') === 'credit';
                ?>
                <tr>
                    <td><?= e(date('d M Y, h:i A', strtotime($r['created_at']))) ?></td>
                    <td><?= e(wallet_ref_label((string) $r['ref_type'])) ?></td>
                    <td><?= e($r['note'] ?: '—') ?></td>
                    <td class="is-in"><?= $isCredit ? currency((float) $r['amount']) : '—' ?></td>
                    <td class="is-out"><?= !$isCredit ? currency((float) $r['amount']) : '—' ?></td>
                    <td><?= currency((float) $r['balance_after']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="wal-pager">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a class="<?= $i === $page ? 'is-on' : '' ?>" href="?page=<?= $i ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
