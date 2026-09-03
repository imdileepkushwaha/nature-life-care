<?php
$pageTitle = 'Kit Invoice';
require_once __DIR__ . '/../includes/invoice.php';
require_once __DIR__ . '/includes/header.php';

$packageId = (int) ($user['package_id'] ?? 0);
$kit = package_kit_invoice_data($pdo, $packageId);
$pkg = $kit['package'];
$invoiceLines = $kit['lines'];
$kitTotals = $kit['totals'];
$invoiceShowPaid = false;

$company = setting('company_name', 'Binary MLM');
$support = setting('support_email', setting('contact_email', ''));
$supportPhone = setting('contact_phone', '');
$gstin = invoice_gstin();
$logoUrl = company_logo_url();
$signatureUrl = company_signature_url();
$joinDate = !empty($user['join_date']) ? date('d M Y', strtotime((string) $user['join_date'])) : date('d M Y');
$kitNo = 'KIT/' . ($user['member_id'] ?? '') . '/' . ($pkg['id'] ?? '0');
?>
<div class="doc-page shop-invoice-page">
    <div class="doc-toolbar no-print">
        <div>
            <h1 class="doc-title">Kit Invoice</h1>
            <p class="doc-sub">Product list, quantity, MRP, taxes and customer value for your package</p>
        </div>
        <div class="doc-toolbar-actions">
            <?php if ($pkg && $invoiceLines): ?>
                <button type="button" class="up-btn up-btn-primary" onclick="window.print()">Print / Save PDF</button>
            <?php endif; ?>
            <a href="welcome-letter.php" class="up-btn up-btn-outline">Welcome Letter</a>
            <?php if (feature_module_allowed('products')): ?>
                <a href="purchase-invoice.php" class="up-btn up-btn-outline">Purchase Invoice</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$pkg): ?>
        <section class="shop-card no-print">
            <div class="shop-empty">
                <strong>No kit assigned</strong>
                <p>Activate a package to see its product list, MRP, taxes and customer value on this invoice.</p>
                <?php if (feature_module_allowed('activations')): ?>
                    <p><a href="activate.php">Activate now</a></p>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <article class="shop-invoice" id="shopInvoicePrint">
            <div class="shop-inv-accent" aria-hidden="true"></div>
            <header class="shop-inv-top">
                <div class="shop-inv-brand-block">
                    <?php if ($logoUrl): ?>
                        <img src="<?= e($logoUrl) ?>" alt="<?= e($company) ?>" class="shop-inv-logo">
                    <?php else: ?>
                        <div class="shop-inv-logo-fallback"><?= e(strtoupper(substr($company, 0, 1))) ?></div>
                    <?php endif; ?>
                    <div>
                        <strong class="shop-inv-brand"><?= e($company) ?></strong>
                        <p class="shop-inv-doc-type">KIT / TAX INVOICE</p>
                        <?php if ($gstin !== ''): ?>
                            <p class="shop-inv-contact">GSTIN <?= e($gstin) ?></p>
                        <?php endif; ?>
                        <?php if ($support !== '' || $supportPhone !== ''): ?>
                            <p class="shop-inv-contact">
                                <?= $support !== '' ? e($support) : '' ?>
                                <?= ($support !== '' && $supportPhone !== '') ? ' · ' : '' ?>
                                <?= $supportPhone !== '' ? e($supportPhone) : '' ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="shop-inv-right">
                    <div class="shop-inv-no">
                        <span>Kit invoice</span>
                        <strong><?= e($kitNo) ?></strong>
                    </div>
                    <div class="shop-inv-badges">
                        <span class="shop-inv-badge is-paid">Active</span>
                    </div>
                </div>
            </header>

            <div class="shop-inv-info-strip">
                <div>
                    <span>Kit / package</span>
                    <strong><?= e((string) $pkg['name']) ?></strong>
                </div>
                <div>
                    <span>Activation date</span>
                    <strong><?= e($joinDate) ?></strong>
                </div>
                <div>
                    <span>Quantity</span>
                    <strong><?= (int) $kitTotals['total_qty'] ?> pcs</strong>
                </div>
                <div>
                    <span>Customer value</span>
                    <strong><?= currency($kitTotals['customer_value']) ?></strong>
                </div>
            </div>

            <div class="shop-inv-parties">
                <div class="shop-inv-party">
                    <span class="shop-inv-label">Bill To</span>
                    <strong><?= e($user['full_name']) ?></strong>
                    <p class="shop-inv-id"><?= e($user['member_id']) ?><?= !empty($user['username']) ? ' · @' . e($user['username']) : '' ?></p>
                    <?php if (!empty($user['email'])): ?><p><?= e($user['email']) ?></p><?php endif; ?>
                    <?php if (!empty($user['phone'])): ?><p><?= e($user['phone']) ?></p><?php endif; ?>
                </div>
                <div class="shop-inv-party">
                    <span class="shop-inv-label">Kit value</span>
                    <strong><?= e((string) $pkg['name']) ?></strong>
                    <p>Package price <?= currency((float) $pkg['amount']) ?></p>
                    <p>Customer value (MRP) <?= currency($kitTotals['customer_value']) ?></p>
                    <p>Taxes (GST in MRP) <?= currency($kitTotals['tax_amount']) ?></p>
                </div>
            </div>

            <?php require __DIR__ . '/includes/invoice-product-table.php'; ?>

            <div class="shop-inv-totals">
                <div class="shop-inv-notes">
                    <span class="shop-inv-label">Clear kit disclosure</span>
                    <p>Har package ki product list, quantity, MRP, taxes aur customer value is invoice par spasht hai. GST MRP me included maana gaya hai.</p>
                </div>
                <div class="shop-inv-sum">
                    <div><span>Total quantity</span><strong><?= (int) $kitTotals['total_qty'] ?> pcs</strong></div>
                    <div><span>Customer value (MRP)</span><strong><?= currency($kitTotals['customer_value']) ?></strong></div>
                    <div><span>Taxable value</span><strong><?= currency($kitTotals['taxable']) ?></strong></div>
                    <div><span>Taxes (GST)</span><strong><?= currency($kitTotals['tax_amount']) ?></strong></div>
                    <div class="is-grand"><span>Kit price</span><strong><?= currency((float) $pkg['amount']) ?></strong></div>
                </div>
            </div>

            <footer class="shop-inv-foot">
                <div>
                    <strong>Computer-generated kit invoice</strong>
                    <p>This document lists products assigned to your active package.</p>
                </div>
                <div class="shop-inv-stamp">
                    <?php if ($signatureUrl): ?>
                        <img class="shop-inv-sign" src="<?= e($signatureUrl) ?>" alt="Authorized signature">
                    <?php endif; ?>
                    <span>Authorized Signatory</span>
                    <strong><?= e($company) ?></strong>
                </div>
            </footer>
        </article>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
