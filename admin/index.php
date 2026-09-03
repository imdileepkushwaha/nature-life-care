<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/closing.php';
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';

$totalMembers = (int) $pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
$activeMembers = (int) $pdo->query("SELECT COUNT(*) FROM members WHERE status = 'active'")->fetchColumn();
$totalPackages = (int) $pdo->query("SELECT COUNT(*) FROM packages WHERE status = 'active'")->fetchColumn();
$pendingWithdrawals = (int) $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
$totalCommissions = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status != 'cancelled'")->fetchColumn();
$totalPaidOut = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status IN ('approved','paid')")->fetchColumn();
$todayJoins = (int) $pdo->query("SELECT COUNT(*) FROM members WHERE DATE(join_date) = CURDATE()")->fetchColumn();
$walletTotal = (float) $pdo->query("SELECT COALESCE(SUM(wallet_balance),0) FROM members")->fetchColumn();
$newsCount = (int) $pdo->query("SELECT COUNT(*) FROM news WHERE status = 'active'")->fetchColumn();
$pendingComm = (int) $pdo->query("SELECT COUNT(*) FROM commissions WHERE status = 'pending'")->fetchColumn();

$closingSummary = ['eligible_members' => 0, 'pairs' => 0, 'matched_bv' => 0, 'est_binary_gross' => 0];
$lastClosing = null;
$showBinaryDash = plan_uses_binary();
$showPackagesDash = feature_module_allowed('packages');
$showWithdrawalsDash = feature_module_allowed('withdrawals');
$showKycDash = feature_module_allowed('kyc');
$showProductsDash = feature_module_allowed('products');
$showActivationsDash = feature_module_allowed('activations') || feature_enabled('feature_utr_activation_enabled');

$alertPendingKyc = 0;
$alertPendingUtr = 0;
$alertPendingWd = $showWithdrawalsDash ? $pendingWithdrawals : 0;
$alertLowStock = 0;
$stockThreshold = 5;

