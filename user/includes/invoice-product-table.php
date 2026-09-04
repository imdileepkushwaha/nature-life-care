<?php
/** @var list<array> $invoiceLines */
$invoiceLines = $invoiceLines ?? [];
?>
<div class="shop-inv-table-wrap">
<table class="shop-inv-table shop-inv-table-kit">
    <thead>
    <tr>
        <th class="is-num">#</th>
        <th>Product</th>
        <th class="is-num">Qty</th>
        <th class="is-num">MRP</th>
        <th class="is-num">Tax %</th>
        <th class="is-num">Tax</th>
        <th class="is-num">MRP Value</th>
        <?php if (!empty($invoiceShowPaid)): ?>
        <th class="is-num">Amount</th>
        <?php endif; ?>
    </tr>
    </thead>
    <tbody>
    <?php if (!$invoiceLines): ?>
        <tr>
            <td colspan="<?= !empty($invoiceShowPaid) ? 8 : 7 ?>" class="shop-inv-empty">No products listed on this invoice yet.</td>
        </tr>
    <?php else: foreach ($invoiceLines as $i => $it):
        $it = invoice_enrich_line($it);
        ?>
        <tr>
            <td class="is-num"><?= $i + 1 ?></td>
            <td class="is-name">
                <?= e((string) ($it['product_name'] ?? '')) ?>
                <?php if (!empty($it['sku'])): ?>
                    <small class="shop-inv-sku"><?= e((string) $it['sku']) ?></small>
                <?php endif; ?>
            </td>
            <td class="is-num"><?= (int) $it['qty'] ?></td>
            <td class="is-num"><?= currency((float) $it['unit_mrp']) ?></td>
            <td class="is-num"><?= number_format((float) $it['tax_percent'], 2) ?>%</td>
            <td class="is-num"><?= currency((float) $it['tax_amount']) ?></td>
            <td class="is-num is-amt"><?= currency((float) $it['customer_value']) ?></td>
            <?php if (!empty($invoiceShowPaid)): ?>
            <td class="is-num"><?= currency((float) ($it['line_total'] ?? 0)) ?></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
