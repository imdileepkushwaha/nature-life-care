<?php
/**
 * Invoice line helpers: MRP, GST (included in MRP), customer value.
 */

function invoice_default_tax_percent(): float
{
    return max(0.0, min(40.0, (float) setting('invoice_tax_percent', '0')));
}

function invoice_gstin(): string
{
    return trim((string) setting('invoice_gstin', setting('contact_gstin', '')));
}

/**
 * GST treated as included in MRP / customer value (Indian MRP style).
 * @return array{customer_value:float,tax_percent:float,tax_amount:float,taxable:float}
 */
function invoice_tax_from_mrp(float $mrpTotal, float $taxPercent): array
{
    $customerValue = round(max(0.0, $mrpTotal), 2);
    $taxPercent = max(0.0, $taxPercent);
    if ($taxPercent <= 0 || $customerValue <= 0) {
        return [
            'customer_value' => $customerValue,
            'tax_percent' => $taxPercent,
            'tax_amount' => 0.0,
            'taxable' => $customerValue,
        ];
    }
    $taxable = round($customerValue * 100 / (100 + $taxPercent), 2);
    $taxAmount = round($customerValue - $taxable, 2);
    return [
        'customer_value' => $customerValue,
        'tax_percent' => $taxPercent,
        'tax_amount' => $taxAmount,
        'taxable' => $taxable,
    ];
}

/**
 * @param array<string, mixed> $line
 * @return array<string, mixed>
 */
function invoice_enrich_line(array $line): array
{
    $qty = max(1, (int) ($line['qty'] ?? 1));
    $mrp = (float) ($line['unit_mrp'] ?? $line['mrp'] ?? 0);
    if ($mrp <= 0) {
        $mrp = (float) ($line['unit_price'] ?? $line['current_price'] ?? $line['price'] ?? 0);
    }
    $taxPct = (float) ($line['tax_percent'] ?? 0);
    if ($taxPct <= 0) {
        $taxPct = invoice_default_tax_percent();
    }
    $tax = invoice_tax_from_mrp($mrp * $qty, $taxPct);
    $line['qty'] = $qty;
    $line['unit_mrp'] = round($mrp, 2);
    $line['tax_percent'] = $tax['tax_percent'];
    $line['tax_amount'] = $tax['tax_amount'];
    $line['taxable'] = $tax['taxable'];
    $line['customer_value'] = $tax['customer_value'];
    return $line;
}

/**
 * @param list<array<string, mixed>> $lines
 * @return array{customer_value:float,tax_amount:float,taxable:float,total_qty:int}
 */
function invoice_lines_totals(array $lines): array
{
    $cv = 0.0;
    $tax = 0.0;
    $taxable = 0.0;
    $qty = 0;
    foreach ($lines as $ln) {
        $cv += (float) ($ln['customer_value'] ?? 0);
        $tax += (float) ($ln['tax_amount'] ?? 0);
        $taxable += (float) ($ln['taxable'] ?? 0);
        $qty += (int) ($ln['qty'] ?? 0);
    }
    return [
        'customer_value' => round($cv, 2),
        'tax_amount' => round($tax, 2),
        'taxable' => round($taxable, 2),
        'total_qty' => $qty,
    ];
}

/**
 * Kit contents for a package, with MRP / tax / customer value.
 * @return array{ok:bool,lines:list<array>,totals:array,package:?array}
 */
function package_kit_invoice_data(PDO $pdo, int $packageId): array
{
    require_once __DIR__ . '/package_products.php';
    package_products_ensure_table($pdo);
    $pkg = null;
    if ($packageId > 0) {
        $st = $pdo->prepare('SELECT id, name, amount, bv, status FROM packages WHERE id = ? LIMIT 1');
        $st->execute([$packageId]);
        $pkg = $st->fetch() ?: null;
    }
    $raw = $pkg ? package_products_list($pdo, $packageId) : [];
    $lines = [];
    foreach ($raw as $r) {
        $mrp = (float) ($r['mrp'] ?? 0);
        if ($mrp <= 0) {
            $mrp = (float) ($r['unit_price'] ?? $r['current_price'] ?? 0);
        }
        $lines[] = invoice_enrich_line([
            'product_name' => (string) ($r['product_name'] ?? ''),
            'sku' => (string) ($r['sku'] ?? ''),
            'qty' => (int) ($r['qty'] ?? 1),
            'unit_mrp' => $mrp,
            'unit_price' => (float) ($r['unit_price'] ?? $r['current_price'] ?? 0),
            'tax_percent' => (float) ($r['tax_percent'] ?? 0),
        ]);
    }
    return [
        'ok' => $pkg !== null,
        'package' => $pkg,
        'lines' => $lines,
        'totals' => invoice_lines_totals($lines),
    ];
}
