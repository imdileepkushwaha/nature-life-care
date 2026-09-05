<?php
require_once __DIR__ . '/../includes/activation.php';
require_once __DIR__ . '/../includes/tpin.php';
require_once __DIR__ . '/../includes/package_products.php';
require_once __DIR__ . '/includes/auth.php';
require_user();
feature_guard_user_page('activate');

$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

$isUpgrade = !empty($user['package_id']);
$currentPkg = $isUpgrade ? activation_member_package($pdo, $user) : null;
$currentAmount = $currentPkg ? (float) $currentPkg['amount'] : 0.0;
$currentBv = $currentPkg ? (float) $currentPkg['bv'] : 0.0;

$pageTitle = $isUpgrade ? 'Upgrade Plan' : 'Activate Account';
$errors = [];
$packages = $isUpgrade
    ? activation_upgrade_packages($pdo, $currentAmount)
    : activation_packages($pdo);
$pending = activation_pending_request($pdo, (int) $user['id']);
$pendingIsUpgrade = $pending && (($pending['request_type'] ?? 'activation') === 'upgrade');
$myPins = tpin_member_unused($pdo, (int) $user['id']);

$payBanks = [];
// Bank / UTR rails are hidden on this page (T-Pin only).

$supportEmail = setting('support_email', setting('contact_email', ''));
$supportPhone = setting('contact_phone', '');

// This page is T-Pin only — hide wallet / UTR rails (keeps server + form consistent).
$featureModes = feature_activation_pay_modes();
if (!in_array('tpin', $featureModes, true)) {
    flash('error', 'T-Pin activation is not enabled. Please contact support.');
    header('Location: index.php');
    exit;
}
$allowedPayModes = ['tpin'];
$payMode = 'tpin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$pending) {
    $packageId = (int) ($_POST['package_id'] ?? 0);
    $payMode = 'tpin';
    $pinCode = (string) ($_POST['tpin_code'] ?? '');
    // Package can come from selected card; pin must match. If no package selected, pin decides.
    $expectedPkg = $packageId > 0 ? $packageId : null;
    $result = tpin_redeem($pdo, $user, $pinCode, $expectedPkg);
    if ($result['ok']) {
        $pkgName = (string) ($result['package']['name'] ?? 'package');
        flash(
            'success',
            ($result['mode'] === 'upgrade')
                ? "Upgrade complete via T-Pin — you are now on {$pkgName}."
                : "Account activated via T-Pin — package {$pkgName} assigned."
        );
        header('Location: index.php');
        exit;
    }
    $errors[] = $result['error'] ?? 'T-Pin redemption failed.';
}

require_once __DIR__ . '/includes/header.php';

$selected = (int) ($_POST['package_id'] ?? 0);
if ($selected <= 0 && $packages) {
    $selected = (int) ($packages[0]['id'] ?? 0);
}

$selectedPkg = null;
foreach ($packages as $pkg) {
    if ((int) $pkg['id'] === $selected) {
        $selectedPkg = $pkg;
        break;
    }
}
if (!$selectedPkg && $packages) {
    $selectedPkg = $packages[0];
    $selected = (int) $selectedPkg['id'];
}

$selectedPay = $selectedPkg ? (float) $selectedPkg['amount'] : 0.0;
$selectedBvDelta = $selectedPkg ? (float) $selectedPkg['bv'] : 0.0;

$featuredId = $packages ? (int) ($packages[0]['id'] ?? 0) : 0;
$stepPay = !$pending && $packages;
$maxPkgAvailable = $isUpgrade && !$packages && !$pending;

$pkgProductCounts = [];
try {
    $pkgProductCounts = package_products_counts($pdo, array_map(static fn ($p) => (int) $p['id'], $packages));
} catch (Throwable $e) {
    $pkgProductCounts = [];
}
$selectedProductCount = $selectedPkg
    ? (int) (($pkgProductCounts[(int) $selectedPkg['id']]['product_count'] ?? 0))
    : 0;
