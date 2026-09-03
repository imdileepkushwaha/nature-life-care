<?php
/**
 * Super Admin shared layout
 */
require_superadmin();
feature_ensure_defaults($pdo);

$company = setting('company_name', 'Binary MLM');
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$saName = $_SESSION['superadmin_name'] ?? 'Super Admin';
$saUser = $_SESSION['superadmin_username'] ?? 'superadmin';
$summary = feature_summary();
$planLabel = [
    'hybrid' => 'Hybrid',
    'binary' => 'Binary',
    'level' => 'Level',
    'unilevel' => 'Unilevel',
    'matrix' => 'Matrix ' . matrix_width() . '×',
][plan_mode()] ?? plan_mode();

$saIco = static function (string $path): string {
    return '<span class="nav-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">' . $path . '</svg></span>';
};
$icoDash = $saIco('<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>');
$icoFeat = $saIco('<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>');
$icoMoney = $saIco('<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>');
$icoBrand = $saIco('<path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/>');
$icoLicense = $saIco('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/><circle cx="12" cy="16" r="1"/>');
$icoSettings = $saIco('<circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>');
$icoAdmin = $saIco('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>');
$icoUser = $saIco('<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>');
$favUrl = company_favicon_url();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Super Admin') ?> | <?= e($company) ?></title>
    <?php if ($favUrl): ?><link rel="icon" href="<?= e($favUrl) ?>"><?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="../assets/css/superadmin.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/superadmin.css') ?>">
</head>
<body class="sa-body">
<div class="app sa-app">
    <aside class="sidebar sa-sidebar" id="sidebar">
        <div class="sidebar-brand sa-side-brand">
            <div class="sa-brand-row">
                <span class="sa-brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </span>
                <div class="sa-brand-copy">
                    <div class="brand-text">Super Admin</div>
                    <span class="sa-brand-sub">Platform control</span>
                </div>
            </div>
            <div class="browse-card sa-client-card">
                <div class="browse-card-text">
                    <span class="browse-label">Client install</span>
                    <strong class="browse-nav"><?= e($company) ?></strong>
                    <span class="sa-plan-chip"><?= e($planLabel) ?> plan</span>
                </div>
                <span class="browse-pill sa-pill">SA</span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-head">
                    <span class="nav-section-label">Control</span>
                    <span class="nav-section-line"></span>
                </div>
                <a href="index.php" class="nav-link <?= $currentPage === 'index' ? 'active' : '' ?>">
                    <span class="nav-link-left"><?= $icoDash ?><span class="nav-label">Dashboard</span></span>
                </a>
                <a href="features.php" class="nav-link <?= $currentPage === 'features' ? 'active' : '' ?>">
                    <span class="nav-link-left"><?= $icoFeat ?><span class="nav-label">Plan &amp; Features</span></span>
                </a>
                <a href="commission.php" class="nav-link <?= $currentPage === 'commission' ? 'active' : '' ?>">
                    <span class="nav-link-left"><?= $icoMoney ?><span class="nav-label">Commission Rates</span></span>
                </a>
                <a href="branding.php" class="nav-link <?= $currentPage === 'branding' ? 'active' : '' ?>">
                    <span class="nav-link-left"><?= $icoBrand ?><span class="nav-label">Client Branding</span></span>
                </a>
                <a href="license.php" class="nav-link <?= $currentPage === 'license' ? 'active' : '' ?>">
                    <span class="nav-link-left"><?= $icoLicense ?><span class="nav-label">Client License</span></span>
                </a>
                <a href="settings.php" class="nav-link <?= $currentPage === 'settings' || $currentPage === 'password' ? 'active' : '' ?>">
                    <span class="nav-link-left"><?= $icoSettings ?><span class="nav-label">Settings</span></span>
                </a>
            </div>
            <div class="nav-section">
                <div class="nav-section-head">
                    <span class="nav-section-label">Open panels</span>
                    <span class="nav-section-line"></span>
                </div>
                <a href="../admin/login.php" class="nav-link" target="_blank" rel="noopener">
                    <span class="nav-link-left"><?= $icoAdmin ?><span class="nav-label">Client Admin</span></span>
                    <span class="sa-ext" aria-hidden="true">↗</span>
                </a>
                <a href="../user/login.php" class="nav-link" target="_blank" rel="noopener">
                    <span class="nav-link-left"><?= $icoUser ?><span class="nav-label">User Panel</span></span>
                    <span class="sa-ext" aria-hidden="true">↗</span>
                </a>
            </div>
        </nav>

        <div class="sidebar-footer sa-side-foot">
            <div class="sa-side-user">
                <span class="sa-side-avatar"><?= strtoupper(substr($saName, 0, 1)) ?></span>
                <div>
                    <strong><?= e($saName) ?></strong>
                    <small>@<?= e($saUser) ?></small>
                </div>
            </div>
            <a href="logout.php" class="logout-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Logout
            </a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar sa-topbar">
            <div class="sa-top-left">
                <button class="menu-toggle" id="menuToggle" aria-label="Menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="sa-top-title">
                    <span class="sa-topbar-eyebrow">Platform owner</span>
                    <strong><?= e($pageTitle ?? 'Dashboard') ?></strong>
                </div>
            </div>

            <div class="topbar-right sa-top-right">
                <div class="sa-top-meta" title="Active client">
                    <span class="sa-top-meta-label">Client</span>
                    <strong><?= e($company) ?></strong>
                </div>

                <button type="button" class="topbar-btn sa-top-btn" id="fullscreenBtn" title="Toggle fullscreen" aria-label="Fullscreen">
                    <svg class="ico-expand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                    <svg class="ico-compress" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" hidden><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                </button>

                <div class="topbar-dropdown" data-dropdown>
                    <button type="button" class="user-pill sa-user-pill" data-dropdown-toggle aria-expanded="false" aria-haspopup="true">
                        <span class="user-avatar">
                            <?= strtoupper(substr($saName, 0, 1)) ?>
                            <span class="online-dot"></span>
                        </span>
                        <span class="user-meta">
                            <strong><?= e($saUser) ?></strong>
                            <small>Super Admin</small>
                        </span>
                        <svg class="user-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="dropdown-menu dropdown-user sa-user-menu" data-dropdown-menu>
                        <div class="dropdown-user-head">
                            <span class="user-avatar sm"><?= strtoupper(substr($saName, 0, 1)) ?></span>
                            <div>
                                <strong><?= e($saName) ?></strong>
                                <small>@<?= e($saUser) ?></small>
                            </div>
                        </div>
                        <a href="index.php" class="dropdown-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                            Dashboard
                        </a>
                        <a href="features.php" class="dropdown-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/></svg>
                            Plan &amp; Features
                        </a>
                        <a href="settings.php?tab=security" class="dropdown-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            Settings · Security
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="../admin/login.php" class="dropdown-item" target="_blank" rel="noopener">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            Client Admin
                        </a>
                        <a href="../user/login.php" class="dropdown-item" target="_blank" rel="noopener">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            User Panel
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="logout.php" class="dropdown-item danger">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>
        <main class="content">
            <?php $flash = get_flash(); if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>
