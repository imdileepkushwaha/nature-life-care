<?php
$pageTitle = 'My Income';
require_once __DIR__ . '/../includes/income.php';
require_once __DIR__ . '/includes/header.php';

$uid = (int) $user['id'];
$types = income_types();
$showWithdrawUi = feature_module_allowed('withdrawals');

$paidTotal = income_sum($pdo, $uid, null, 'paid');
$pendingTotal = income_sum($pdo, $uid, null, 'pending');
$allCount = income_count($pdo, $uid);
$grand = $paidTotal + $pendingTotal;

$typeCards = [];
foreach ($types as $key => $meta) {
    $typeCards[] = [
        'meta' => $meta,
        'paid' => income_sum($pdo, $uid, $key, 'paid'),
        'pending' => income_sum($pdo, $uid, $key, 'pending'),
        'count' => income_count($pdo, $uid, $key),
    ];
}

// Recent across all types
$recent = [];
try {
    $rs = $pdo->prepare("
        SELECT c.*, fm.member_id AS from_mid, fm.full_name AS from_name
        FROM commissions c
        LEFT JOIN members fm ON fm.id = c.from_member_id
        WHERE c.member_id = ?
        ORDER BY c.id DESC
        LIMIT 10
    ");
    $rs->execute([$uid]);
    $recent = $rs->fetchAll();
} catch (Throwable $e) {
    $recent = [];
}

$needsActivation = empty($user['package_id']);
$canUpgrade = !$needsActivation && activation_can_upgrade($pdo, $user);
$upgradePending = false;
if (!$needsActivation) {
    $upPending = activation_pending_request($pdo, (int) $user['id']);
    $upgradePending = $upPending && (($upPending['request_type'] ?? '') === 'upgrade');
}
?>
<div class="up-page-head">
    <div>
        <h1>My Income</h1>
        <p>Overview of all commission types credited to your account.</p>
    </div>
    <?php if ($showWithdrawUi): ?>
    <a href="withdrawal-fund.php" class="up-btn up-btn-primary">Withdraw</a>
    <?php endif; ?>
</div>

<div class="inc-stats">
    <article class="inc-stat g-green">
        <span class="inc-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
        <div>
            <span class="inc-stat-label">Paid Total</span>
            <strong class="is-sm"><?= currency($paidTotal) ?></strong>
        </div>
    </article>
    <article class="inc-stat g-orange">
        <span class="inc-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
        <div>
            <span class="inc-stat-label">Pending</span>
            <strong class="is-sm"><?= currency($pendingTotal) ?></strong>
        </div>
    </article>
    <article class="inc-stat g-blue">
        <span class="inc-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg></span>
        <div>
            <span class="inc-stat-label">All Income</span>
            <strong class="is-sm"><?= currency($grand) ?></strong>
            <small><?= $allCount ?> entries</small>
        </div>
    </article>
    <article class="inc-stat g-purple">
        <span class="inc-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></span>
        <div>
            <span class="inc-stat-label">Wallet</span>
            <strong class="is-sm"><?= currency((float) $user['wallet_balance']) ?></strong>
        </div>
    </article>
</div>

<section class="inc-card">
    <div class="inc-banner is-summary">
        <div class="inc-banner-main">
            <span class="inc-banner-ico" aria-hidden="true"><?= income_type_icon('summary') ?></span>
            <div>
                <span class="inc-kicker">Income hub</span>
                <h2>Browse by type</h2>
                <p>Open a report for binary, referral, matching, level, or other credits.</p>
            </div>
        </div>
    </div>
    <div class="inc-type-grid">
        <?php foreach ($typeCards as $card):
            $m = $card['meta'];
            $totalType = $card['paid'] + $card['pending'];
            ?>
            <a href="<?= e($m['file']) ?>" class="inc-type-card tone-<?= e($m['tone']) ?>">
                <span class="inc-type-kicker"><?= e($m['kicker']) ?></span>
                <strong><?= e($m['label']) ?></strong>
                <span class="inc-type-amt"><?= currency($totalType) ?></span>
                <span class="inc-type-meta">
                    <span>Paid <?= currency($card['paid']) ?></span>
                    <span><?= (int) $card['count'] ?> rows</span>
                </span>
                <span class="inc-type-go">Open report →</span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="inc-card">
    <div class="inc-banner is-recent">
        <div class="inc-banner-main">
            <span class="inc-banner-ico" aria-hidden="true"><?= income_type_icon('recent') ?></span>
            <div>
                <span class="inc-kicker">Latest</span>
                <h2>Recent income</h2>
                <p>Last 10 credits across all income types.</p>
            </div>
        </div>
    </div>
    <div class="inc-table-wrap">
        <table class="inc-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>From</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$recent): ?>
                <tr>
                    <td colspan="6">
                        <div class="inc-empty">
                            <?php if ($needsActivation): ?>
                                <strong>No income records yet</strong>
                                <p>Activate your plan and grow your team to start earning.</p>
                                <a href="activate.php" class="up-btn up-btn-primary">Activate Account</a>
                            <?php elseif ($canUpgrade || $upgradePending): ?>
                                <strong>No income records yet</strong>
                                <p>Grow your team to earn — or upgrade your plan (pay only the difference) to unlock a higher package.</p>
                                <a href="activate.php" class="up-btn up-btn-primary"><?= $upgradePending ? 'View Upgrade' : 'Upgrade Plan' ?></a>
                            <?php else: ?>
                                <strong>No income records yet</strong>
                                <p>Share your referral links and grow your team to start earning.</p>
                                <a href="my-direct.php" class="up-btn up-btn-primary">View Directs</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($recent as $r):
                $tMeta = income_type_meta((string) $r['type']);
                ?>
                <tr>
                    <td><strong>#<?= (int) $r['id'] ?></strong></td>
                    <td>
                        <a class="inc-type-link" href="<?= e($tMeta['file'] ?? 'income-summary.php') ?>">
                            <?= e($tMeta['short'] ?? ucfirst((string) $r['type'])) ?>
                        </a>
                    </td>
                    <td><strong class="inc-amt"><?= currency((float) $r['amount']) ?></strong></td>
                    <td>
                        <?php if (!empty($r['from_mid'])): ?>
                            <div class="inc-from">
                                <strong><?= e($r['from_name'] ?? '—') ?></strong>
                                <small><?= e($r['from_mid']) ?></small>
                            </div>
                        <?php else: ?>
                            <span class="inc-muted">System / Admin</span>
                        <?php endif; ?>
                    </td>
                    <td><?= income_status_pill((string) $r['status']) ?></td>
                    <td><?= e(date('d M Y, h:i A', strtotime($r['created_at']))) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