$selectedProductQty = $selectedPkg
    ? (int) (($pkgProductCounts[(int) $selectedPkg['id']]['total_qty'] ?? 0))
    : 0;

$payLabels = [];
if (in_array('tpin', $allowedPayModes, true)) {
    $payLabels[] = 'T-Pin (instant)';
}
if (in_array('wallet', $allowedPayModes, true)) {
    $payLabels[] = 'Topup Wallet (instant)';
}
if (in_array('utr', $allowedPayModes, true)) {
    $payLabels[] = 'UTR / slip (admin approval)';
}
$payHelp = $payLabels ? implode(' or ', $payLabels) : 'an enabled payment method';
?>
<div class="up-page-head">
    <div>
        <h1><?= $isUpgrade ? 'Upgrade Plan' : 'Activate Account' ?></h1>
        <p><?= $isUpgrade ? 'Upgrade with ' : 'Activate with ' ?><?= e($payHelp) ?>.</p>
    </div>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
        <?php if (in_array('tpin', $allowedPayModes, true)): ?>
        <a href="tpin.php" class="up-btn up-btn-outline">My T-Pins</a>
        <?php endif; ?>
        <a href="index.php" class="up-btn up-btn-outline">Back to Dashboard</a>
    </div>
</div>

<?php foreach ($errors as $err): ?>
    <div class="up-alert up-alert-err"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($pending): ?>
<section class="actx-pending">
    <div class="actx-pending-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
    </div>
    <div class="actx-pending-copy">
        <span class="actx-kicker">Awaiting approval</span>
        <h2><?= $pendingIsUpgrade ? 'Upgrade request pending' : 'Activation request pending' ?></h2>
        <p><?= $pendingIsUpgrade
            ? 'Your full package payment is with the admin team. Your plan will update once it is verified.'
            : 'Your payment proof is with the admin team. You will get Active status once it is verified.' ?></p>
        <div class="actx-pending-meta">
            <?php if ($pendingIsUpgrade && !empty($pending['from_package_name'])): ?>
            <div><small>From</small><strong><?= e($pending['from_package_name']) ?></strong></div>
            <?php endif; ?>
            <div><small><?= $pendingIsUpgrade ? 'Upgrade to' : 'Package' ?></small><strong><?= e($pending['package_name'] ?? '—') ?></strong></div>
            <div><small>Amount</small><strong><?= currency((float) $pending['amount']) ?></strong></div>
            <div><small>Method</small><strong><?= e($pending['payment_method'] ?? '—') ?></strong></div>
            <div><small>UTR / Ref</small><strong><?= e($pending['utr_reference'] ?? '—') ?></strong></div>
            <div><small>Submitted</small><strong><?= !empty($pending['created_at']) ? e(date('d M Y H:i', strtotime((string) $pending['created_at']))) : '—' ?></strong></div>
            <?php
            $slipUrl = activation_slip_url($pending['payment_slip'] ?? null);
            if ($slipUrl):
            ?>
            <div>
                <small>Payment slip</small>
                <strong><a href="<?= e($slipUrl) ?>" target="_blank" rel="noopener noreferrer">View slip</a></strong>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($supportEmail || $supportPhone): ?>
            <p class="actx-pending-help">Need help? <a href="support.php">Contact support</a></p>
        <?php endif; ?>
    </div>
</section>
<?php elseif ($maxPkgAvailable): ?>
<section class="actx-empty">
    <span class="actx-empty-ico" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    </span>
    <strong>You are on the highest plan</strong>
    <p>Your current package is <?= e($currentPkg['name'] ?? ($user['package_name'] ?? 'active')) ?> (<?= currency($currentAmount) ?>). No higher package is available right now.</p>
    <a href="index.php" class="up-btn up-btn-outline">Back to Dashboard</a>
</section>
<?php else: ?>

