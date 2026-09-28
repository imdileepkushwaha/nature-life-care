<?php
/**
 * Franchise Header - Admin Panel Sidebar Layout
 */
require_once __DIR__ . '/auth.php';

$currentFranchise = franchise_require_auth($pdo);
$company = setting('company_name', 'Binary MLM');
$logoUrl = company_logo_url();
$favUrl = company_favicon_url();
$flash = get_flash();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

function fr_nav_ico(string $svg): string
{
    return '<span class="nav-ico">' . $svg . '</span>';
}

$icoDash = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>';
$icoStock = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
$icoPurchases = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>';
$icoBill = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
$icoSales = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>';
$icoProfile = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
$icoTeam = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
$icoIncome = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
$icoLogout = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>';

$userHierarchy = (int) ($currentFranchise['hierarchy_level'] ?? 4);
$canCreateSubFranchise = ($userHierarchy < 4);
$pageLabel = $pageTitle ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageLabel) ?> | Franchise Portal | <?= e($company) ?></title>
    <?php if ($favUrl): ?><link rel="icon" href="<?= e($favUrl) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/../../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="assets/css/franchise.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/franchise.css') ?>">
</head>
<body>
<div class="app">
    <!-- Left Sidebar Menu -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="admin-brand-row">
                <span class="franchise-brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/><path d="M9 10h.01M15 10h.01"/></svg>
                </span>
                <div class="admin-brand-copy">
                    <div class="brand-text">Franchise Terminal</div>
                    <span class="admin-brand-sub"><?= e($currentFranchise['franchisee_code']) ?></span>
                </div>
                <button type="button" class="sidebar-close-btn" id="sidebarCloseBtn" aria-label="Close sidebar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="browse-card">
                <div class="browse-card-text">
                    <span class="browse-label">Type</span>
                    <strong class="browse-nav" style="color:var(--brand)">
                        <?= e($currentFranchise['type_name'] ?? 'Franchise') ?>
                        <?php if ((float)($currentFranchise['commission_percent'] ?? 0) > 0): ?>
                            (<?= number_format((float)$currentFranchise['commission_percent'], 0) ?>%)
                        <?php endif; ?>
                    </strong>
                </div>
                <span class="browse-pill fr-pill">ONLINE</span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-head">
                    <span class="nav-section-label">Main</span>
                    <span class="nav-section-line"></span>
                </div>

                <a href="index.php" class="nav-link <?= $currentPage === 'index' ? 'active' : '' ?>">
                    <span class="nav-link-left">
                        <?= fr_nav_ico($icoDash) ?>
                        <span class="nav-label">Dashboard</span>
                    </span>
                </a>
            </div>

            <?php if ($canCreateSubFranchise): ?>
            <div class="nav-section">
                <div class="nav-section-head">
                    <span class="nav-section-label">Team & Channel</span>
                    <span class="nav-section-line"></span>
                </div>

                <a href="team-add.php" class="nav-link <?= $currentPage === 'team-add' ? 'active' : '' ?>">
                    <span class="nav-link-left">
                        <?= fr_nav_ico($icoTeam) ?>
                        <span class="nav-label">Add Sub-Franchise</span>
                    </span>
                </a>

                <a href="team-report.php" class="nav-link <?= $currentPage === 'team-report' ? 'active' : '' ?>">
                    <span class="nav-link-left">
                        <?= fr_nav_ico($icoTeam) ?>
                        <span class="nav-label">My Downline Network</span>
                    </span>
                </a>
            </div>
            <?php endif; ?>

            <div class="nav-section">
                <div class="nav-section-head">
                    <span class="nav-section-label">Financials & Earnings</span>
                    <span class="nav-section-line"></span>
                </div>

                <a href="commissions.php" class="nav-link <?= $currentPage === 'commissions' ? 'active' : '' ?>">
                    <span class="nav-link-left">
                        <?= fr_nav_ico($icoIncome) ?>
                        <span class="nav-label">My Commission Income</span>
                    </span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-head">
                    <span class="nav-section-label">Inventory & Stock</span>
                    <span class="nav-section-line"></span>
                </div>

                <a href="stock.php" class="nav-link <?= $currentPage === 'stock' ? 'active' : '' ?>">
                    <span class="nav-link-left">
                        <?= fr_nav_ico($icoStock) ?>
                        <span class="nav-label">My Stock Inventory</span>
                    </span>
                </a>

                <a href="purchases.php" class="nav-link <?= $currentPage === 'purchases' ? 'active' : '' ?>">
                    <span class="nav-link-left">
                        <?= fr_nav_ico($icoPurchases) ?>
                        <span class="nav-label">Stock Purchases</span>
                    </span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-head">
                    <span class="nav-section-label">Sales & Billing</span>
                    <span class="nav-section-line"></span>
                </div>

                <a href="billing.php" class="nav-link <?= $currentPage === 'billing' ? 'active' : '' ?>">
                    <span class="nav-link-left">
                        <?= fr_nav_ico($icoBill) ?>
                        <span class="nav-label">Bill to Member</span>
                    </span>
                </a>

                <a href="sales-report.php" class="nav-link <?= in_array($currentPage, ['sales-report', 'invoice'], true) ? 'active' : '' ?>">
                    <span class="nav-link-left">
                        <?= fr_nav_ico($icoSales) ?>
                        <span class="nav-label">Sales History</span>
                    </span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-head">
                    <span class="nav-section-label">Account</span>
                    <span class="nav-section-line"></span>
                </div>

                <a href="profile.php" class="nav-link <?= $currentPage === 'profile' ? 'active' : '' ?>">
                    <span class="nav-link-left">
                        <?= fr_nav_ico($icoProfile) ?>
                        <span class="nav-label">My Profile & Security</span>
                    </span>
                </a>
            </div>
        </nav>

        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="main">
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
            </button>
            <div class="topbar-welcome">
                <span class="eyebrow">Franchise Terminal</span>
                <strong class="topbar-company"><?= e($company) ?></strong>
            </div>

            <div class="topbar-right">
                <a href="billing.php" class="btn btn-primary btn-sm topbar-bill-btn" title="Create New Member Bill">
                    <svg style="width:14px;height:14px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span class="topbar-bill-label">New Member Bill</span>
                    <span class="topbar-bill-short">Bill</span>
                </a>

                <div class="topbar-dropdown" data-dropdown>
                    <button type="button" class="user-pill" data-dropdown-toggle aria-expanded="false" aria-haspopup="true">
                        <span class="user-avatar fr-avatar">
                            <?= strtoupper(substr($currentFranchise['name'] ?? 'F', 0, 1)) ?>
                            <span class="online-dot"></span>
                        </span>
                        <span class="user-meta">
                            <strong><?= e($currentFranchise['name']) ?></strong>
                            <small><?= e($currentFranchise['franchisee_code']) ?></small>
                        </span>
                        <svg class="user-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="dropdown-menu dropdown-user" data-dropdown-menu>
                        <div class="dropdown-user-head">
                            <span class="user-avatar sm fr-avatar"><?= strtoupper(substr($currentFranchise['name'] ?? 'F', 0, 1)) ?></span>
                            <div>
                                <strong><?= e($currentFranchise['name']) ?></strong>
                                <small><?= e($currentFranchise['franchisee_code']) ?></small>
                            </div>
                        </div>
                        <a href="profile.php" class="dropdown-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="7" r="4"/><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/></svg>
                            Profile &amp; Password
                        </a>
                        <a href="stock.php" class="dropdown-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                            My Stock Inventory
                        </a>
                        <a href="sales-report.php" class="dropdown-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                            Sales History
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
            <?php if ($flash && !empty($flash['message'])): ?>
                <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : $flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <!-- Page Banner with Breadcrumbs -->
            <div class="page-banner">
                <div class="page-banner-left">
                    <div class="page-banner-icon fr-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/><path d="M9 10h.01M15 10h.01"/></svg>
                    </div>
                    <div class="page-banner-meta">
                        <span class="page-banner-badge fr-badge"><span class="dot"></span> Franchise Portal</span>
                        <h2><?= e($pageLabel) ?></h2>
                    </div>
                </div>
                <nav class="page-breadcrumb" aria-label="Breadcrumb">
                    <ol>
                        <li class="page-breadcrumb-home" aria-hidden="true">
                            <a href="index.php" style="color:inherit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                            </a>
                        </li>
                        <li>
                            <span class="page-breadcrumb-sep" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="9 18 15 12 9 6"/></svg>
                            </span>
                            <span class="current" aria-current="page"><?= e($pageLabel) ?></span>
                        </li>
                    </ol>
                </nav>
            </div>
