<?php
$pageTitle = 'Purchase Product';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/product_orders.php';
require_once __DIR__ . '/../includes/wallet.php';

require_user();
feature_guard_user_page('purchase-product');
$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

product_orders_ensure_tables($pdo);
wallet_ensure_schema($pdo);

$uid = (int) $user['id'];
$q = trim($_GET['q'] ?? $_POST['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $pid = (int) ($_POST['product_id'] ?? 0);
    $qty = max(1, (int) ($_POST['qty'] ?? 1));
    $found = null;
    foreach (product_orders_catalog($pdo) as $p) {
        if ((int) $p['id'] === $pid) {
            $found = $p;
            break;
        }
    }
    if (!$found) {
        flash('error', 'Product not found or inactive.');
        header('Location: purchase-product.php' . ($q !== '' ? '?q=' . rawurlencode($q) : ''));
        exit;
    }
    if ((int) ($found['stock_qty'] ?? 0) < 1) {
        flash('error', 'Product is out of stock.');
        header('Location: purchase-product.php' . ($q !== '' ? '?q=' . rawurlencode($q) : ''));
        exit;
    }
    $max = (int) $found['stock_qty'];
    $cart = product_orders_cart_get();
    $newQty = min($max, ($cart[$pid] ?? 0) + $qty);
    product_orders_cart_update($pid, $newQty);
    flash('success', $found['name'] . ' added to cart.');
    header('Location: purchase-cart.php');
    exit;
}

$catalog = product_orders_catalog($pdo, $q);
$cart = product_orders_cart_get();
$cartCount = product_orders_cart_count();
$productActivates = feature_enabled('feature_product_activates_package');
$productOnly = feature_product_only_activation();
$needsShopActivate = $productActivates && empty($user['package_id']);
$productMinActivate = product_activate_min_amount();

require_once __DIR__ . '/includes/header.php';
$flash = get_flash();
?>
<div class="up-page-head">
    <div>
        <h1>Purchase Product</h1>
        <p>Browse products, add to cart, then checkout with address &amp; Shopping Wallet.</p>
    </div>
    <div class="team-head-actions">
        <a href="purchase-cart.php" class="up-btn up-btn-primary">
            Cart<?= $cartCount > 0 ? ' (' . $cartCount . ')' : '' ?>
        </a>
        <a href="purchase-report.php" class="up-btn up-btn-outline">Orders</a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="up-alert up-alert-<?= $flash['type'] === 'error' ? 'err' : 'ok' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
<?php if ($needsShopActivate): ?>
    <div class="up-alert up-alert-ok">
        <?php if ($productOnly): ?>
            <?= $productMinActivate > 0
                ? 'Your ID activates after you buy any product priced at least ' . e(strip_tags(currency($productMinActivate))) . '.'
                : 'Your ID activates after you complete a product purchase.' ?>
        <?php else: ?>
            <?= $productMinActivate > 0
                ? 'Your ID activates after you buy a product priced at least ' . e(strip_tags(currency($productMinActivate))) . ' that is linked to a package.'
                : 'Your ID activates after you buy a product linked to a package.' ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="shop-card">
    <div class="shop-banner">
        <div>
            <span class="shop-kicker">Catalog</span>
            <h2>Products</h2>
            <p>Add items to cart — checkout opens on the next page.</p>
        </div>
        <form method="get" class="shop-search">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or SKU">
            <button type="submit" class="up-btn up-btn-primary">Search</button>
            <?php if ($q !== ''): ?>
                <a href="purchase-product.php" class="up-btn up-btn-outline">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$catalog): ?>
        <div class="shop-empty">
            <strong>No products found</strong>
            <p>Admin has not published active products yet<?= $q !== '' ? ' matching your search' : '' ?>.</p>
        </div>
    <?php else: ?>
        <div class="shop-grid">
            <?php foreach ($catalog as $p):
                $pid = (int) $p['id'];
                $stock = (int) ($p['stock_qty'] ?? 0);
                $thumb = product_thumb_url($p['thumbnail'] ?? null);
                $inCart = (int) ($cart[$pid] ?? 0);
                $oos = $stock < 1;
            ?>
            <article class="shop-product<?= $oos ? ' is-oos' : '' ?>">
                <div class="shop-product-media">
                    <?php if ($thumb !== ''): ?>
                        <img src="<?= e($thumb) ?>" alt="<?= e($p['name']) ?>">
                    <?php else: ?>
                        <span class="shop-product-ph" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
                        </span>
                    <?php endif; ?>
                    <?php if ($oos): ?><span class="shop-stock-tag is-out">Out of stock</span>
                    <?php elseif ($stock <= 5): ?><span class="shop-stock-tag is-low">Only <?= $stock ?> left</span>
                    <?php endif; ?>
                </div>
                <div class="shop-product-body">
                    <small class="shop-product-cat"><?= e($p['category_name'] ?? 'General') ?><?= !empty($p['sku']) ? ' · ' . e($p['sku']) : '' ?></small>
                    <h3><?= e($p['name']) ?></h3>
                    <div class="shop-product-price">
                        <strong><?= currency((float) $p['price']) ?></strong>
                        <?php if (!empty($p['mrp']) && (float) $p['mrp'] > (float) $p['price']): ?>
                            <s><?= currency((float) $p['mrp']) ?></s>
                        <?php endif; ?>
                        <span class="shop-bv">PV <?= number_format((float) ($p['bv'] ?? 0), 0) ?></span>
                    </div>
                    <form method="post" class="shop-add-form">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="product_id" value="<?= $pid ?>">
                        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
                        <input type="number" name="qty" min="1" max="<?= max(1, $stock) ?>" value="1" <?= $oos ? 'disabled' : '' ?>>
                        <button type="submit" class="up-btn up-btn-primary" <?= $oos ? 'disabled' : '' ?>>
                            <?= $inCart > 0 ? 'Add more' : 'Add to cart' ?>
                        </button>
                    </form>
                    <?php if ($inCart > 0): ?>
                        <p class="shop-in-cart"><?= $inCart ?> in cart · <a href="purchase-cart.php">View cart</a></p>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
