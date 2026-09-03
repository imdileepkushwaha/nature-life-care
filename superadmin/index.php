<?php
require_once __DIR__ . '/../config/database.php';
$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';

$s = feature_summary();
$modeLabel = [
    'hybrid' => 'Hybrid (Binary + Level)',
    'binary' => 'Binary only',
    'level' => 'Level only',
    'unilevel' => 'Unilevel',
    'matrix' => 'Matrix ' . matrix_width() . '×',
][$s['plan_mode']] ?? $s['plan_mode'];

$modules = [
    ['Binary income / tree', $s['binary']],
    ['Level income', $s['level']],
    ['Referral income', $s['referral']],
    ['Matching income', $s['matching']],
    ['Packages', $s['package']],
    ['T-PIN', $s['tpin']],
    ['UTR activation', $s['utr']],
    ['Wallet topup', $s['wallet_topup']],
    ['Product shop', $s['products']],
    ['Product → activation', $s['product_activates']],
    ['Product Only mode', !empty($s['product_only'])],
    ['Withdrawals', $s['withdrawals']],
    ['KYC', $s['kyc']],
];
$onCount = count(array_filter(array_column($modules, 1)));
$licenseOk = !empty($s['license_ok']);
?>
<section class="sa-hero">
    <div>
        <span class="sa-hero-kicker">Control center</span>
        <h1>Super Admin</h1>
        <p>You decide the plan mode and modules. Client Admin &amp; User panels only show what you enable here.</p>
    </div>
    <div class="sa-hero-actions">
        <a href="features.php" class="btn btn-primary">Configure features</a>
        <a href="license.php" class="btn-ghost">License</a>
    </div>
</section>

<div class="sa-stat-grid">
    <article class="sa-stat">
        <span class="sa-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
        </span>
        <span class="sa-stat-label">Client</span>
        <strong><?= e($company) ?></strong>
    </article>
    <article class="sa-stat">
        <span class="sa-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="3"/><circle cx="6" cy="19" r="3"/><circle cx="18" cy="19" r="3"/><path d="M12 8v3M9.5 14.5L6 17M14.5 14.5L18 17"/></svg>
        </span>
        <span class="sa-stat-label">Plan mode</span>
        <strong><?= e($modeLabel) ?></strong>
    </article>
    <article class="sa-stat">
        <span class="sa-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
        </span>
        <span class="sa-stat-label">License</span>
        <strong><?= $licenseOk ? 'Active' : 'Blocked' ?><?= !empty($s['license_expires']) ? ' · ' . e($s['license_expires']) : '' ?></strong>
    </article>
    <article class="sa-stat">
        <span class="sa-stat-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        </span>
        <span class="sa-stat-label">Active modules</span>
        <strong><?= (int) $onCount ?> / <?= count($modules) ?> · <?= e($s['preset']) ?></strong>
    </article>
</div>

<div class="sa-panel">
    <div class="sa-panel-head">
        <div>
            <h2>Module status</h2>
            <p>Live flags for this client install</p>
        </div>
        <a href="features.php" class="btn btn-outline btn-sm">Edit</a>
    </div>
    <div class="sa-panel-body">
        <div class="sa-mod-grid">
            <?php foreach ($modules as [$label, $on]): ?>
            <div class="sa-mod">
                <span class="sa-mod-name"><?= e($label) ?></span>
                <?= $on ? '<span class="sa-chip on">ON</span>' : '<span class="sa-chip off">OFF</span>' ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="sa-note">Client Admin cannot change these. They only run day-to-day operations on enabled modules.</div>
    </div>
</div>

<div class="sa-quick-links">
    <a class="sa-quick-link" href="features.php">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/></svg></span>
        <div>Plan &amp; Features<small>Presets &amp; toggles</small></div>
    </a>
    <a class="sa-quick-link" href="branding.php">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg></span>
        <div>Branding<small>Name, currency, IDs</small></div>
    </a>
    <a class="sa-quick-link" href="../admin/login.php" target="_blank" rel="noopener">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
        <div>Open Admin<small>Client panel</small></div>
    </a>
    <a class="sa-quick-link" href="../user/login.php" target="_blank" rel="noopener">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
        <div>Open User<small>Member panel</small></div>
    </a>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
