<?php
/**
 * Franchise Sales History Report
 */
$pageTitle = 'Sales History';
require_once __DIR__ . '/includes/header.php';

$frId = (int) $currentFranchise['id'];

$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'from' => trim($_GET['from'] ?? ''),
    'to' => trim($_GET['to'] ?? ''),
    'franchisee_id' => $frId,
];

$sales = franchise_sales($pdo, $filters);

$totalRevenue = 0.0;
foreach ($sales as $s) {
    $totalRevenue += (float) $s['total_amount'];
}
?>

<div class="panel">
    <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem">
        <div>
            <h2>Member Sales History (<?= count($sales) ?> Bills)</h2>
            <p style="color:#64748b;font-size:0.88rem;margin-top:0.25rem">
                All retail bills and product issues completed by your franchise to registered members.
            </p>
        </div>
        <div>
            <a href="billing.php" class="btn btn-primary btn-sm">+ New Member Bill</a>
        </div>
    </div>

    <div class="panel-body">
        <!-- Filter Form -->
        <form method="get" action="sales-report.php" class="franchise-filter-form" style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="margin-bottom:0">
                <label style="font-size:0.8rem">Search</label>
                <input type="text" name="q" class="form-control" value="<?= e($filters['q']) ?>" placeholder="Bill No, Member ID...">
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label style="font-size:0.8rem">From Date</label>
                <input type="date" name="from" class="form-control" value="<?= e($filters['from']) ?>">
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label style="font-size:0.8rem">To Date</label>
                <input type="date" name="to" class="form-control" value="<?= e($filters['to']) ?>">
            </div>
            <button type="submit" class="btn btn-outline" style="height:38px">Filter</button>
            <?php if ($filters['q'] !== '' || $filters['from'] !== '' || $filters['to'] !== ''): ?>
                <a href="sales-report.php" class="btn btn-outline" style="height:38px">Reset</a>
            <?php endif; ?>

            <div style="margin-left:auto;font-size:0.92rem;align-self:center">
                Total Billed: <strong style="color:var(--brand);font-size:1.1rem">₹<?= number_format($totalRevenue, 2) ?></strong>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Bill No</th>
                    <th>Member ID</th>
                    <th>Member Name</th>
                    <th>Date</th>
                    <th>Payment</th>
                    <th>Total Amount</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$sales): ?>
                <tr>
                    <td colspan="7" style="text-align:center;color:#94a3b8;padding:2.5rem">
                        No sales bills found. <a href="billing.php">Create a bill</a>
                    </td>
                </tr>
            <?php else: foreach ($sales as $s): ?>
                <tr>
                    <td><strong><?= e($s['bill_no']) ?></strong></td>
                    <td><code><?= e($s['member_code']) ?></code></td>
                    <td><?= e($s['member_name']) ?><br><small style="color:#64748b"><?= e($s['member_phone']) ?></small></td>
                    <td><?= date('d M Y', strtotime($s['sale_date'])) ?></td>
                    <td><span class="badge badge-info" style="text-transform:uppercase"><?= e($s['payment_mode']) ?></span></td>
                    <td><strong style="color:#0284c7">₹<?= number_format((float) $s['total_amount'], 2) ?></strong></td>
                    <td>
                        <a href="invoice.php?id=<?= (int) $s['id'] ?>" class="btn btn-outline btn-sm">
                            <svg style="width:14px;height:14px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            View Invoice
                        </a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
