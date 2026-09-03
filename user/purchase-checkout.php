<?php
$pageTitle = 'Checkout';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/product_orders.php';
require_once __DIR__ . '/../includes/wallet.php';

require_user();
feature_guard_user_page('purchase-checkout');
$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

product_orders_ensure_tables($pdo);
product_shipping_ensure_table($pdo);
wallet_ensure_schema($pdo);

$uid = (int) $user['id'];
$step = strtolower(trim((string) ($_GET['step'] ?? $_POST['step'] ?? 'address')));
if (!in_array($step, ['address', 'payment'], true)) {
    $step = 'address';
}

$cart = product_orders_cart_get();
$built = product_orders_build_lines($pdo, $cart);
if (!$built['ok'] || empty($built['lines'])) {
    flash('error', 'Your cart is empty. Add products first.');
    header('Location: purchase-product.php');
    exit;
}

$itemCount = product_orders_cart_count();
$subtotal = (float) $built['subtotal'];
$saveTotal = (float) ($built['save_total'] ?? 0);
$totalBv = (float) $built['total_bv'];
$errors = [];
$showAddressForm = isset($_GET['new']) || isset($_GET['edit']);

$addresses = product_shipping_list($pdo, $uid);
$selectedId = product_checkout_get_address_id();
if ($selectedId > 0 && !product_shipping_get($pdo, $selectedId, $uid)) {
    $selectedId = 0;
    product_checkout_set_address(0);
}
if ($selectedId < 1 && $addresses) {
    foreach ($addresses as $a) {
        if (!empty($a['is_default'])) {
            $selectedId = (int) $a['id'];
            break;
        }
    }
    if ($selectedId < 1) {
        $selectedId = (int) $addresses[0]['id'];
    }
    product_checkout_set_address($selectedId);
}

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId > 0 ? product_shipping_get($pdo, $editId, $uid) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_address') {
        $res = product_shipping_save($pdo, $uid, [
            'label' => (string) ($_POST['label'] ?? 'Home'),
            'full_name' => (string) ($_POST['full_name'] ?? ''),
            'phone' => (string) ($_POST['phone'] ?? ''),
            'address_line' => (string) ($_POST['address_line'] ?? ''),
            'city' => (string) ($_POST['city'] ?? ''),
            'state' => (string) ($_POST['state'] ?? ''),
            'pincode' => (string) ($_POST['pincode'] ?? ''),
            'is_default' => !empty($_POST['is_default']),
        ], (int) ($_POST['address_id'] ?? 0));
        if ($res['ok']) {
            product_checkout_set_address((int) $res['id']);
            flash('success', 'Address saved.');
            header('Location: purchase-checkout.php?step=address');
            exit;
        }
        $errors[] = $res['error'] ?? 'Could not save address.';
        $showAddressForm = true;
        $step = 'address';
    }

    if ($action === 'delete_address') {
        $aid = (int) ($_POST['address_id'] ?? 0);
        $res = product_shipping_delete($pdo, $uid, $aid);
        if ($res['ok']) {
            if (product_checkout_get_address_id() === $aid) {
                product_checkout_set_address(0);
            }
            flash('success', 'Address deleted.');
        } else {
            flash('error', $res['error'] ?? 'Delete failed.');
        }
        header('Location: purchase-checkout.php?step=address');
        exit;
    }

    if ($action === 'select_address') {
        $aid = (int) ($_POST['address_id'] ?? 0);
        if (!product_shipping_get($pdo, $aid, $uid)) {
            $errors[] = 'Please select a valid address.';
            $step = 'address';
        } else {
            product_checkout_set_address($aid);
            header('Location: purchase-checkout.php?step=payment');
            exit;
        }
    }

    if ($action === 'pay') {
        $aid = product_checkout_get_address_id();
        $addr = product_shipping_get($pdo, $aid, $uid);
        if (!$addr) {
            flash('error', 'Select a delivery address first.');
            header('Location: purchase-checkout.php?step=address');
            exit;
        }
        $userFresh = current_user($pdo) ?: $user;
        $res = product_orders_checkout(
            $pdo,
            $userFresh,
            product_orders_cart_get(),
            (string) $addr['full_name'],
            (string) $addr['phone'],
            (string) $addr['address_line'],
            trim((string) ($_POST['note'] ?? '')),
            [
                'city' => (string) $addr['city'],
                'state' => (string) $addr['state'],
                'pincode' => (string) $addr['pincode'],
            ]
        );
        if ($res['ok']) {
            product_checkout_clear();
            $msg = 'Order placed. Invoice ' . $res['invoice_no'] . '.';
            if (!empty($res['activated']) && !empty($res['package_name'])) {
                $msg = feature_product_only_activation()
                    ? 'Payment successful! Your account is now activated. Invoice ' . $res['invoice_no'] . '.'
                    : 'Payment successful! Your account is now activated with package ' . $res['package_name'] . '. Invoice ' . $res['invoice_no'] . '.';
            } elseif (($res['activated'] ?? null) === false && !empty($res['activation_error'])) {
                $msg .= ' Activation note: ' . $res['activation_error'];
            } elseif (feature_enabled('feature_product_activates_package') && empty($userFresh['package_id']) && empty($res['activated'])) {
                $msg .= feature_product_only_activation()
                    ? ' Account not activated — buy a product at/above the activation value.'
                    : ' Account not activated — product must be linked to a package (Activates package).';
            }
            flash('success', $msg);
            $redir = 'purchase-invoice.php?id=' . (int) $res['order_id'];
            if (!empty($res['activated'])) {
                $redir .= '&activated=1';
            }
            header('Location: ' . $redir);
            exit;
        }
        $errors[] = $res['error'] ?? 'Payment failed.';
        $step = 'payment';
    }
}

