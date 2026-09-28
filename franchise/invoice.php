<?php
/**
 * Franchise Sales Invoice / Printable Receipt
 */
$pageTitle = 'Sales Invoice';
require_once __DIR__ . '/includes/header.php';

$saleId = (int) ($_GET['id'] ?? 0);
$frId = (int) $currentFranchise['id'];

$sale = franchise_sale_get($pdo, $saleId, $frId);
if (!$sale) {
    flash('error', 'Sale invoice not found.');
    header('Location: sales-report.php');
    exit;
}

$items = franchise_sale_items($pdo, $saleId);

function number_to_words_inr(float $num): string
{
    $units = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
              'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $num = floor($num);
    if ($num == 0) return 'Zero Rupees Only';

    $res = '';
    if ($num >= 10000000) {
        $cr = floor($num / 10000000);
        $res .= ($cr < 20 ? $units[$cr] : $tens[floor($cr/10)] . ' ' . $units[$cr%10]) . ' Crore ';
        $num %= 10000000;
    }
    if ($num >= 100000) {
        $lakh = floor($num / 100000);
        $res .= ($lakh < 20 ? $units[$lakh] : $tens[floor($lakh/10)] . ' ' . $units[$lakh%10]) . ' Lakh ';
        $num %= 100000;
    }
    if ($num >= 1000) {
        $th = floor($num / 1000);
        $res .= ($th < 20 ? $units[$th] : $tens[floor($th/10)] . ' ' . $units[$th%10]) . ' Thousand ';
        $num %= 1000;
    }
    if ($num >= 100) {
        $h = floor($num / 100);
        $res .= $units[$h] . ' Hundred ';
        $num %= 100;
    }
    if ($num > 0) {
        $res .= ($num < 20 ? $units[$num] : $tens[floor($num/10)] . ' ' . $units[$num%10]) . ' ';
    }
    return trim($res) . ' Rupees Only';
}
?>

<div style="margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center" class="no-print">
    <a href="sales-report.php" class="btn btn-outline btn-sm">← Back to Sales History</a>
    <div style="display:flex;gap:0.5rem">
        <a href="billing.php" class="btn btn-outline btn-sm">+ New Bill</a>
        <button onclick="window.print()" class="btn btn-primary btn-sm">
            <svg style="width:16px;height:16px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print Invoice
        </button>
    </div>
</div>