<section class="actx-hero">
    <div class="actx-hero-copy">
        <span class="actx-kicker"><?= $isUpgrade ? 'Plan upgrade' : 'Membership activation' ?></span>
        <h2><?= $isUpgrade ? 'Upgrade to a higher plan' : 'Choose your growth plan' ?></h2>
        <p>Pay with <?= e($payHelp) ?>.</p>
        <ol class="actx-steps">
            <li class="is-on"><span>1</span> Select package</li>
            <li class="<?= $stepPay ? 'is-on' : '' ?>"><span>2</span> Payment</li>
            <li><span>3</span> <?= $isUpgrade ? 'Upgraded' : 'Activated' ?></li>
        </ol>
    </div>
    <aside class="actx-hero-user">
        <div class="actx-user-top">
            <?= user_avatar_html($user, 'up-avatar actx-avatar', false) ?>
            <div class="actx-user-meta">
                <strong><?= e($user['full_name']) ?></strong>
                <small><?= e($user['member_id']) ?> · @<?= e($user['username']) ?></small>
            </div>
        </div>
        <div class="actx-user-stats">
            <div class="actx-user-stat">
                <span>Status</span>
                <strong class="actx-pill <?= $isUpgrade ? 'is-ok' : 'is-wait' ?>"><?= $isUpgrade ? 'Active' : 'Inactive' ?></strong>
            </div>
            <div class="actx-user-stat">
                <span>Package</span>
                <strong><?= e($currentPkg['name'] ?? ($user['package_name'] ?? 'Not assigned')) ?></strong>
            </div>
            <div class="actx-user-stat">
                <span>T-Pins</span>
                <strong><?= count($myPins) ?> unused</strong>
            </div>
        </div>
    </aside>
</section>

<?php if (!$packages): ?>
    <section class="actx-empty">
        <span class="actx-empty-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
        </span>
        <strong>No packages available</strong>
        <p>Please contact admin to enable <?= $isUpgrade ? 'upgrade' : 'activation' ?> packages.</p>
        <a href="support.php" class="up-btn up-btn-outline">Contact Support</a>
    </section>