$addresses = product_shipping_list($pdo, $uid);
$selectedId = product_checkout_get_address_id();
$selectedAddr = $selectedId > 0 ? product_shipping_get($pdo, $selectedId, $uid) : null;

$balances = wallet_get_balances($pdo, $uid);
$shopBal = (float) ($balances['shopping'] ?? 0);
$topupBal = (float) ($balances['topup'] ?? 0);
$needMore = max(0.0, round($subtotal - $shopBal, 2));
$canPay = $shopBal + 0.00001 >= $subtotal;
$featTopup = feature_module_allowed('wallet_topup');
$productActivates = feature_enabled('feature_product_activates_package');
$needsActivation = $productActivates && empty($user['package_id']);
$productMinActivate = product_activate_min_amount();
$productOnly = feature_product_only_activation();

if ($step === 'payment' && !$selectedAddr) {
    header('Location: purchase-checkout.php?step=address');
    exit;
}

$formDefaults = [
    'label' => $editRow['label'] ?? 'Home',
    'full_name' => $editRow['full_name'] ?? ($user['full_name'] ?? ''),
    'phone' => $editRow['phone'] ?? ($user['phone'] ?? ''),
    'address_line' => $editRow['address_line'] ?? '',
    'city' => $editRow['city'] ?? '',
    'state' => $editRow['state'] ?? '',
    'pincode' => $editRow['pincode'] ?? '',
    'is_default' => !empty($editRow['is_default']) || !$addresses,
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_address') {
    foreach (['label', 'full_name', 'phone', 'address_line', 'city', 'state', 'pincode'] as $k) {
        $formDefaults[$k] = (string) ($_POST[$k] ?? $formDefaults[$k]);
    }
    $formDefaults['is_default'] = !empty($_POST['is_default']);
}

