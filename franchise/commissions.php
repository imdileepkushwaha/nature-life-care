<?php
/**
 * Franchise Portal - My Commission Income & Ledger
 */
$pageTitle = 'Commission Income';
require_once __DIR__ . '/includes/header.php';

$frId = (int) $currentFranchise['id'];
$commissions = franchise_commissions($pdo, ['franchisee_id' => $frId]);

$totalEarned = 0.0;
foreach ($commissions as $c) {
    $totalEarned += (float) ($c['commission_amount'] ?? 0);
}
$walletBal = (float) ($currentFranchise['wallet_balance'] ?? 0);
?>

<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem">
        <div>
            <h2>My Commission Income &amp; Earnings</h2>
            <p style="color:#64748b;font-size:0.88rem;margin-top:0.25rem">
                Transparent breakdown of margins earned from member billings and downline sales.
            </p>
        </div>
        <div style="display:flex;gap:0.75rem;align-items:center">
            <span style="background:#ecfdf5;color:#059669;padding:0.4rem 0.8rem;border-radius:8px;font-weight:600;font-size:0.88rem">
                Wallet Balance: ₹<?= number_format($walletBal, 2) ?>
            </span>
            <span style="background:#eff6ff;color:#2563eb;padding:0.4rem 0.8rem;border-radius:8px;font-weight:600;font-size:0.88rem">
                Total Earned: ₹<?= number_format($totalEarned, 2) ?>
            </span>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <h2>Commission Statements (<?= count($commissions) ?> records)</h2>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date &amp; Time</th>
                    <th>Bill Number</th>
                    <th>Billed By / Downline</th>
                    <th>Role Type</th>
                    <th>Bill Amount</th>
                    <th>Your Margin %</th>
                    <th>Commission Earned</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($commissions)): ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:2.5rem 1rem;color:#64748b">
                        <svg style="width:40px;height:40px;stroke:#94a3b8;fill:none;margin-bottom:0.5rem" viewBox="0 0 24 24" stroke-width="1.5">
                            <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                        </svg>
                        <br>
                        <strong>No commission records yet.</strong>
                        <p style="font-size:0.85rem;margin-top:0.25rem">Commissions will be automatically credited here when you or your downline network bills products to members.</p>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($commissions as $idx => $r): ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <td><?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></td>
                    <td><strong><?= e($r['bill_no']) ?></strong></td>
                    <td>
                        <strong><?= e($r['source_code']) ?></strong><br>
                        <small style="color:#64748b"><?= e($r['source_name']) ?></small>
                    </td>
                    <td>
                        <span class="badge badge-info"><?= e($r['source_type_name'] ?? 'Retailer') ?></span>
                    </td>
                    <td>₹<?= number_format((float)$r['bill_amount'], 2) ?></td>
                    <td><strong style="color:#0284c7"><?= number_format((float)$r['commission_percent'], 2) ?>%</strong></td>
                    <td>
                        <strong style="color:#059669;font-size:1rem">+ ₹<?= number_format((float)$r['commission_amount'], 2) ?></strong>
                    </td>
                    <td>
                        <span class="badge badge-success">Credited</span>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
