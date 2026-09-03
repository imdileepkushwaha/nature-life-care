<?php
require_once __DIR__ . '/../includes/activation.php';
require_once __DIR__ . '/../includes/tpin.php';
require_once __DIR__ . '/../includes/package_products.php';
require_once __DIR__ . '/../includes/wallet_topup.php';
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
try {
    require_once __DIR__ . '/../includes/utility.php';
    bank_accounts_ensure_columns($pdo);
    $payBanks = $pdo->query("
        SELECT a.account_name, a.account_number, a.ifsc_code, a.branch_name, a.account_type,
               a.upi_id, a.qr_code, b.name AS bank_name
        FROM bank_accounts a
        JOIN banks b ON b.id = a.bank_id
        WHERE a.status = 'active'
        ORDER BY a.id ASC
        LIMIT 5
    ")->fetchAll();
} catch (Throwable $e) {
    $payBanks = [];
}

$supportEmail = setting('support_email', setting('contact_email', ''));
$supportPhone = setting('contact_phone', '');

$allowedPayModes = feature_activation_pay_modes();
if ($allowedPayModes === []) {
    flash('error', 'No activation method is enabled. Please contact support.');
    header('Location: index.php');
    exit;
}

$payMode = (string) ($_POST['pay_mode'] ?? feature_activation_default_pay_mode());
if (!in_array($payMode, $allowedPayModes, true)) {
    $payMode = feature_activation_default_pay_mode();
}

$topupBal = wallet_balance($pdo, (int) $user['id'], 'topup');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$pending) {
    $packageId = (int) ($_POST['package_id'] ?? 0);
    if (!in_array($payMode, $allowedPayModes, true)) {
        $errors[] = 'Selected payment mode is not available.';
    } elseif ($payMode === 'tpin') {
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
    } elseif ($payMode === 'wallet') {
        if ($packageId <= 0) {
            $errors[] = $isUpgrade ? 'Please select a package to upgrade.' : 'Please select a package to activate.';
        } else {
            $result = wallet_topup_pay_and_activate($pdo, $user, $user, $packageId);
            if ($result['ok']) {
                $pkgName = (string) ($result['package']['name'] ?? 'package');
                flash(
                    'success',
                    ($result['mode'] === 'upgrade')
                        ? "Upgrade complete via Topup Wallet — you are now on {$pkgName}."
                        : "Account activated via Topup Wallet — package {$pkgName} assigned."
                );
                header('Location: index.php');
                exit;
            }
            $errors[] = $result['error'] ?? 'Topup Wallet payment failed.';
        }
    } else {
        $method = trim((string) ($_POST['payment_method'] ?? 'Bank Transfer'));
        $utr = trim((string) ($_POST['utr_reference'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($packageId <= 0) {
            $errors[] = $isUpgrade ? 'Please select a package to upgrade.' : 'Please select a package to activate.';
        } elseif ($isUpgrade && !$packages) {
            $errors[] = 'No higher package is available to upgrade.';
        } else {
            $isCash = ($method === 'Cash');
            $slipPath = null;

            if (!$isCash) {
                $slipUp = activation_store_slip($_FILES['payment_slip'] ?? [], (int) $user['id']);
                if (!$slipUp['ok']) {
                    $errors[] = $slipUp['error'] ?? 'Payment slip upload failed.';
                } else {
                    $slipPath = $slipUp['path'];
                }
            }

            if (!$errors) {
                $result = $isUpgrade
                    ? activation_submit_upgrade_request($pdo, $user, $packageId, $method, $utr, $note, $slipPath)
                    : activation_submit_request($pdo, $user, $packageId, $method, $utr, $note, $slipPath);
                if ($result['ok']) {
                    flash(
                        'success',
                        $isUpgrade
                            ? 'Upgrade request submitted. Pay only the difference — admin will verify and upgrade your plan.'
                            : 'Activation request submitted. Admin will verify your payment and activate your account.'
                    );
                    header('Location: activate.php');
                    exit;
                }
                if ($slipPath) {
                    $orphan = BASE_PATH . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $slipPath);
                    if (is_file($orphan)) {
                        @unlink($orphan);
                    }
                }
                $errors[] = $result['error'] ?? 'Could not submit request.';
            }
        }
    }
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

$selectedPay = $selectedPkg
    ? ($isUpgrade ? activation_diff_amount($currentPkg, $selectedPkg) : (float) $selectedPkg['amount'])
    : 0.0;
$selectedBvDelta = $selectedPkg
    ? ($isUpgrade ? activation_diff_bv($currentPkg, $selectedPkg) : (float) $selectedPkg['bv'])
    : 0.0;

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
            ? 'Your difference payment is with the admin team. Your plan will update once it is verified.'
            : 'Your payment proof is with the admin team. You will get Active status once it is verified.' ?></p>
        <div class="actx-pending-meta">
            <?php if ($pendingIsUpgrade && !empty($pending['from_package_name'])): ?>
            <div><small>From</small><strong><?= e($pending['from_package_name']) ?></strong></div>
            <?php endif; ?>
            <div><small><?= $pendingIsUpgrade ? 'Upgrade to' : 'Package' ?></small><strong><?= e($pending['package_name'] ?? '—') ?></strong></div>
            <div><small><?= $pendingIsUpgrade ? 'Difference payable' : 'Amount' ?></small><strong><?= currency((float) $pending['amount']) ?></strong></div>
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
<form method="post" class="actx-form" id="actxForm" enctype="multipart/form-data">
    <div class="actx-section-head">
        <h3><?= $isUpgrade ? 'Upgrade packages' : 'Available packages' ?></h3>
        <p><?= $isUpgrade
            ? 'Only higher plans are listed. T-Pin must match the selected package.'
            : 'Select a package, then choose payment below.' ?></p>
    </div>

    <?php if ($isUpgrade && $currentPkg): ?>
    <div class="actx-current-plan" style="margin:0 0 1.25rem;padding:0.9rem 1.1rem;border:1px solid var(--up-border, #e5e7eb);border-radius:12px;background:rgba(15,118,110,.04);display:flex;flex-wrap:wrap;gap:0.75rem 1.5rem;align-items:center">
        <div><small style="display:block;opacity:.7">Current plan</small><strong><?= e($currentPkg['name']) ?></strong></div>
        <div><small style="display:block;opacity:.7">Paid</small><strong><?= currency($currentAmount) ?></strong></div>
        <div><small style="display:block;opacity:.7">BV</small><strong><?= number_format($currentBv, 0) ?></strong></div>
    </div>
    <?php endif; ?>

    <div class="actx-grid">
        <?php foreach ($packages as $i => $pkg):
            $pid = (int) $pkg['id'];
            $isOn = $selected === $pid;
            $isFeatured = $featuredId === $pid;
            $tones = ['tone-a', 'tone-b', 'tone-c', 'tone-d'];
            $tone = $tones[$i % count($tones)];
            $payAmt = $isUpgrade ? activation_diff_amount($currentPkg, $pkg) : (float) $pkg['amount'];
            $bvShow = $isUpgrade ? activation_diff_bv($currentPkg, $pkg) : (float) $pkg['bv'];
            $pricePlain = html_entity_decode(strip_tags(currency($payAmt)), ENT_QUOTES, 'UTF-8');
            $fullPlain = html_entity_decode(strip_tags(currency((float) $pkg['amount'])), ENT_QUOTES, 'UTF-8');
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
                    <span class="actx-desc" style="margin-top:-0.35rem">Full price <?= currency((float) $pkg['amount']) ?> · UTR pays difference · T-Pin = full package pin</span>
                <?php endif; ?>
                <span class="actx-stats">
                    <span><small><?= $isUpgrade ? '+BV' : 'BV' ?></small><strong><?= number_format($bvShow, 0) ?></strong></span>
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
                    <span class="actx-pay-kicker">Step 2 · Payment mode</span>
                    <h3>How do you want to <?= $isUpgrade ? 'upgrade' : 'activate' ?>?</h3>
                    <p><?= e($payHelp) ?>.</p>
                </div>
            </div>
        </div>
        <div class="actx-proof-body" style="display:block">
            <div class="actx-mode-tabs" style="display:flex;gap:0.6rem;flex-wrap:wrap;margin-bottom:1.1rem">
                <?php if (in_array('tpin', $allowedPayModes, true)): ?>
                <label class="actx-mode-tab" style="flex:1;min-width:160px;border:1.5px solid var(--up-border,#e5e7eb);border-radius:12px;padding:0.85rem 1rem;cursor:pointer;display:grid;gap:0.2rem">
                    <span style="display:flex;align-items:center;gap:0.5rem">
                        <input type="radio" name="pay_mode" value="tpin" <?= $payMode === 'tpin' ? 'checked' : '' ?> data-actx-mode>
                        <strong>T-Pin</strong>
                    </span>
                    <small style="opacity:.7">Instant <?= $isUpgrade ? 'upgrade' : 'activation' ?> · no admin wait</small>
                </label>
                <?php endif; ?>
                <?php if (in_array('wallet', $allowedPayModes, true)): ?>
                <label class="actx-mode-tab" style="flex:1;min-width:160px;border:1.5px solid var(--up-border,#e5e7eb);border-radius:12px;padding:0.85rem 1rem;cursor:pointer;display:grid;gap:0.2rem">
                    <span style="display:flex;align-items:center;gap:0.5rem">
                        <input type="radio" name="pay_mode" value="wallet" <?= $payMode === 'wallet' ? 'checked' : '' ?> data-actx-mode>
                        <strong>Topup Wallet</strong>
                    </span>
                    <small style="opacity:.7">Balance <?= strip_tags(currency($topupBal)) ?> · instant</small>
                </label>
                <?php endif; ?>
                <?php if (in_array('utr', $allowedPayModes, true)): ?>
                <label class="actx-mode-tab" style="flex:1;min-width:160px;border:1.5px solid var(--up-border,#e5e7eb);border-radius:12px;padding:0.85rem 1rem;cursor:pointer;display:grid;gap:0.2rem">
                    <span style="display:flex;align-items:center;gap:0.5rem">
                        <input type="radio" name="pay_mode" value="utr" <?= $payMode === 'utr' ? 'checked' : '' ?> data-actx-mode>
                        <strong>UTR / Slip</strong>
                    </span>
                    <small style="opacity:.7">Bank / UPI transfer · admin approval</small>
                </label>
                <?php endif; ?>
            </div>

            <?php if (in_array('wallet', $allowedPayModes, true)): ?>
            <div id="actxWalletPanel" <?= $payMode === 'wallet' ? '' : 'hidden' ?>>
                <div class="up-alert up-alert-info" style="margin-bottom:0.85rem">
                    Payable amount will be deducted from your <strong>Topup Wallet</strong> instantly.
                    Current balance: <strong><?= currency($topupBal) ?></strong>
                    <?php if ($topupBal + 0.00001 < $selectedPay): ?>
                        — <a href="wallet-topup.php">Add Money</a> first (need <?= currency($selectedPay) ?>).
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (in_array('tpin', $allowedPayModes, true)): ?>
            <div id="actxTpinPanel" <?= $payMode === 'tpin' ? '' : 'hidden' ?>>
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
                           <?= $payMode === 'tpin' ? 'required' : '' ?>>
                    <small style="display:block;margin-top:0.4rem;opacity:.7">Pin package must match the selected plan. Unused company-stock pins also work if you have the code.</small>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if (in_array('utr', $allowedPayModes, true)): ?>
    <div id="actxUtrPanel" <?= $payMode === 'utr' ? '' : 'hidden' ?>>
    <?php if ($payBanks): ?>
    <section class="actx-pay-panel actx-bank-panel" id="actxBankPanel">
        <div class="actx-pay-head is-teal">
            <div class="actx-pay-head-main">
                <span class="actx-pay-head-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                </span>
                <div>
                    <span class="actx-pay-kicker">Step 2 · Pay company</span>
                    <h3>Company bank details</h3>
                    <p><?= $isUpgrade
                        ? 'Transfer only the difference amount via bank / UPI, then submit proof below.'
                        : 'Transfer the selected package amount via bank / UPI, then submit proof below.' ?></p>
                </div>
            </div>
            <span class="actx-pay-chip"><?= count($payBanks) ?> account<?= count($payBanks) === 1 ? '' : 's' ?></span>
        </div>
        <div class="actx-bank-grid">
            <?php foreach ($payBanks as $ba):
                $qrUrl = bank_qr_url($ba['qr_code'] ?? null);
                $accNo = (string) ($ba['account_number'] ?? '');
                $ifsc = (string) ($ba['ifsc_code'] ?? '');
                $upi = (string) ($ba['upi_id'] ?? '');
            ?>
            <article class="actx-bank-card<?= $qrUrl ? ' has-qr' : '' ?>">
                <div class="actx-bank-top">
                    <div class="actx-bank-brand">
                        <span class="actx-bank-mark" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 10l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>
                        </span>
                        <div>
                            <strong><?= e($ba['bank_name']) ?></strong>
                            <small><?= e($ba['account_type'] ?? 'Account') ?></small>
                        </div>
                    </div>
                    <?php if ($upi !== ''): ?>
                    <span class="actx-bank-tag">UPI ready</span>
                    <?php endif; ?>
                </div>

                <div class="actx-bank-body">
                    <div class="actx-bank-rows">
                        <div class="actx-bank-row">
                            <small>A/c holder</small>
                            <strong><?= e($ba['account_name']) ?></strong>
                        </div>
                        <div class="actx-bank-row">
                            <small>A/c number</small>
                            <div class="actx-bank-val">
                                <strong><?= e($accNo) ?></strong>
                                <button type="button" class="actx-copy-btn" data-copy-text="<?= e($accNo) ?>" data-label="Copy account number" title="Copy account number" aria-label="Copy account number">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="actx-bank-row">
                            <small>IFSC</small>
                            <div class="actx-bank-val">
                                <strong><?= e($ifsc) ?></strong>
                                <button type="button" class="actx-copy-btn" data-copy-text="<?= e($ifsc) ?>" data-label="Copy IFSC" title="Copy IFSC" aria-label="Copy IFSC">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                </button>
                            </div>
                        </div>
                        <?php if ($upi !== ''): ?>
                        <div class="actx-bank-row is-upi">
                            <small>UPI ID</small>
                            <div class="actx-bank-val">
                                <strong><?= e($upi) ?></strong>
                                <button type="button" class="actx-copy-btn" data-copy-text="<?= e($upi) ?>" data-label="Copy UPI" title="Copy UPI" aria-label="Copy UPI">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($ba['branch_name'])): ?>
                        <div class="actx-bank-row">
                            <small>Branch</small>
                            <strong><?= e($ba['branch_name']) ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($qrUrl): ?>
                    <div class="actx-bank-qr">
                        <img src="<?= e($qrUrl) ?>" alt="Payment QR for <?= e($ba['bank_name']) ?>">
                        <span>Scan &amp; pay</span>
                    </div>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="actx-pay-panel actx-proof-panel">
        <div class="actx-pay-head is-indigo">
            <div class="actx-pay-head-main">
                <span class="actx-pay-head-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/></svg>
                </span>
                <div>
                    <span class="actx-pay-kicker">Step 3 · Verify</span>
                    <h3>Payment proof</h3>
                    <!-- <p id="actxPayHint">Submit UTR and upload your payment slip after transfer.</p> -->
                </div>
            </div>
        </div>
        <div class="actx-proof-body">
            <div class="actx-pay-fields">
                <div class="up-field">
                    <label for="payment_method">Payment method</label>
                    <select name="payment_method" id="payment_method" required>
                        <?php
                        $methods = ['Bank Transfer', 'UPI', 'Cash', 'Other'];
                        $curMethod = (string) ($_POST['payment_method'] ?? 'Bank Transfer');
                        foreach ($methods as $m):
                        ?>
                        <option value="<?= e($m) ?>" <?= $curMethod === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="up-field" id="actxUtrWrap" data-actx-proof>
                    <label for="utr_reference">UTR / Transaction ID *</label>
                    <input type="text" name="utr_reference" id="utr_reference" maxlength="100"
                           value="<?= e($_POST['utr_reference'] ?? '') ?>"
                           placeholder="e.g. 312345678901" <?= $curMethod !== 'Cash' ? 'required' : '' ?>>
                </div>
                <div class="up-field actx-pay-slip" id="actxSlipWrap" data-actx-proof>
                    <label>Payment slip *</label>
                    <div class="actx-upbox" id="actxSlipBox">
                        <input type="file" name="payment_slip" id="payment_slip" class="actx-upbox-input"
                               accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                               <?= $curMethod !== 'Cash' ? 'required' : '' ?>>

                        <div class="actx-upbox-thumb" id="actxSlipThumb">
                            <div class="actx-upbox-empty" id="actxSlipEmpty">
                                <span class="actx-upbox-ico" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                                </span>
                            </div>
                            <div class="actx-upbox-preview" id="actxSlipPreview" hidden>
                                <img src="" alt="Slip preview" id="actxSlipImg" hidden>
                                <div class="actx-upbox-pdf" id="actxSlipPdf" hidden>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    <span>PDF</span>
                                </div>
                            </div>
                        </div>

                        <div class="actx-upbox-body">
                            <div class="actx-upbox-copy" id="actxSlipIdleCopy">
                                <strong>Drop slip here or browse</strong>
                                <small>JPG, PNG, WebP or PDF · max 3MB</small>
                            </div>
                            <div class="actx-upbox-meta" id="actxSlipMeta" hidden>
                                <strong id="actxSlipLabel">—</strong>
                                <small id="actxSlipSize"></small>
                            </div>
                            <div class="actx-upbox-actions">
                                <button type="button" class="actx-upbox-cta" id="actxSlipCta">Choose file</button>
                                <button type="button" class="actx-upbox-btn" id="actxSlipBrowse" hidden>Change</button>
                                <button type="button" class="actx-upbox-btn is-clear" id="actxSlipClear" hidden>Remove</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="up-field actx-pay-note">
                    <label for="note">Note (optional)</label>
                    <input type="text" name="note" id="note" maxlength="255"
                           value="<?= e($_POST['note'] ?? '') ?>"
                           placeholder="Bank name / UPI ID used">
                </div>
            </div>
            <aside class="actx-proof-tips" id="actxProofTips">
                <strong>Quick checklist</strong>
                <ul>
                    <li><?= $isUpgrade ? 'Pay only the difference' : 'Pay exact package amount' ?></li>
                    <li>Keep UTR / ref ready</li>
                    <li>Upload clear slip photo</li>
                    <li>Admin <?= $isUpgrade ? 'upgrades' : 'activates' ?> after verify</li>
                </ul>
            </aside>
        </div>
    </section>
    </div>
    <?php endif; ?>

    <div class="actx-bar">
        <div class="actx-bar-summary">
            <span class="actx-bar-label"><?= $isUpgrade ? 'Upgrade to' : 'Selected plan' ?></span>
            <strong id="actxSumName"><?= e($selectedPkg['name'] ?? '—') ?></strong>
            <div class="actx-bar-meta">
                <span id="actxSumPrice"><?= $selectedPkg ? currency($selectedPay) : '—' ?></span>
                <?php if ($isUpgrade): ?><span class="actx-bar-pay-note">difference (UTR)</span><?php endif; ?>
                <span>·</span>
                <span><?= $isUpgrade ? '+BV' : 'BV' ?> <em id="actxSumBv"><?= $selectedPkg ? number_format($selectedBvDelta, 0) : '0' ?></em></span>
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
            <span id="actxSubmitLabel"><?= $payMode === 'tpin'
                ? ($isUpgrade ? 'Activate Upgrade with T-Pin' : 'Activate with T-Pin')
                : ($isUpgrade ? 'Submit Upgrade' : 'Submit for Approval') ?></span>
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
        input.addEventListener('change', () => sync(card));
        card.addEventListener('click', () => {
            if (!input.checked) {
                input.checked = true;
                sync(card);
            }
        });
    });

    const slipInput = document.getElementById('payment_slip');
    const slipBox = document.getElementById('actxSlipBox');
    const slipLabel = document.getElementById('actxSlipLabel');
    const slipSize = document.getElementById('actxSlipSize');
    const slipPreview = document.getElementById('actxSlipPreview');
    const slipImg = document.getElementById('actxSlipImg');
    const slipPdf = document.getElementById('actxSlipPdf');
    const slipEmpty = document.getElementById('actxSlipEmpty');
    const slipMeta = document.getElementById('actxSlipMeta');
    const slipCta = document.getElementById('actxSlipCta');
    const slipIdleCopy = document.getElementById('actxSlipIdleCopy');
    const slipBrowse = document.getElementById('actxSlipBrowse');
    const slipClear = document.getElementById('actxSlipClear');
    let slipObjectUrl = null;

    function formatBytes(n) {
        if (!n && n !== 0) return '';
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(2) + ' MB';
    }

    function clearSlipPreview() {
        if (slipObjectUrl) {
            URL.revokeObjectURL(slipObjectUrl);
            slipObjectUrl = null;
        }
        if (slipImg) {
            slipImg.src = '';
            slipImg.hidden = true;
        }
        if (slipPdf) slipPdf.hidden = true;
        if (slipPreview) slipPreview.hidden = true;
        if (slipEmpty) slipEmpty.hidden = false;
        if (slipIdleCopy) slipIdleCopy.hidden = false;
        if (slipMeta) slipMeta.hidden = true;
        if (slipCta) slipCta.hidden = false;
        if (slipBrowse) slipBrowse.hidden = true;
        if (slipClear) slipClear.hidden = true;
        if (slipBox) slipBox.classList.remove('has-file', 'is-drag');
        if (slipLabel) slipLabel.textContent = '—';
        if (slipSize) slipSize.textContent = '';
    }

    function renderSlipFile(file) {
        if (!file) {
            clearSlipPreview();
            return;
        }
        if (slipObjectUrl) {
            URL.revokeObjectURL(slipObjectUrl);
            slipObjectUrl = null;
        }
        const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
        if (slipEmpty) slipEmpty.hidden = true;
        if (slipIdleCopy) slipIdleCopy.hidden = true;
        if (slipMeta) slipMeta.hidden = false;
        if (slipCta) slipCta.hidden = true;
        if (slipBrowse) slipBrowse.hidden = false;
        if (slipClear) slipClear.hidden = false;
        if (slipBox) slipBox.classList.add('has-file');
        if (slipLabel) slipLabel.textContent = file.name;
        if (slipSize) slipSize.textContent = formatBytes(file.size);
        if (slipPreview) slipPreview.hidden = false;
        if (isPdf) {
            if (slipImg) {
                slipImg.hidden = true;
                slipImg.src = '';
            }
            if (slipPdf) slipPdf.hidden = false;
        } else {
            slipObjectUrl = URL.createObjectURL(file);
            if (slipPdf) slipPdf.hidden = true;
            if (slipImg) {
                slipImg.hidden = false;
                slipImg.src = slipObjectUrl;
            }
        }
    }

    function openSlipPicker() {
        if (slipInput && !slipInput.disabled) slipInput.click();
    }

    if (slipInput) {
        slipInput.addEventListener('change', () => {
            renderSlipFile(slipInput.files && slipInput.files[0] ? slipInput.files[0] : null);
        });
    }
    if (slipCta) slipCta.addEventListener('click', (e) => { e.preventDefault(); openSlipPicker(); });
    if (slipBrowse) slipBrowse.addEventListener('click', (e) => { e.preventDefault(); openSlipPicker(); });
    if (slipClear) {
        slipClear.addEventListener('click', (e) => {
            e.preventDefault();
            if (slipInput) slipInput.value = '';
            clearSlipPreview();
        });
    }
    if (slipBox) {
        ['dragenter', 'dragover'].forEach((ev) => {
            slipBox.addEventListener(ev, (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (!slipInput || slipInput.disabled) return;
                slipBox.classList.add('is-drag');
            });
        });
        ['dragleave', 'drop'].forEach((ev) => {
            slipBox.addEventListener(ev, (e) => {
                e.preventDefault();
                e.stopPropagation();
                slipBox.classList.remove('is-drag');
            });
        });
        slipBox.addEventListener('drop', (e) => {
            if (!slipInput || slipInput.disabled) return;
            const files = e.dataTransfer && e.dataTransfer.files;
            if (!files || !files.length) return;
            const dt = new DataTransfer();
            dt.items.add(files[0]);
            slipInput.files = dt.files;
            renderSlipFile(files[0]);
        });
    }

    const methodSel = document.getElementById('payment_method');
    const utrInput = document.getElementById('utr_reference');
    const utrWrap = document.getElementById('actxUtrWrap');
    const slipWrap = document.getElementById('actxSlipWrap');
    const payHint = document.getElementById('actxPayHint');
    const noteInput = document.getElementById('note');
    const payBox = document.getElementById('actxBankPanel') || document.querySelector('.actx-bank-panel');
    const proofTips = document.getElementById('actxProofTips');

    function syncPaymentProof() {
        const isCash = !!(methodSel && methodSel.value === 'Cash');
        [utrWrap, slipWrap].forEach((el) => {
            if (!el) return;
            el.hidden = isCash;
            el.classList.toggle('is-cash-hide', isCash);
            el.style.display = isCash ? 'none' : '';
        });
        if (payBox) {
            payBox.hidden = isCash;
            payBox.style.display = isCash ? 'none' : '';
        }
        if (proofTips) {
            proofTips.hidden = isCash;
            proofTips.style.display = isCash ? 'none' : '';
        }
        if (utrInput) {
            if (isCash) {
                utrInput.removeAttribute('required');
                utrInput.value = '';
                utrInput.disabled = true;
            } else {
                utrInput.disabled = false;
                utrInput.setAttribute('required', 'required');
            }
        }
        if (slipInput) {
            if (isCash) {
                slipInput.removeAttribute('required');
                slipInput.value = '';
                slipInput.disabled = true;
                clearSlipPreview();
            } else {
                slipInput.disabled = false;
                slipInput.setAttribute('required', 'required');
            }
        }
        if (payHint) {
            payHint.textContent = isCash
                ? 'Cash payment — admin will verify offline. UTR and slip are not required.'
                : 'Submit UTR and upload your payment slip after transfer.';
        }
        if (noteInput) {
            noteInput.placeholder = isCash ? 'Collector name / receipt no. (optional)' : 'Bank name / UPI ID used';
        }
    }

    if (methodSel) {
        methodSel.addEventListener('change', syncPaymentProof);
        methodSel.addEventListener('input', syncPaymentProof);
        syncPaymentProof();
    }

    const modeInputs = document.querySelectorAll('[data-actx-mode]');
    const tpinPanel = document.getElementById('actxTpinPanel');
    const walletPanel = document.getElementById('actxWalletPanel');
    const utrPanel = document.getElementById('actxUtrPanel');
    const tpinInput = document.getElementById('tpin_code');
    const tpinPick = document.getElementById('tpin_pick');
    const submitLabel = document.getElementById('actxSubmitLabel');
    const isUpgradeUi = <?= $isUpgrade ? 'true' : 'false' ?>;

    function currentMode() {
        const checked = document.querySelector('[data-actx-mode]:checked');
        return checked ? checked.value : 'utr';
    }

    function syncPayMode() {
        const mode = currentMode();
        const isTpin = mode === 'tpin';
        const isWallet = mode === 'wallet';
        const isUtr = mode === 'utr';
        if (tpinPanel) tpinPanel.hidden = !isTpin;
        if (walletPanel) walletPanel.hidden = !isWallet;
        if (utrPanel) utrPanel.hidden = !isUtr;
        if (tpinInput) {
            if (isTpin) {
                tpinInput.setAttribute('required', 'required');
                tpinInput.disabled = false;
            } else {
                tpinInput.removeAttribute('required');
                tpinInput.disabled = true;
            }
        }
        if (methodSel) methodSel.disabled = !isUtr;
        if (utrInput) {
            if (!isUtr) {
                utrInput.removeAttribute('required');
                utrInput.disabled = true;
            } else {
                utrInput.disabled = false;
            }
        }
        if (slipInput) {
            if (!isUtr) {
                slipInput.removeAttribute('required');
                slipInput.disabled = true;
            } else {
                slipInput.disabled = false;
            }
        }
        if (submitLabel) {
            if (isTpin) {
                submitLabel.textContent = isUpgradeUi ? 'Activate Upgrade with T-Pin' : 'Activate with T-Pin';
            } else if (isWallet) {
                submitLabel.textContent = isUpgradeUi ? 'Upgrade with Topup Wallet' : 'Activate with Topup Wallet';
            } else {
                submitLabel.textContent = isUpgradeUi ? 'Submit Upgrade' : 'Submit for Approval';
            }
        }
        if (isUtr && typeof syncPaymentProof === 'function') {
            syncPaymentProof();
        }
    }

    modeInputs.forEach((el) => el.addEventListener('change', syncPayMode));
    syncPayMode();

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