<div class="invoice-box">
    <div class="invoice-top">
        <div>
            <?php if ($logoUrl): ?>
                <img src="<?= e($logoUrl) ?>" alt="<?= e($company) ?>" style="max-height:48px;max-width:180px;object-fit:contain;margin-bottom:0.5rem">
            <?php else: ?>
                <h2 style="font-size:1.5rem;font-weight:700;margin-bottom:0.3rem"><?= e($company) ?></h2>
            <?php endif; ?>
            <p style="color:#64748b;font-size:0.85rem">Official Franchise Delivery Receipt</p>
        </div>
        <div style="text-align:right">
            <h1 style="font-size:1.4rem;font-weight:800;color:#0f172a;text-transform:uppercase;letter-spacing:0.04em">Retail Invoice</h1>
            <p style="font-size:0.95rem;font-weight:700;color:#0284c7;margin-top:0.25rem"><?= e($sale['bill_no']) ?></p>
            <p style="font-size:0.85rem;color:#64748b">Date: <?= date('d M Y', strtotime($sale['sale_date'])) ?></p>
        </div>
    </div>

    <div class="invoice-parties">
        <div style="background:#f8fafc;padding:1rem;border-radius:8px;border:1px solid #e2e8f0">
            <p style="font-size:0.75rem;text-transform:uppercase;font-weight:700;color:#64748b;margin-bottom:0.35rem">Issued By (Franchise)</p>
            <h4 style="font-size:1.05rem;color:#0f172a;margin-bottom:0.2rem"><?= e($sale['franchisee_name']) ?></h4>
            <p style="font-size:0.85rem;color:#475569">Franchise Code: <strong><?= e($sale['franchisee_code']) ?></strong></p>
            <?php if (!empty($sale['franchisee_gst'])): ?>
                <p style="font-size:0.85rem;color:#475569">GSTIN: <strong><?= e($sale['franchisee_gst']) ?></strong></p>
            <?php endif; ?>
            <?php if (!empty($sale['franchisee_phone'])): ?>
                <p style="font-size:0.85rem;color:#475569">Phone: <?= e($sale['franchisee_phone']) ?></p>
            <?php endif; ?>
            <?php if (!empty($sale['franchisee_address'])): ?>
                <p style="font-size:0.85rem;color:#475569"><?= e($sale['franchisee_address']) ?></p>
            <?php endif; ?>
        </div>

        <div style="background:#f8fafc;padding:1rem;border-radius:8px;border:1px solid #e2e8f0">
            <p style="font-size:0.75rem;text-transform:uppercase;font-weight:700;color:#64748b;margin-bottom:0.35rem">Billed To (Member)</p>
            <h4 style="font-size:1.05rem;color:#0f172a;margin-bottom:0.2rem"><?= e($sale['member_name']) ?></h4>
            <p style="font-size:0.85rem;color:#475569">Member ID: <strong><?= e($sale['member_code']) ?></strong></p>
            <?php if (!empty($sale['member_phone'])): ?>
                <p style="font-size:0.85rem;color:#475569">Phone: <?= e($sale['member_phone']) ?></p>
            <?php endif; ?>
            <?php if (!empty($sale['member_email'])): ?>
                <p style="font-size:0.85rem;color:#475569">Email: <?= e($sale['member_email']) ?></p>
            <?php endif; ?>
            <p style="font-size:0.85rem;color:#475569;margin-top:0.2rem">Payment: <strong style="text-transform:uppercase"><?= e($sale['payment_mode']) ?></strong></p>
        </div>
    </div>

    <div class="table-wrap" style="margin-bottom:1.5rem">
        <table class="data">
            <thead>
                <tr>
                    <th style="width:5%">#</th>
                    <th>Item Description</th>
                    <th>SKU</th>
                    <th style="text-align:right">Rate</th>
                    <th style="text-align:center">Qty</th>
                    <th style="text-align:right">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php $sn = 1; foreach ($items as $item): ?>
                <tr>
                    <td><?= $sn++ ?></td>
                    <td><strong><?= e($item['product_name']) ?></strong></td>
                    <td><code><?= e($item['sku'] ?: '—') ?></code></td>
                    <td style="text-align:right">₹<?= number_format((float) $item['rate'], 2) ?></td>
                    <td style="text-align:center"><strong><?= number_format((int) $item['qty']) ?></strong></td>
                    <td style="text-align:right"><strong>₹<?= number_format((float) $item['amount'], 2) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bill-totals-row" style="background:#f0f9ff;font-weight:700">
                    <td colspan="4" style="text-align:right">Total Quantity & Grand Total:</td>
                    <td style="text-align:center"><?= number_format(array_sum(array_column($items, 'qty'))) ?></td>
                    <td style="text-align:right;color:#0284c7;font-size:1.1rem">₹<?= number_format((float) $sale['total_amount'], 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:1.5rem;padding-top:1rem;border-top:1px solid #e2e8f0;flex-wrap:wrap">
        <div>
            <p style="font-size:0.85rem;color:#64748b">Amount in Words:</p>
            <p style="font-size:0.92rem;font-weight:600;color:#0f172a"><?= number_to_words_inr((float) $sale['total_amount']) ?></p>
            <?php if (!empty($sale['note'])): ?>
                <p style="font-size:0.82rem;color:#64748b;margin-top:0.35rem">Note: <?= e($sale['note']) ?></p>
            <?php endif; ?>
        </div>
        <div style="text-align:right">
            <p style="font-size:0.85rem;color:#64748b">Authorized Signatory</p>
            <div style="height:45px"></div>
            <p style="font-size:0.9rem;font-weight:700;border-top:1px dashed #94a3b8;padding-top:0.25rem"><?= e($sale['franchisee_name']) ?></p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