require_once __DIR__ . '/includes/header.php';
$flash = get_flash();
?>
<div class="ck-page">
    <div class="ck-head">
        <div>
            <h1>Checkout <span class="ck-count"><?= $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?></span></h1>
        </div>
        <a href="purchase-cart.php" class="ck-back">← Back to cart</a>
    </div>

    <nav class="ck-steps" aria-label="Checkout steps">
        <a class="ck-step<?= $step === 'address' ? ' is-on' : ' is-done' ?>" href="purchase-checkout.php?step=address"><span>1</span> Address</a>
        <span class="ck-step-sep" aria-hidden="true">→</span>
        <a class="ck-step<?= $step === 'payment' ? ' is-on' : '' ?>" href="<?= $selectedAddr ? 'purchase-checkout.php?step=payment' : 'purchase-checkout.php?step=address' ?>"><span>2</span> Payment</a>
        <span class="ck-step-sep" aria-hidden="true">→</span>
        <span class="ck-step"><span>3</span> Review</span>
    </nav>

    <?php if ($flash): ?>
        <div class="up-alert up-alert-<?= $flash['type'] === 'error' ? 'err' : 'ok' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?php if (!$showAddressForm): foreach ($errors as $err): ?>
        <div class="up-alert up-alert-err"><?= e($err) ?></div>
    <?php endforeach; endif; ?>

    <div class="ck-layout">
        <div class="ck-main">
            <?php if ($step === 'address'): ?>
            <section class="ck-panel">
                <div class="ck-panel-head">
                    <h2>Delivery address</h2>
                    <button type="button" class="ck-cta-sm" data-ck-addr-open><?= $addresses ? '+ Add New' : '+ Add address' ?></button>
                </div>
                <div class="ck-panel-sub">
                    <strong>Saved Addresses</strong>
                    <small>Select where you want this order delivered</small>
                </div>

                <div class="ck-addr-grid">
                    <?php foreach ($addresses as $a):
                        $aid = (int) $a['id'];
                        $isOn = $aid === $selectedId;
                    ?>
                    <article class="ck-addr-card<?= $isOn ? ' is-on' : '' ?>">
                        <div class="ck-addr-badges">
                            <span class="ck-addr-label"><?= e((string) $a['label']) ?></span>
                            <?php if (!empty($a['is_default'])): ?><span class="ck-addr-default">Default</span><?php endif; ?>
                            <?php if ($isOn): ?><span class="ck-addr-selected">Selected</span><?php endif; ?>
                        </div>
                        <strong><?= e((string) $a['full_name']) ?></strong>
                        <p><?= e((string) $a['address_line']) ?>, <?= e((string) $a['city']) ?>, <?= e((string) $a['state']) ?> - <?= e((string) $a['pincode']) ?></p>
                        <p class="ck-addr-phone"><?= e((string) $a['phone']) ?></p>
                        <div class="ck-addr-actions">
                            <form method="post">
                                <input type="hidden" name="action" value="select_address">
                                <input type="hidden" name="address_id" value="<?= $aid ?>">
                                <button type="submit" class="ck-cta-sm"><?= $isOn ? 'Continue →' : 'Deliver here' ?></button>
                            </form>
                            <button type="button" class="up-btn up-btn-outline up-btn-sm" data-ck-addr-edit
                                data-id="<?= $aid ?>"
                                data-label="<?= e((string) $a['label']) ?>"
                                data-name="<?= e((string) $a['full_name']) ?>"
                                data-phone="<?= e((string) $a['phone']) ?>"
                                data-line="<?= e((string) $a['address_line']) ?>"
                                data-city="<?= e((string) $a['city']) ?>"
                                data-state="<?= e((string) $a['state']) ?>"
                                data-pin="<?= e((string) $a['pincode']) ?>"
                                data-default="<?= !empty($a['is_default']) ? '1' : '0' ?>">Edit</button>
                            <form method="post" onsubmit="return confirm('Delete this address?');">
                                <input type="hidden" name="action" value="delete_address">
                                <input type="hidden" name="address_id" value="<?= $aid ?>">
                                <button type="submit" class="up-btn up-btn-outline up-btn-sm">Delete</button>
                            </form>
                        </div>
                    </article>
                    <?php endforeach; ?>

                    <button type="button" class="ck-addr-add" data-ck-addr-open>
                        <span>+</span>
                        Add new address
                    </button>
                </div>

                <?php if ($addresses): ?>
                <form method="post" class="ck-addr-continue">
                    <input type="hidden" name="action" value="select_address">
                    <input type="hidden" name="address_id" value="<?= (int) $selectedId ?>">
                    <button type="submit" class="ck-cta" <?= $selectedId < 1 ? 'disabled' : '' ?>>Continue to Payment →</button>
                </form>
                <?php else: ?>
                <p class="ck-addr-empty-hint">Add a delivery address to continue checkout.</p>
                <?php endif; ?>
            </section>

            <div class="ck-modal<?= $showAddressForm ? ' is-open' : '' ?>" id="ckAddrModal" <?= $showAddressForm ? '' : 'hidden' ?> role="dialog" aria-modal="true" aria-labelledby="ckAddrTitle">
                <div class="ck-modal-backdrop" data-ck-addr-close></div>
                <div class="ck-modal-card" role="document">
                    <header class="ck-modal-hero">
                        <div>
                            <span class="ck-modal-kicker">Shipping</span>
                            <h2 id="ckAddrTitle"><?= $editRow ? 'Edit address' : 'Add new address' ?></h2>
                            <p>We’ll deliver your order to this address.</p>
                        </div>
                        <button type="button" class="ck-modal-x" data-ck-addr-close aria-label="Close">&times;</button>
                    </header>
                    <form method="post" class="ck-modal-body" id="ckAddrForm">
                        <input type="hidden" name="action" value="save_address">
                        <input type="hidden" name="address_id" id="ckAddrId" value="<?= (int) ($editRow['id'] ?? 0) ?>">

                        <?php if ($errors && $showAddressForm): ?>
                        <div class="up-alert up-alert-err"><?= e($errors[0]) ?></div>
                        <?php endif; ?>

                        <div class="ck-label-pills" role="group" aria-label="Address type">
                            <?php foreach (['Home', 'Work', 'Other'] as $lab): ?>
                            <label class="ck-label-pill">
                                <input type="radio" name="label" value="<?= e($lab) ?>" <?= ($formDefaults['label'] ?? 'Home') === $lab ? 'checked' : '' ?>>
                                <span><?= e($lab) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="ck-modal-grid">
                            <div class="form-group">
                                <label for="ck_full_name">Full name *</label>
                                <input type="text" name="full_name" id="ck_full_name" required autocomplete="name" placeholder="Receiver name" value="<?= e($formDefaults['full_name']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="ck_phone">Phone *</label>
                                <input type="tel" name="phone" id="ck_phone" required autocomplete="tel" placeholder="10-digit mobile" value="<?= e($formDefaults['phone']) ?>">
                            </div>
                            <div class="form-group ck-full">
                                <label for="ck_address_line">Address *</label>
                                <textarea name="address_line" id="ck_address_line" rows="3" required placeholder="House / flat, street, landmark"><?= e($formDefaults['address_line']) ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="ck_city">City *</label>
                                <input type="text" name="city" id="ck_city" required autocomplete="address-level2" placeholder="City" value="<?= e($formDefaults['city']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="ck_state">State *</label>
                                <input type="text" name="state" id="ck_state" required autocomplete="address-level1" placeholder="State" value="<?= e($formDefaults['state']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="ck_pincode">PIN code *</label>
                                <input type="text" name="pincode" id="ck_pincode" required inputmode="numeric" maxlength="12" autocomplete="postal-code" placeholder="e.g. 226012" value="<?= e($formDefaults['pincode']) ?>">
                            </div>
                        </div>

                        <label class="ck-default-toggle">
                            <input type="checkbox" name="is_default" id="ck_is_default" value="1" <?= !empty($formDefaults['is_default']) ? 'checked' : '' ?>>
                            <span>
                                <strong>Set as default address</strong>
                                <small>Use this address next time automatically</small>
                            </span>
                        </label>

                        <div class="ck-modal-actions">
                            <button type="button" class="up-btn up-btn-outline" data-ck-addr-close>Cancel</button>
                            <button type="submit" class="ck-cta-sm ck-modal-save"><?= $editRow ? 'Update address' : 'Save address' ?></button>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <section class="ck-panel">
                <div class="ck-panel-head">
                    <h2>Payment</h2>
                    <a href="purchase-checkout.php?step=address" class="ck-back">Change address</a>
                </div>

                <?php if ($selectedAddr): ?>
                <div class="ck-pay-ship">
                    <span class="shop-kicker">Deliver to</span>
                    <strong><?= e((string) $selectedAddr['full_name']) ?></strong>
                    <p><?= e((string) $selectedAddr['address_line']) ?>, <?= e((string) $selectedAddr['city']) ?>, <?= e((string) $selectedAddr['state']) ?> - <?= e((string) $selectedAddr['pincode']) ?></p>
                    <p><?= e((string) $selectedAddr['phone']) ?></p>
                </div>
                <?php endif; ?>

                <div class="ck-wallet-card<?= $canPay ? ' is-ok' : ' is-low' ?>">
                    <div>
                        <span class="shop-kicker">Shopping Wallet</span>
                        <strong><?= currency($shopBal) ?></strong>
                        <small>Order total <?= currency($subtotal) ?></small>
                    </div>
                    <?php if ($canPay): ?>
                        <span class="ck-wallet-badge">Sufficient balance</span>
                    <?php else: ?>
                        <span class="ck-wallet-badge is-low">Need <?= currency($needMore) ?> more</span>
                    <?php endif; ?>
                </div>

                <?php if (!$canPay): ?>
                <div class="ck-fund-box">
                    <strong>Add money to pay</strong>
                    <p>Topup Wallet balance: <?= currency($topupBal) ?>. Transfer to Shopping Wallet, then pay.</p>
                    <div class="ck-fund-actions">
                        <?php if ($featTopup): ?>
                        <a href="wallet-topup.php?next=checkout" class="up-btn up-btn-outline">1. Request Topup</a>
                        <?php endif; ?>
                        <a href="wallet-transfer.php?from=topup&to=shopping&next=checkout&amount=<?= rawurlencode(number_format($needMore > 0 ? $needMore : $subtotal, 2, '.', '')) ?>" class="up-btn up-btn-primary">2. Transfer to Shopping</a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($needsActivation): ?>
                <div class="up-alert up-alert-ok">
                    <?php if ($productOnly): ?>
                        <?= $productMinActivate > 0
                            ? 'Paying for a product priced at least ' . e(strip_tags(currency($productMinActivate))) . ' will activate your ID.'
                            : 'Completing this purchase can activate your ID.' ?>
                    <?php else: ?>
                        <?= $productMinActivate > 0
                            ? 'Paying for a qualifying product (min ' . e(strip_tags(currency($productMinActivate))) . ', package-linked) will activate your ID.'
                            : 'Paying for a package-linked product can activate your ID.' ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <form method="post" class="ck-pay-form">
                    <input type="hidden" name="action" value="pay">
                    <input type="hidden" name="step" value="payment">
                    <div class="ck-pay-note">
                        <label for="note">Order note <span>(optional)</span></label>
                        <textarea name="note" id="note" rows="3" maxlength="250" placeholder="Any delivery instruction for the courier…"><?= e((string) ($_POST['note'] ?? '')) ?></textarea>
                    </div>
                    <button type="submit" class="ck-cta" <?= !$canPay ? 'disabled' : '' ?>>
                        Pay <?= currency($subtotal) ?> →
                    </button>
                    <p class="ck-secure">Amount will be deducted from Shopping Wallet instantly.</p>
                </form>
            </section>
            <?php endif; ?>
        </div>

        <aside class="ck-side">
            <div class="ck-summary">
                <h2>Order Summary</h2>
                <?php foreach ($built['lines'] as $line): ?>
                <div class="ck-sum-line">
                    <span><?= e($line['product_name']) ?> × <?= (int) $line['qty'] ?></span>
                    <strong><?= currency((float) $line['line_total']) ?></strong>
                </div>
                <?php endforeach; ?>
                <div class="ck-sum-row"><span>Subtotal</span><strong><?= currency($subtotal) ?></strong></div>
                <div class="ck-sum-row"><span>Total PV</span><strong><?= number_format($totalBv, 2) ?></strong></div>
                <div class="ck-sum-row is-total"><span>Total Amount</span><strong><?= currency($subtotal) ?></strong></div>
                <?php if ($saveTotal > 0): ?>
                    <div class="ck-save">You save <?= currency($saveTotal) ?> on this order!</div>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('ckAddrModal');
    if (!modal) return;
    var form = document.getElementById('ckAddrForm');
    var title = document.getElementById('ckAddrTitle');
    var saveBtn = form && form.querySelector('.ck-modal-save');
    var defaults = {
        id: '0',
        label: 'Home',
        name: <?= json_encode((string) ($user['full_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>,
        phone: <?= json_encode((string) ($user['phone'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>,
        line: '',
        city: '',
        state: '',
        pin: '',
        isDefault: <?= empty($addresses) ? 'true' : 'false' ?>
    };

    function openModal(data, isEdit) {
        if (!form) return;
        document.getElementById('ckAddrId').value = data.id || '0';
        form.querySelectorAll('input[name="label"]').forEach(function (r) {
            r.checked = r.value === (data.label || 'Home');
        });
        document.getElementById('ck_full_name').value = data.name || '';
        document.getElementById('ck_phone').value = data.phone || '';
        document.getElementById('ck_address_line').value = data.line || '';
        document.getElementById('ck_city').value = data.city || '';
        document.getElementById('ck_state').value = data.state || '';
        document.getElementById('ck_pincode').value = data.pin || '';
        document.getElementById('ck_is_default').checked = !!data.isDefault;
        if (title) title.textContent = isEdit ? 'Edit address' : 'Add new address';
        if (saveBtn) saveBtn.textContent = isEdit ? 'Update address' : 'Save address';
        modal.hidden = false;
        modal.classList.add('is-open');
        document.body.classList.add('ck-modal-open');
        setTimeout(function () {
            var el = document.getElementById('ck_full_name');
            if (el) el.focus();
        }, 50);
        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('step', 'address');
            if (isEdit) {
                url.searchParams.set('edit', data.id);
                url.searchParams.delete('new');
            } else {
                url.searchParams.set('new', '1');
                url.searchParams.delete('edit');
            }
            window.history.replaceState({}, '', url);
        }
    }

    function closeModal() {
        modal.hidden = true;
        modal.classList.remove('is-open');
        document.body.classList.remove('ck-modal-open');
        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.delete('new');
            url.searchParams.delete('edit');
            url.searchParams.set('step', 'address');
            window.history.replaceState({}, '', url);
        }
    }

    document.querySelectorAll('[data-ck-addr-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(Object.assign({}, defaults), false);
        });
    });
    document.querySelectorAll('[data-ck-addr-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal({
                id: btn.getAttribute('data-id') || '0',
                label: btn.getAttribute('data-label') || 'Home',
                name: btn.getAttribute('data-name') || '',
                phone: btn.getAttribute('data-phone') || '',
                line: btn.getAttribute('data-line') || '',
                city: btn.getAttribute('data-city') || '',
                state: btn.getAttribute('data-state') || '',
                pin: btn.getAttribute('data-pin') || '',
                isDefault: btn.getAttribute('data-default') === '1'
            }, true);
        });
    });
    document.querySelectorAll('[data-ck-addr-close]').forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });

    <?php if ($showAddressForm): ?>
    document.body.classList.add('ck-modal-open');
    <?php endif; ?>
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
