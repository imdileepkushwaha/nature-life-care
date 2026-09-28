<?php
/**
 * Franchise Dashboard - Admin Styled with Custom Color Theme
 */
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

$frId = (int) $currentFranchise['id'];
$stats = franchise_stat_summary($pdo, $frId);

// Recent sales
$recentSales = franchise_sales($pdo, [
    'franchisee_id' => $frId,
]);
$recentSales = array_slice($recentSales, 0, 5);

// Recent company purchases
$recentPurchases = franchise_purchases($pdo, [
    'franchisee_id' => $frId,
]);
$recentPurchases = array_slice($recentPurchases, 0, 5);

$iconStock = '<svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>';
$iconMoney = '<svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
$iconPurchases = '<svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>';
$iconSales = '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
?>

<!-- Franchise Details Overview Panel -->
<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem">
        <div>
            <h2>Terminal Overview: <?= e($currentFranchise['name']) ?></h2>
            <p style="color:#64748b;font-size:0.88rem;margin-top:0.25rem">
                Code: <strong><?= e($currentFranchise['franchisee_code']) ?></strong> &bull;
                Type: <strong><?= e($currentFranchise['type_name'] ?? 'Franchise') ?></strong> &bull;
                Status: <span class="badge badge-success"><?= e($currentFranchise['status']) ?></span>
                <?php if (!empty($currentFranchise['gst_no'])): ?>
                    &bull; GSTIN: <code><?= e($currentFranchise['gst_no']) ?></code>
                <?php endif; ?>
            </p>
        </div>
        <div>
            <a href="billing.php" class="btn btn-primary btn-sm">+ Issue Bill to Member</a>
        </div>
    </div>
</div>

<!-- Stats Grid (Admin Panel Cards) -->
<div class="stats-grid">
    <div class="stat-card g-orange">
        <div class="bg-icon"><?= $iconMoney ?></div>
        <div class="value">₹<?= number_format($stats['commission_total'], 2) ?></div>
        <div class="label">Total Commission Earned</div>
        <a class="more" href="commissions.php">View earnings breakdown →</a>
    </div>

    <div class="stat-card g-green">
        <div class="bg-icon"><?= $iconMoney ?></div>
        <div class="value">₹<?= number_format($stats['wallet_balance'], 2) ?></div>
        <div class="label">Commission Wallet Balance</div>
        <a class="more" href="commissions.php">Available balance →</a>
    </div>

    <?php if (!empty($canCreateSubFranchise)): ?>
    <div class="stat-card g-cyan">
        <div class="bg-icon"><?= $icoTeam ?></div>
        <div class="value"><?= number_format($stats['team_count']) ?></div>
        <div class="label">Downline Franchisees</div>
        <a class="more" href="team-report.php">View network team →</a>
    </div>
    <?php endif; ?>

    <div class="stat-card g-blue">
        <div class="bg-icon"><?= $iconStock ?></div>
        <div class="value"><?= number_format($stats['stock_units']) ?></div>
        <div class="label">Stock Units in Hand</div>
        <a class="more" href="stock.php"><?= $stats['products_count'] ?> distinct products →</a>
    </div>

    <div class="stat-card g-mint">
        <div class="bg-icon"><?= $iconPurchases ?></div>
        <div class="value">₹<?= number_format($stats['purchase_amount'], 2) ?></div>
        <div class="label">Company Purchases</div>
        <a class="more" href="purchases.php"><?= $stats['purchase_invoices'] ?> invoices received →</a>
    </div>

    <div class="stat-card g-purple">
        <div class="bg-icon"><?= $iconSales ?></div>
        <div class="value">₹<?= number_format($stats['sales_amount'], 2) ?></div>
        <div class="label">Member Sales Billed</div>
        <a class="more" href="sales-report.php"><?= $stats['sales_count'] ?> bills completed →</a>
    </div>
</div>

<!-- Recent Activity Tables (Admin Panel Panels) -->
<div class="franchise-dash-split">
    <!-- Recent Member Sales -->
    <div class="panel">
        <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between">
            <h2>Recent Member Sales</h2>
            <a href="sales-report.php" class="btn btn-outline btn-sm">View All →</a>
        </div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Bill No</th>
                        <th>Member</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$recentSales): ?>
                    <tr><td colspan="5" style="text-align:center;padding:2rem;color:#94a3b8">No sales recorded yet. <a href="billing.php">Create a bill</a></td></tr>
                <?php else: foreach ($recentSales as $s): ?>
                    <tr>
                        <td><strong><?= e($s['bill_no']) ?></strong></td>
                        <td><?= e($s['member_code']) ?><br><small style="color:#64748b"><?= e($s['member_name']) ?></small></td>
                        <td><?= date('d M Y', strtotime($s['sale_date'])) ?></td>
                        <td><strong style="color:var(--brand)">₹<?= number_format((float) $s['total_amount'], 2) ?></strong></td>
                        <td>
                            <a href="invoice.php?id=<?= (int) $s['id'] ?>" class="btn btn-outline btn-sm">Invoice</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Stock Purchases -->
    <div class="panel">
        <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between">
            <h2>Company Stock Invoices</h2>
            <a href="purchases.php" class="btn btn-outline btn-sm">View All →</a>
        </div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Invoice No</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$recentPurchases): ?>
                    <tr><td colspan="5" style="text-align:center;padding:2rem;color:#94a3b8">No stock purchases received from company yet.</td></tr>
                <?php else: foreach ($recentPurchases as $p): ?>
                    <tr>
                        <td><strong><?= e($p['invoice_no'] ?: ('INV-' . $p['id'])) ?></strong></td>
                        <td><?= date('d M Y', strtotime($p['purchase_date'])) ?></td>
                        <td><strong style="color:var(--brand)">₹<?= number_format((float) $p['total_amount'], 2) ?></strong></td>
                        <td><span class="badge badge-success"><?= e($p['status']) ?></span></td>
                        <td>
                            <a href="purchases.php?view=<?= (int) $p['id'] ?>" class="btn btn-outline btn-sm">View</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