<?php else: ?>
<form method="post" class="actx-form" id="actxForm">
    <input type="hidden" name="pay_mode" value="tpin">
    <div class="actx-section-head">
        <div class="actx-section-head-main">
            <span class="actx-section-head-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
            </span>
            <div>
                <span class="actx-section-kicker">Step 1 · Package</span>
                <h3><?= $isUpgrade ? 'Upgrade packages' : 'Available packages' ?></h3>
                <p><?= $isUpgrade
                    ? 'Only higher plans are listed. Pay the full new package amount — T-Pin must match that package.'
                    : 'Select a package, then enter your T-Pin below.' ?></p>
            </div>
        </div>
        <?php if ($packages): ?>
        <span class="actx-section-chip"><?= count($packages) ?> plan<?= count($packages) === 1 ? '' : 's' ?></span>
        <?php endif; ?>
    </div>

    <?php if ($isUpgrade && $currentPkg): ?>
    <aside class="actx-current-plan">
        <div class="actx-current-plan-glow" aria-hidden="true"></div>
        <div class="actx-current-plan-top">
            <span class="actx-current-plan-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>
            </span>
            <div class="actx-current-plan-copy">
                <span class="actx-current-kicker">Your active plan</span>
                <strong><?= e($currentPkg['name']) ?></strong>
                <small>Upgrade uses the full new package amount — T-Pin must match that package.</small>
            </div>
            <a href="kit-invoice.php" class="actx-current-plan-cta">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Kit Invoice
            </a>
        </div>
        <div class="actx-current-plan-stats">
            <div class="actx-current-stat">
                <small>Paid</small>
                <strong><?= currency($currentAmount) ?></strong>
            </div>
            <div class="actx-current-stat">
                <small>PV</small>
                <strong><?= number_format($currentBv, 0) ?></strong>
            </div>
            <div class="actx-current-stat">
                <small>Status</small>
                <strong class="is-ok">Active</strong>
            </div>
        </div>
    </aside>
    <?php endif; ?>

    <div class="actx-grid">
        <?php foreach ($packages as $i => $pkg):
            $pid = (int) $pkg['id'];
            $isOn = $selected === $pid;
            $isFeatured = $featuredId === $pid;
            $tones = ['tone-a', 'tone-b', 'tone-c', 'tone-d'];
            $tone = $tones[$i % count($tones)];
            $payAmt = (float) $pkg['amount'];
            $bvShow = (float) $pkg['bv'];
            $pricePlain = html_entity_decode(strip_tags(currency($payAmt)), ENT_QUOTES, 'UTF-8');
            $fullPlain = $pricePlain;
            $prodMeta = $pkgProductCounts[$pid] ?? ['product_count' => 0, 'total_qty' => 0];
            $prodCount = (int) $prodMeta['product_count'];
            $prodQty = (int) $prodMeta['total_qty'];
            $prodLabel = $prodCount === 1 ? '1 product' : $prodCount . ' products';
            if ($prodQty > $prodCount && $prodCount > 0) {
                $prodLabel .= ' · ' . $prodQty . ' pcs';
            }
            ?>
            <label class="actx-card <?= e($tone) ?><?= $isOn ? ' is-on' : '' ?><?= $isFeatured ? ' is-featured' : '' ?>" data-actx-card
                   data-name="<?= e($pkg['name']) ?>"
                   data-price="<?= e($pricePlain) ?>"
                   data-full="<?= e($fullPlain) ?>"
                   data-bv="<?= e(number_format($bvShow, 0)) ?>"
                   data-products="<?= (int) $prodCount ?>"
                   data-products-label="<?= e($prodCount > 0 ? $prodLabel : 'No products') ?>">
                <input type="radio" name="package_id" value="<?= $pid ?>" <?= $isOn ? 'checked' : '' ?> required>
                <?php if ($isFeatured): ?>
                    <span class="actx-badge"><?= $isUpgrade ? 'Next step' : 'Popular' ?></span>
                <?php endif; ?>
                <span class="actx-check" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><polyline points="20 6 9 17 4 12"/></svg>
                </span>
                <span class="actx-card-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
                </span>
                <span class="actx-name"><?= e($pkg['name']) ?></span>
                <span class="actx-price"><?= currency($payAmt) ?></span>
                <?php if ($isUpgrade): ?>
                    <span class="actx-desc" style="margin-top:-0.35rem">Full package amount · T-Pin must match this package</span>
                <?php endif; ?>
                <span class="actx-stats">
                    <span><small>PV</small><strong><?= number_format($bvShow, 0) ?></strong></span>
                    <span title="<?= e($prodCount > 0 ? $prodLabel : 'No products assigned') ?>">
                        <small>Products</small>
                        <strong><?= $prodCount > 0 ? (int) $prodCount : '—' ?></strong>
                    </span>
                </span>
                <?php if (!$isUpgrade && !empty($pkg['description'])): ?>
                    <span class="actx-desc"><?= e($pkg['description']) ?></span>
                <?php elseif (!$isUpgrade): ?>
                    <span class="actx-desc">Full access to team tools, wallet &amp; withdrawal after activation.</span>
                <?php endif; ?>
                <span class="actx-select-label"><?= $isOn ? 'Selected' : 'Select plan' ?></span>
            </label>
        <?php endforeach; ?>
    </div>

    <section class="actx-pay-panel" style="margin-top:1.5rem">
        <div class="actx-pay-head is-teal">
            <div class="actx-pay-head-main">
                <span class="actx-pay-head-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 9h4M7 13h10"/></svg>
                </span>
                <div>
                    <span class="actx-pay-kicker">Step 2 · T-Pin</span>
                    <h3>Activate with T-Pin</h3>
                    <p>Instant <?= $isUpgrade ? 'upgrade' : 'activation' ?> — no admin wait.</p>
                </div>
            </div>
        </div>
        <div class="actx-proof-body" style="display:block">
            <div id="actxTpinPanel">
                <div class="up-field" style="max-width:420px">
                    <label for="tpin_code">Enter T-Pin *</label>
                    <?php if ($myPins): ?>
                    <select id="tpin_pick" style="margin-bottom:0.55rem">
                        <option value="">Pick from my wallet…</option>
                        <?php foreach ($myPins as $mp): ?>
                            <option value="<?= e(tpin_format_code((string) $mp['pin_code'])) ?>" data-pkg="<?= (int) $mp['package_id'] ?>">
                                <?= e(tpin_format_code((string) $mp['pin_code'])) ?> · <?= e($mp['package_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <input type="text" name="tpin_code" id="tpin_code" maxlength="20"
                           value="<?= e($_POST['tpin_code'] ?? '') ?>"
                           placeholder="XXXX-XXXX-XXXX"
                           autocomplete="off"
                           required>
                    <small style="display:block;margin-top:0.4rem;opacity:.7">Pin package must match the selected plan. Unused company-stock pins also work if you have the code.</small>
                </div>
            </div>
        </div>
    </section>

    <div class="actx-bar">
        <div class="actx-bar-summary">
            <span class="actx-bar-label"><?= $isUpgrade ? 'Upgrade to' : 'Selected plan' ?></span>
            <strong id="actxSumName"><?= e($selectedPkg['name'] ?? '—') ?></strong>
            <div class="actx-bar-meta">
                <span id="actxSumPrice"><?= $selectedPkg ? currency($selectedPay) : '—' ?></span>
                <span>·</span>
                <span><?= $isUpgrade ? 'PV' : 'PV' ?> <em id="actxSumBv"><?= $selectedPkg ? number_format($selectedBvDelta, 0) : '0' ?></em></span>
                <span>·</span>
                <span id="actxSumProducts"><?php
                    if ($selectedProductCount > 0) {
                        echo e($selectedProductCount === 1 ? '1 product' : $selectedProductCount . ' products');
                        if ($selectedProductQty > $selectedProductCount) {
                            echo e(' · ' . $selectedProductQty . ' pcs');
                        }
                    } else {
                        echo 'No products';
                    }
                ?></span>
            </div>
        </div>
        <button type="submit" class="actx-submit" id="actxSubmitBtn">
            <span id="actxSubmitLabel"><?= $isUpgrade ? 'Activate Upgrade with T-Pin' : 'Activate with T-Pin' ?></span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
        </button>
    </div>
</form>
<script>
(function () {
    const cards = document.querySelectorAll('[data-actx-card]');
    const nameEl = document.getElementById('actxSumName');
    const priceEl = document.getElementById('actxSumPrice');
    const bvEl = document.getElementById('actxSumBv');
    const productsEl = document.getElementById('actxSumProducts');
    const tpinInput = document.getElementById('tpin_code');
    const tpinPick = document.getElementById('tpin_pick');

    function sync(card) {
        cards.forEach((c) => {
            const on = c === card;
            c.classList.toggle('is-on', on);
            const lbl = c.querySelector('.actx-select-label');
            if (lbl) lbl.textContent = on ? 'Selected' : 'Select plan';
        });
        if (nameEl) nameEl.textContent = card.getAttribute('data-name') || '—';
        if (priceEl) priceEl.textContent = card.getAttribute('data-price') || '—';
        if (bvEl) bvEl.textContent = card.getAttribute('data-bv') || '0';
        if (productsEl) productsEl.textContent = card.getAttribute('data-products-label') || 'No products';
    }

    cards.forEach((card) => {
        const input = card.querySelector('input');
        if (!input) return;
        input.addEventListener('change', () => sync(card));
        card.addEventListener('click', () => {
            if (!input.checked) {
                input.checked = true;
                sync(card);
            }
        });
    });

    if (tpinPick && tpinInput) {
        tpinPick.addEventListener('change', () => {
            if (tpinPick.value) tpinInput.value = tpinPick.value;
            const opt = tpinPick.options[tpinPick.selectedIndex];
            const pkgId = opt ? opt.getAttribute('data-pkg') : '';
            if (pkgId) {
                const radio = document.querySelector('input[name="package_id"][value="' + pkgId + '"]');
                if (radio) {
                    radio.checked = true;
                    const card = radio.closest('[data-actx-card]');
                    if (card) sync(card);
                }
            }
        });
    }
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