if ($showKycDash) {
    try {
        $alertPendingKyc = (int) $pdo->query("SELECT COUNT(*) FROM member_kyc_documents WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        $alertPendingKyc = 0;
    }
}
if ($showActivationsDash) {
    try {
        $alertPendingUtr = (int) $pdo->query("SELECT COUNT(*) FROM activation_requests WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        $alertPendingUtr = 0;
    }
}
if ($showProductsDash) {
    try {
        $alertLowStock = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active' AND stock_qty <= {$stockThreshold}")->fetchColumn();
    } catch (Throwable $e) {
        $alertLowStock = 0;
    }
}

$dashAlerts = [];
if ($alertPendingKyc > 0) {
    $dashAlerts[] = [
        'tone' => 'warn',
        'label' => 'Pending KYC',
        'count' => $alertPendingKyc,
        'href' => 'approve-kyc.php?status=pending',
        'hint' => 'Documents waiting for review',
    ];
}
if ($alertPendingUtr > 0) {
    $dashAlerts[] = [
        'tone' => 'info',
        'label' => 'Pending activations',
        'count' => $alertPendingUtr,
        'href' => 'activations.php?status=pending',
        'hint' => 'UTR / package requests',
    ];
}
if ($alertPendingWd > 0) {
    $dashAlerts[] = [
        'tone' => 'danger',
        'label' => 'Open withdrawals',
        'count' => $alertPendingWd,
        'href' => 'withdrawals.php?status=pending',
        'hint' => 'Awaiting approve / pay',
    ];
}
if ($alertLowStock > 0) {
    $dashAlerts[] = [
        'tone' => 'stock',
        'label' => 'Low stock',
        'count' => $alertLowStock,
        'href' => 'product-status.php',
        'hint' => 'Qty ≤ ' . $stockThreshold,
    ];
}

if ($showBinaryDash) {
    try {
        closing_ensure_tables($pdo);
        $closingSummary = closing_open_pair_summary($pdo);
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $lastClosing = $pdo->query('SELECT * FROM closing_runs ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    } catch (Throwable $e) {
        $lastClosing = null;
    }
}

$recentMembers = $pdo->query("
    SELECT m.*, p.name AS package_name
    FROM members m
    LEFT JOIN packages p ON p.id = m.package_id
    ORDER BY m.join_date DESC
    LIMIT 8
")->fetchAll();

$recentCommissions = $pdo->query("
    SELECT c.*, m.full_name, m.member_id AS mid
    FROM commissions c
    JOIN members m ON m.id = c.member_id
    ORDER BY c.created_at DESC
    LIMIT 8
")->fetchAll();

$iconUsers = '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>';
$iconCheck = '<svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
$iconCalendar = '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
$iconMoney = '<svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>';
$iconWallet = '<svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>';
$iconPackage = '<svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>';
$iconOut = '<svg viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>';
?>

<?php if ($dashAlerts): ?>
<div class="dash-alerts">
    <div class="dash-alerts-head">
        <strong>Needs attention</strong>
        <span class="muted"><?= count($dashAlerts) ?> alert<?= count($dashAlerts) === 1 ? '' : 's' ?></span>
    </div>
    <div class="dash-alerts-grid">
        <?php foreach ($dashAlerts as $al): ?>
        <a class="dash-alert tone-<?= e($al['tone']) ?>" href="<?= e($al['href']) ?>">
            <span class="dash-alert-count"><?= (int) $al['count'] ?></span>
            <span class="dash-alert-copy">
                <strong><?= e($al['label']) ?></strong>
                <small><?= e($al['hint']) ?></small>
            </span>
            <span class="dash-alert-go" aria-hidden="true">→</span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card g-blue">
        <div class="bg-icon"><?= $iconUsers ?></div>
        <div class="value"><?= $totalMembers ?></div>
        <div class="label">Total Members</div>
        <a class="more" href="members.php">More info →</a>
    </div>
    <div class="stat-card g-cyan">
        <div class="bg-icon"><?= $iconCheck ?></div>
        <div class="value"><?= $activeMembers ?></div>
        <div class="label">Active Members</div>
        <a class="more" href="members.php?status=active">More info →</a>
    </div>
    <div class="stat-card g-green">
        <div class="bg-icon"><?= $iconCalendar ?></div>
        <div class="value"><?= $todayJoins ?></div>
        <div class="label">Joined Today</div>
        <a class="more" href="members.php">More info →</a>
    </div>
    <div class="stat-card g-mint">
        <div class="bg-icon"><?= $iconMoney ?></div>
        <div class="value"><?= currency($totalCommissions) ?></div>
        <div class="label">Total Commissions</div>
        <a class="more" href="commissions.php">More info →</a>
    </div>
    <?php if ($showWithdrawalsDash): ?>
    <div class="stat-card g-red">
        <div class="bg-icon"><?= $iconWallet ?></div>
        <div class="value"><?= $pendingWithdrawals ?></div>
        <div class="label">Pending Withdrawals</div>
        <a class="more" href="withdrawals.php?status=pending">More info →</a>
    </div>
    <div class="stat-card g-orange">
        <div class="bg-icon"><?= $iconOut ?></div>
        <div class="value"><?= currency($totalPaidOut) ?></div>
        <div class="label">Paid Out</div>
        <a class="more" href="withdrawals.php">More info →</a>
    </div>
    <?php endif; ?>
    <div class="stat-card g-pink">
        <div class="bg-icon"><?= $iconWallet ?></div>
        <div class="value"><?= currency($walletTotal) ?></div>
        <div class="label">Wallet Balance</div>
        <a class="more" href="members.php">More info →</a>
    </div>
    <?php if ($showPackagesDash): ?>
    <div class="stat-card g-purple">
        <div class="bg-icon"><?= $iconPackage ?></div>
        <div class="value"><?= $totalPackages ?></div>
        <div class="label">Active Packages</div>
        <a class="more" href="packages.php">More info →</a>
    </div>
    <?php endif; ?>
    <?php if ($showBinaryDash): ?>
    <div class="stat-card g-cyan">
        <div class="bg-icon"><?= $iconCheck ?></div>
        <div class="value"><?= number_format((float) $closingSummary['pairs'], 1) ?></div>
        <div class="label">Open Binary Pairs</div>
        <a class="more" href="binary-closing.php">Close now →</a>
    </div>
    <div class="stat-card g-mint">
        <div class="bg-icon"><?= $iconMoney ?></div>
        <div class="value"><?= currency((float) $closingSummary['est_binary_gross']) ?></div>
        <div class="label">Est. Binary Gross</div>
        <a class="more" href="binary-closing.php">Preview →</a>
    </div>
    <?php endif; ?>
</div>

<?php if ($showBinaryDash && $lastClosing): ?>
<div class="panel" style="margin-bottom:1.25rem">
    <div class="panel-body" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between">
        <div>
            <strong>Last closing #<?= (int) $lastClosing['id'] ?></strong>
            <span class="muted"> · <?= e(date('d M Y H:i', strtotime((string) $lastClosing['created_at']))) ?></span>
            <div class="muted" style="margin-top:0.35rem;font-size:0.85rem">
                Paid <?= (int) $lastClosing['members_paid'] ?> · Binary net <?= currency((float) $lastClosing['binary_net_total']) ?> · Matching <?= currency((float) $lastClosing['matching_total']) ?>
            </div>
        </div>
        <a class="btn btn-outline btn-sm" href="binary-closing.php?run=<?= (int) $lastClosing['id'] ?>">View closing</a>
    </div>
</div>
<?php endif; ?>

<div class="dash-bottom">
    <div class="summary-stack">
        <div class="summary-card theme-blue">
            <div class="top">
                <span class="summary-icon blue"><?= $iconMoney ?></span>
                <span class="chip blue">Fund In</span>
            </div>
            <h3>Commission Pending</h3>
            <div class="summary-stats">
                <div><span>Total</span><strong><?= $pendingComm ?></strong></div>
                <div><span>Pending</span><strong><?= $pendingComm ?></strong></div>
            </div>
            <div class="summary-foot">
                <span class="hint">Awaiting approval</span>
                <a href="commissions.php?status=pending">View all →</a>
            </div>
        </div>

        <div class="summary-card theme-red">
            <div class="top">
                <span class="summary-icon red"><?= $iconWallet ?></span>
                <span class="chip red">Payout</span>
            </div>
            <h3>Withdrawal Request</h3>
            <div class="summary-stats">
                <div><span>Total</span><strong><?= $pendingWithdrawals ?></strong></div>
                <div><span>Pending</span><strong><?= $pendingWithdrawals ?></strong></div>
            </div>
            <div class="summary-foot">
                <span class="hint">Needs action</span>
                <a href="withdrawals.php?status=pending">View all →</a>
            </div>
        </div>

        <div class="summary-card theme-green">
            <div class="top">
                <span class="summary-icon green"><?= $iconCheck ?></span>
                <span class="chip green">Updates</span>
            </div>
            <h3>News</h3>
            <div class="summary-stats">
                <div><span>Total</span><strong><?= $newsCount ?></strong></div>
                <div><span>Active</span><strong><?= $newsCount ?></strong></div>
            </div>
            <div class="summary-foot">
                <span class="hint">Published items</span>
                <a href="news.php">Manage →</a>
            </div>
        </div>

        <div class="summary-card theme-orange">
            <div class="top">
                <span class="summary-icon orange"><?= $iconPackage ?></span>
                <span class="chip orange">Packages</span>
            </div>
            <h3>Active Packages</h3>
            <div class="summary-stats">
                <div><span>Total</span><strong><?= $totalPackages ?></strong></div>
                <div><span>Active</span><strong><?= $totalPackages ?></strong></div>
            </div>
            <div class="summary-foot">
                <span class="hint">Plan catalogue</span>
                <a href="packages.php">Manage →</a>
            </div>
        </div>
    </div>

    <div class="panel" style="margin:0">
        <div class="panel-header">
            <div>
                <h2>Recent Members</h2>
            </div>
            <a href="members.php" class="btn btn-outline btn-sm">View all</a>
        </div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Member ID</th>
                        <th>Name</th>
                        <th>Package</th>
                        <th>Status</th>
                        <th>Joined</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$recentMembers): ?>
                    <tr><td colspan="5">No members yet.</td></tr>
                <?php else: foreach ($recentMembers as $m): ?>
                    <tr>
                        <td><a href="member-view.php?id=<?= (int) $m['id'] ?>"><?= e($m['member_id']) ?></a></td>
                        <td><?= e($m['full_name']) ?></td>
                        <td><?= e($m['package_name'] ?? '—') ?></td>
                        <td><?= status_badge((string) $m['status']) ?></td>
                        <td><?= date('d M Y', strtotime($m['join_date'])) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2>Recent Commissions</h2>
        </div>
        <a href="commissions.php" class="btn btn-outline btn-sm">View all</a>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$recentCommissions): ?>
                <tr><td colspan="5">No commissions yet.</td></tr>
            <?php else: foreach ($recentCommissions as $c): ?>
                <tr>
                    <td><?= e($c['full_name']) ?> <small>(<?= e($c['mid']) ?>)</small></td>
                    <td><?= e(ucfirst($c['type'])) ?></td>
                    <td><?= currency((float) $c['amount']) ?></td>
                    <td><?= status_badge((string) $c['status']) ?></td>
                    <td><?= date('d M Y H:i', strtotime($c['created_at'])) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
