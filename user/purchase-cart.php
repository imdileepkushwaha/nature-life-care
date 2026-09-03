<?php
$pageTitle = 'Your Cart';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/product_orders.php';
require_once __DIR__ . '/../includes/wallet.php';

require_user();
feature_guard_user_page('purchase-cart');
$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

product_orders_ensure_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'qty') {
        $pid = (int) ($_POST['product_id'] ?? 0);
        $qty = (int) ($_POST['qty'] ?? 0);
        product_orders_cart_update($pid, max(0, $qty));
        header('Location: purchase-cart.php');
        exit;
    }

    if ($action === 'remove') {
        product_orders_cart_update((int) ($_POST['product_id'] ?? 0), 0);
        flash('success', 'Item removed from cart.');
        header('Location: purchase-cart.php');
        exit;
    }

    if ($action === 'remove_selected') {
        $ids = $_POST['selected'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        foreach ($ids as $pid) {
            product_orders_cart_update((int) $pid, 0);
        }
        flash('success', 'Selected items removed.');
        header('Location: purchase-cart.php');
        exit;
    }
}

$cart = product_orders_cart_get();
$built = product_orders_build_lines($pdo, $cart);
$lines = $built['ok'] ? $built['lines'] : [];
$itemCount = product_orders_cart_count();
$subtotal = (float) ($built['subtotal'] ?? 0);
$saveTotal = (float) ($built['save_total'] ?? 0);
$totalBv = (float) ($built['total_bv'] ?? 0);

require_once __DIR__ . '/includes/header.php';
$flash = get_flash();
?>
<div class="ck-page">
    <div class="ck-head">
        <div>
            <h1>Your Cart <span class="ck-count"><?= $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?></span></h1>
        </div>
        <a href="purchase-product.php" class="ck-back">← Continue Shopping</a>
    </div>

    <?php if ($flash): ?>
        <div class="up-alert up-alert-<?= $flash['type'] === 'error' ? 'err' : 'ok' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?php if (!$built['ok'] && $cart): ?>
        <div class="up-alert up-alert-err"><?= e($built['error'] ?? 'Cart error') ?></div>
    <?php endif; ?>

    <?php if (!$lines): ?>
        <section class="ck-empty">
            <strong>Your cart is empty</strong>
            <p>Add products from the catalog to continue checkout.</p>
            <a href="purchase-product.php" class="up-btn up-btn-primary">Browse products</a>
        </section>
    <?php else: ?>
        <div class="ck-layout">
            <div class="ck-main">
                <div class="ck-select-bar">
                    <label class="ck-check">
                        <input type="checkbox" id="ckSelectAll">
                        <span>Select All</span>
                    </label>
                    <button type="submit" form="ckRemoveSelected" class="ck-link-danger">Remove Selected</button>
                </div>

                <form method="post" id="ckRemoveSelected">
                    <input type="hidden" name="action" value="remove_selected">
                </form>

                <div class="ck-items">
                    <?php foreach ($lines as $line):
                        $pid = (int) $line['product_id'];
                        $thumb = product_thumb_url($line['thumbnail'] ?? null);
                        $mrp = (float) ($line['unit_mrp'] ?? 0);
                        $price = (float) $line['unit_price'];
                        $qty = (int) $line['qty'];
                        $max = (int) $line['max_qty'];
                        $off = ($mrp > $price && $mrp > 0) ? (int) round((($mrp - $price) / $mrp) * 100) : 0;
                    ?>
                    <article class="ck-item">
                        <label class="ck-check ck-item-check">
                            <input type="checkbox" name="selected[]" value="<?= $pid ?>" class="ck-item-cb" form="ckRemoveSelected">
                        </label>
                        <div class="ck-item-media">
                            <?php if ($thumb !== ''): ?>
                                <img src="<?= e($thumb) ?>" alt="">
                            <?php else: ?>
                                <span class="ck-item-ph" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="ck-item-body">
                            <small class="ck-item-sku"><?= e($line['sku'] ?: 'Product') ?></small>
                            <h3><?= e($line['product_name']) ?></h3>
                            <div class="ck-item-tags">
                                <span>PV <?= number_format((float) $line['unit_bv'], 0) ?></span>
                            </div>
                            <div class="ck-item-price">
                                <strong><?= currency($price) ?></strong>
                                <?php if ($mrp > $price): ?>
                                    <s><?= currency($mrp) ?></s>
                                    <em><?= $off ?>% off</em>
                                <?php endif; ?>
                            </div>
                            <div class="ck-item-actions">
                                <div class="ck-qty">
                                    <form method="post" class="ck-qty-form">
                                        <input type="hidden" name="action" value="qty">
                                        <input type="hidden" name="product_id" value="<?= $pid ?>">
                                        <input type="hidden" name="qty" value="<?= max(0, $qty - 1) ?>">
                                        <button type="submit" class="ck-qty-btn">−</button>
                                    </form>
                                    <span><?= $qty ?></span>
                                    <form method="post" class="ck-qty-form">
                                        <input type="hidden" name="action" value="qty">
                                        <input type="hidden" name="product_id" value="<?= $pid ?>">
                                        <input type="hidden" name="qty" value="<?= min($max, $qty + 1) ?>">
                                        <button type="submit" class="ck-qty-btn" <?= $qty >= $max ? 'disabled' : '' ?>>+</button>
                                    </form>
                                </div>
                                <form method="post">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="product_id" value="<?= $pid ?>">
                                    <button type="submit" class="ck-link-danger">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                        Remove
                                    </button>
                                </form>
                            </div>
                            <p class="ck-item-ship">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                Ships after payment · Track on invoice
                            </p>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <aside class="ck-side">
                <div class="ck-summary">
                    <h2>Order Summary</h2>
                    <div class="ck-sum-row"><span>Subtotal (<?= $itemCount ?> items)</span><strong><?= currency($subtotal) ?></strong></div>
                    <div class="ck-sum-row"><span>Total PV</span><strong><?= number_format($totalBv, 2) ?></strong></div>
                    <div class="ck-sum-row is-total"><span>Total Amount</span><strong><?= currency($subtotal) ?></strong></div>
                    <?php if ($saveTotal > 0): ?>
                        <div class="ck-save">You save <?= currency($saveTotal) ?> on this order!</div>
                    <?php endif; ?>
                    <a href="purchase-checkout.php?step=address" class="ck-cta">→ Proceed to Checkout</a>
                    <p class="ck-secure">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        Pay securely with Shopping Wallet
                    </p>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
    var all = document.getElementById('ckSelectAll');
    if (!all) return;
    all.addEventListener('change', function () {
        document.querySelectorAll('.ck-item-cb').forEach(function (cb) { cb.checked = all.checked; });
    });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
