<?php
/**
 * Member product purchase (Shopping Wallet).
 */

require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/utility.php';
require_once __DIR__ . '/activation.php';

function product_orders_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    products_ensure_columns($pdo);
    wallet_ensure_schema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_no VARCHAR(40) NOT NULL,
            member_id INT NOT NULL,
            wallet_type ENUM('shopping') NOT NULL DEFAULT 'shopping',
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_bv DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('pending','paid','cancelled','refunded') NOT NULL DEFAULT 'paid',
            shipping_name VARCHAR(150) NULL,
            shipping_phone VARCHAR(30) NULL,
            shipping_address TEXT NULL,
            note TEXT NULL,
            ledger_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_po_invoice (invoice_no),
            KEY idx_po_member (member_id),
            KEY idx_po_status (status),
            KEY idx_po_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(150) NOT NULL,
            sku VARCHAR(60) NULL,
            qty INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            unit_bv DECIMAL(12,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            line_bv DECIMAL(12,2) NOT NULL DEFAULT 0,
            KEY idx_poi_order (order_id),
            KEY idx_poi_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    product_orders_ensure_fulfillment_columns($pdo);
    $done = true;
}

/** Delivery / shipping columns (safe to call often). */
function product_orders_ensure_fulfillment_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $cols = [
        'delivery_status' => "VARCHAR(30) NOT NULL DEFAULT 'pending'",
        'shipping_city' => 'VARCHAR(100) NULL',
        'shipping_state' => 'VARCHAR(100) NULL',
        'shipping_pincode' => 'VARCHAR(20) NULL',
        'courier_name' => 'VARCHAR(120) NULL',
        'tracking_no' => 'VARCHAR(80) NULL',
        'shipped_at' => 'DATETIME NULL',
        'delivered_at' => 'DATETIME NULL',
        'delivery_note' => 'VARCHAR(255) NULL',
    ];
    foreach ($cols as $col => $def) {
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM product_orders LIKE " . $pdo->quote($col));
            if ($chk && !$chk->fetch()) {
                $pdo->exec("ALTER TABLE product_orders ADD COLUMN {$col} {$def}");
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    $done = true;
}

/** @return array<string, string> */
function product_delivery_statuses(): array
{
    return [
        'pending' => 'Order Placed',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];
}

function product_delivery_label(?string $status): string
{
    $status = strtolower(trim((string) $status));
    if ($status === '') {
        $status = 'pending';
    }
    $map = product_delivery_statuses();
    return $map[$status] ?? ucwords(str_replace('_', ' ', $status));
}

/** Ordered steps for timeline UI (excludes cancelled). */
function product_delivery_timeline_steps(): array
{
    return ['pending', 'processing', 'shipped', 'out_for_delivery', 'delivered'];
}

function product_delivery_step_index(?string $status): int
{
    $status = strtolower(trim((string) $status));
    if ($status === '' || $status === 'cancelled') {
        return $status === 'cancelled' ? -1 : 0;
    }
    $steps = product_delivery_timeline_steps();
    $idx = array_search($status, $steps, true);
    return $idx === false ? 0 : (int) $idx;
}

/**
 * Last used shipping address for member (checkout prefills).
 * @return array{name:string,phone:string,address:string,city:string,state:string,pincode:string}|null
 */
function product_orders_last_shipping(PDO $pdo, int $memberId): ?array
{
    if ($memberId < 1) {
        return null;
    }
    product_orders_ensure_tables($pdo);
    $stmt = $pdo->prepare("
        SELECT shipping_name, shipping_phone, shipping_address,
               shipping_city, shipping_state, shipping_pincode
        FROM product_orders
        WHERE member_id = ? AND shipping_address IS NOT NULL AND shipping_address != ''
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'name' => (string) ($row['shipping_name'] ?? ''),
        'phone' => (string) ($row['shipping_phone'] ?? ''),
        'address' => (string) ($row['shipping_address'] ?? ''),
        'city' => (string) ($row['shipping_city'] ?? ''),
        'state' => (string) ($row['shipping_state'] ?? ''),
        'pincode' => (string) ($row['shipping_pincode'] ?? ''),
    ];
}

/**
 * Admin: update delivery / courier details.
 * @param array{delivery_status?:string,courier_name?:string,tracking_no?:string,delivery_note?:string} $data
 * @return array{ok:bool,error:?string}
 */
function product_orders_admin_update_fulfillment(PDO $pdo, int $orderId, array $data): array
{
    product_orders_ensure_tables($pdo);
    $order = product_order_get($pdo, $orderId);
    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }

    $statuses = product_delivery_statuses();
    $status = strtolower(trim((string) ($data['delivery_status'] ?? ($order['delivery_status'] ?? 'pending'))));
    if (!isset($statuses[$status])) {
        return ['ok' => false, 'error' => 'Invalid delivery status.'];
    }

    $courier = trim((string) ($data['courier_name'] ?? ($order['courier_name'] ?? '')));
    $tracking = trim((string) ($data['tracking_no'] ?? ($order['tracking_no'] ?? '')));
    $note = trim((string) ($data['delivery_note'] ?? ($order['delivery_note'] ?? '')));

    $shippedAt = $order['shipped_at'] ?? null;
    $deliveredAt = $order['delivered_at'] ?? null;
    if (in_array($status, ['shipped', 'out_for_delivery', 'delivered'], true) && empty($shippedAt)) {
        $shippedAt = date('Y-m-d H:i:s');
    }
    if ($status === 'delivered' && empty($deliveredAt)) {
        $deliveredAt = date('Y-m-d H:i:s');
    }
    if ($status === 'pending' || $status === 'processing') {
        // keep existing timestamps
    }
    if ($status === 'cancelled') {
        // leave dates as-is
    }

    try {
        $pdo->prepare("
            UPDATE product_orders SET
                delivery_status = ?,
                courier_name = ?,
                tracking_no = ?,
                delivery_note = ?,
                shipped_at = ?,
                delivered_at = ?
            WHERE id = ?
        ")->execute([
            $status,
            $courier !== '' ? $courier : null,
            $tracking !== '' ? $tracking : null,
            $note !== '' ? $note : null,
            $shippedAt,
            $deliveredAt,
            $orderId,
        ]);
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update delivery status.'];
    }
}

function product_orders_cart_get(): array
{
    if (!isset($_SESSION['shop_cart']) || !is_array($_SESSION['shop_cart'])) {
        $_SESSION['shop_cart'] = [];
    }
    $out = [];
    foreach ($_SESSION['shop_cart'] as $pid => $qty) {
        $pid = (int) $pid;
        $qty = (int) $qty;
        if ($pid > 0 && $qty > 0) {
            $out[$pid] = $qty;
        }
    }
    $_SESSION['shop_cart'] = $out;
    return $out;
}

function product_orders_cart_set(array $cart): void
{
    $clean = [];
    foreach ($cart as $pid => $qty) {
        $pid = (int) $pid;
        $qty = (int) $qty;
        if ($pid > 0 && $qty > 0) {
            $clean[$pid] = min(999, $qty);
        }
    }
    $_SESSION['shop_cart'] = $clean;
}

function product_orders_cart_clear(): void
{
    $_SESSION['shop_cart'] = [];
}

function product_orders_cart_add(int $productId, int $qty = 1): void
{
    if ($productId < 1 || $qty < 1) {
        return;
    }
    $cart = product_orders_cart_get();
    $cart[$productId] = min(999, ($cart[$productId] ?? 0) + $qty);
    product_orders_cart_set($cart);
}

function product_orders_cart_update(int $productId, int $qty): void
{
    $cart = product_orders_cart_get();
    if ($qty <= 0) {
        unset($cart[$productId]);
    } else {
        $cart[$productId] = min(999, $qty);
    }
    product_orders_cart_set($cart);
}

function product_orders_cart_count(): int
{
    return array_sum(product_orders_cart_get());
}

/** Active catalog products for purchase. */
function product_orders_catalog(PDO $pdo, string $q = ''): array
{
    product_orders_ensure_tables($pdo);
    $params = [];
    $where = "p.status = 'active'";
    if ($q !== '') {
        $where .= ' AND (p.name LIKE ? OR p.sku LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$like, $like];
    }

    $sql = "
        SELECT p.id, p.name, p.sku, p.price, p.bv, p.stock_qty, p.thumbnail, p.description,
               c.name AS category_name, sc.name AS subcategory_name
        FROM products p
        LEFT JOIN product_categories c ON c.id = p.category_id
        LEFT JOIN product_subcategories sc ON sc.id = p.subcategory_id
        LEFT JOIN subcategory_settings ss ON ss.subcategory_id = p.subcategory_id
        WHERE {$where}
          AND (p.subcategory_id IS NULL OR ss.allow_purchase IS NULL OR ss.allow_purchase = 1)
        ORDER BY p.name ASC
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        // subcategory_settings may not exist
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.sku, p.price, p.bv, p.stock_qty, p.thumbnail, p.description,
                   c.name AS category_name, sc.name AS subcategory_name
            FROM products p
            LEFT JOIN product_categories c ON c.id = p.category_id
            LEFT JOIN product_subcategories sc ON sc.id = p.subcategory_id
            WHERE {$where}
            ORDER BY p.name ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    }

    // Optional MRP if column exists
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM products LIKE 'mrp'");
        if ($chk && $chk->fetch() && $rows) {
            $ids = array_map(static fn($r) => (int) $r['id'], $rows);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $m = $pdo->prepare("SELECT id, mrp FROM products WHERE id IN ($in)");
            $m->execute($ids);
            $map = [];
            foreach ($m->fetchAll() as $mr) {
                $map[(int) $mr['id']] = $mr['mrp'];
            }
            foreach ($rows as &$r) {
                $r['mrp'] = $map[(int) $r['id']] ?? null;
            }
            unset($r);
        }
    } catch (Throwable $e) {
        // ignore
    }

    return $rows;
}

/**
 * Resolve cart lines against live stock/prices.
 * @return array{ok:bool,error:?string,lines:array,subtotal:float,total_bv:float}
 */
function product_orders_build_lines(PDO $pdo, array $cart): array
{
    product_orders_ensure_tables($pdo);
    if (!$cart) {
        return ['ok' => false, 'error' => 'Your cart is empty.', 'lines' => [], 'subtotal' => 0.0, 'total_bv' => 0.0];
    }

    $lines = [];
    $subtotal = 0.0;
    $totalBv = 0.0;

    $stmt = $pdo->prepare("
        SELECT id, name, sku, price, bv, stock_qty, status, package_id, thumbnail
        FROM products
        WHERE id = ?
        LIMIT 1
    ");

    $hasMrp = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM products LIKE 'mrp'");
        $hasMrp = $chk && (bool) $chk->fetch();
    } catch (Throwable $e) {
        $hasMrp = false;
    }
    if ($hasMrp) {
        $stmt = $pdo->prepare("
            SELECT id, name, sku, price, bv, stock_qty, status, package_id, thumbnail, mrp
            FROM products
            WHERE id = ?
            LIMIT 1
        ");
    }

    foreach ($cart as $pid => $qty) {
        $pid = (int) $pid;
        $qty = (int) $qty;
        if ($pid < 1 || $qty < 1) {
            continue;
        }
        $stmt->execute([$pid]);
        $p = $stmt->fetch();
        if (!$p || ($p['status'] ?? '') !== 'active') {
            return ['ok' => false, 'error' => 'One or more products are unavailable.', 'lines' => [], 'subtotal' => 0.0, 'total_bv' => 0.0];
        }
        $stock = (int) ($p['stock_qty'] ?? 0);
        if ($stock < $qty) {
            return [
                'ok' => false,
                'error' => ($p['name'] ?? 'Product') . ' has only ' . $stock . ' in stock.',
                'lines' => [],
                'subtotal' => 0.0,
                'total_bv' => 0.0,
            ];
        }
        $unit = round((float) $p['price'], 2);
        $mrp = round((float) ($p['mrp'] ?? 0), 2);
        $bv = round((float) ($p['bv'] ?? 0), 2);
        $lineTotal = round($unit * $qty, 2);
        $lineBv = round($bv * $qty, 2);
        $lineMrp = $mrp > $unit ? round($mrp * $qty, 2) : 0.0;
        $lines[] = [
            'product_id' => $pid,
            'product_name' => (string) $p['name'],
            'sku' => (string) ($p['sku'] ?? ''),
            'qty' => $qty,
            'max_qty' => $stock,
            'unit_price' => $unit,
            'unit_mrp' => $mrp,
            'unit_bv' => $bv,
            'line_total' => $lineTotal,
            'line_mrp' => $lineMrp,
            'line_bv' => $lineBv,
            'package_id' => product_orders_resolve_package_id($pdo, $pid, $p['package_id'] ?? null) ?: null,
            'thumbnail' => (string) ($p['thumbnail'] ?? ''),
        ];
        $subtotal += $lineTotal;
        $totalBv += $lineBv;
    }

    if (!$lines) {
        return ['ok' => false, 'error' => 'Your cart is empty.', 'lines' => [], 'subtotal' => 0.0, 'total_bv' => 0.0];
    }

    $mrpTotal = 0.0;
    foreach ($lines as $ln) {
        $mrpTotal += (float) ($ln['line_mrp'] ?? 0);
    }

    return [
        'ok' => true,
        'error' => null,
        'lines' => $lines,
        'subtotal' => round($subtotal, 2),
        'total_bv' => round($totalBv, 2),
        'mrp_total' => round($mrpTotal, 2),
        'save_total' => round(max(0, $mrpTotal - $subtotal), 2),
    ];
}

function product_orders_next_invoice(PDO $pdo): string
{
    $prefix = 'INV-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT invoice_no FROM product_orders WHERE invoice_no LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string) ($stmt->fetchColumn() ?: '');
    $seq = 1;
    if ($last !== '' && preg_match('/-(\d+)$/', $last, $m)) {
        $seq = (int) $m[1] + 1;
    }
    return $prefix . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
}

/**
 * Place order and debit Shopping Wallet.
 * Optionally activates member when feature_product_activates_package is on.
 *
 * @return array{ok:bool,error:?string,order_id:?int,invoice_no:?string,activated:?bool,activation_error:?string,package_name:?string}
 */
function product_orders_checkout(
    PDO $pdo,
    array $member,
    array $cart,
    string $shippingName,
    string $shippingPhone,
    string $shippingAddress,
    string $note = '',
    array $shippingExtra = []
): array {
    if (!feature_enabled('feature_product_shop_enabled')) {
        return ['ok' => false, 'error' => 'Product shop is disabled for this client.', 'order_id' => null, 'invoice_no' => null, 'activated' => null, 'activation_error' => null, 'package_name' => null];
    }
    product_orders_ensure_tables($pdo);
    $memberId = (int) ($member['id'] ?? 0);
    if ($memberId < 1) {
        return ['ok' => false, 'error' => 'Invalid member.', 'order_id' => null, 'invoice_no' => null, 'activated' => null, 'activation_error' => null, 'package_name' => null];
    }
    if (($member['status'] ?? '') === 'blocked') {
        return ['ok' => false, 'error' => 'Your account is blocked.', 'order_id' => null, 'invoice_no' => null, 'activated' => null, 'activation_error' => null, 'package_name' => null];
    }

    $shippingName = trim($shippingName);
    $shippingPhone = trim($shippingPhone);
    $shippingAddress = trim($shippingAddress);
    $shipCity = trim((string) ($shippingExtra['city'] ?? ''));
    $shipState = trim((string) ($shippingExtra['state'] ?? ''));
    $shipPin = trim((string) ($shippingExtra['pincode'] ?? ''));
    if ($shippingName === '') {
        return ['ok' => false, 'error' => 'Shipping name is required.', 'order_id' => null, 'invoice_no' => null, 'activated' => null, 'activation_error' => null, 'package_name' => null];
    }
    if ($shippingPhone === '') {
        return ['ok' => false, 'error' => 'Shipping phone is required.', 'order_id' => null, 'invoice_no' => null, 'activated' => null, 'activation_error' => null, 'package_name' => null];
    }
    if ($shippingAddress === '') {
        return ['ok' => false, 'error' => 'Shipping address is required.', 'order_id' => null, 'invoice_no' => null, 'activated' => null, 'activation_error' => null, 'package_name' => null];
    }
    if ($shipCity === '' || $shipState === '' || $shipPin === '') {
        return ['ok' => false, 'error' => 'City, state and PIN code are required.', 'order_id' => null, 'invoice_no' => null, 'activated' => null, 'activation_error' => null, 'package_name' => null];
    }

    $builtLines = [];
    try {
        $pdo->beginTransaction();

        $built = product_orders_build_lines($pdo, $cart);
        if (!$built['ok']) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $built['error'], 'order_id' => null, 'invoice_no' => null, 'activated' => null, 'activation_error' => null, 'package_name' => null];
        }
        $builtLines = $built['lines'];

        $total = (float) $built['subtotal'];
        $totalBv = (float) $built['total_bv'];
        $bal = wallet_balance($pdo, $memberId, 'shopping');
        if ($bal + 0.00001 < $total) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'error' => 'Insufficient Shopping Wallet balance. Need ' . strip_tags(currency($total)) . ', available ' . strip_tags(currency($bal)) . '. First topup, then transfer to Shopping Wallet.',
                'order_id' => null,
                'invoice_no' => null,
                'activated' => null,
                'activation_error' => null,
                'package_name' => null,
            ];
        }

        $invoiceNo = product_orders_next_invoice($pdo);
        $pdo->prepare("
            INSERT INTO product_orders
                (invoice_no, member_id, wallet_type, subtotal, discount_amount, total_amount, total_bv,
                 status, delivery_status, shipping_name, shipping_phone, shipping_address,
                 shipping_city, shipping_state, shipping_pincode, note)
            VALUES (?, ?, 'shopping', ?, 0, ?, ?, 'paid', 'pending', ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $invoiceNo,
            $memberId,
            $total,
            $total,
            $totalBv,
            $shippingName,
            $shippingPhone,
            $shippingAddress,
            $shipCity,
            $shipState,
            $shipPin,
            $note !== '' ? $note : null,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $itemIns = $pdo->prepare("
            INSERT INTO product_order_items
                (order_id, product_id, product_name, sku, qty, unit_price, unit_bv, line_total, line_bv)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stockUp = $pdo->prepare('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?');

        foreach ($built['lines'] as $line) {
            $itemIns->execute([
                $orderId,
                $line['product_id'],
                $line['product_name'],
                $line['sku'] !== '' ? $line['sku'] : null,
                $line['qty'],
                $line['unit_price'],
                $line['unit_bv'],
                $line['line_total'],
                $line['line_bv'],
            ]);
            $stockUp->execute([$line['qty'], $line['product_id'], $line['qty']]);
            if ($stockUp->rowCount() < 1) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Stock changed for ' . $line['product_name'] . '. Try again.', 'order_id' => null, 'invoice_no' => null, 'activated' => null, 'activation_error' => null, 'package_name' => null];
            }
        }

        $debit = wallet_debit(
            $pdo,
            $memberId,
            'shopping',
            $total,
            'product_order',
            $orderId,
            'Product order ' . $invoiceNo
        );
        if (!$debit['ok']) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $debit['error'] ?: 'Could not debit Shopping Wallet.', 'order_id' => null, 'invoice_no' => null, 'activated' => null, 'activation_error' => null, 'package_name' => null];
        }

        $pdo->prepare('UPDATE product_orders SET ledger_id = ? WHERE id = ?')
            ->execute([(int) ($debit['ledger_id'] ?? 0) ?: null, $orderId]);

        $pdo->commit();
        product_orders_cart_clear();

        $act = product_orders_try_activate($pdo, $member, $builtLines);

        return [
            'ok' => true,
            'error' => null,
            'order_id' => $orderId,
            'invoice_no' => $invoiceNo,
            'activated' => $act['activated'],
            'activation_error' => $act['error'],
            'package_name' => $act['package_name'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Checkout failed. Please try again.', 'order_id' => null, 'invoice_no' => null, 'activated' => null, 'activation_error' => null, 'package_name' => null];
    }
}

/**
 * Resolve which package a product should activate.
 * Prefer products.package_id; else highest-amount package from package_products.
 */
function product_orders_resolve_package_id(PDO $pdo, int $productId, $directPackageId = null): int
{
    $direct = (int) ($directPackageId ?? 0);
    if ($direct > 0) {
        return $direct;
    }
    if ($productId < 1) {
        return 0;
    }
    try {
        require_once __DIR__ . '/package_products.php';
        package_products_ensure_table($pdo);
        $stmt = $pdo->prepare("
            SELECT p.id
            FROM package_products pp
            INNER JOIN packages p ON p.id = pp.package_id AND p.status = 'active'
            WHERE pp.product_id = ?
            ORDER BY p.amount DESC, p.id ASC
            LIMIT 1
        ");
        $stmt->execute([$productId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Find or create an internal package matching product purchase value (for BV / income hooks).
 * Used in Product Only mode — not shown as a starter-plan catalog to members.
 * @return array<string,mixed>|null
 */
function product_orders_ensure_value_package(PDO $pdo, float $amount, float $bv = 0.0): ?array
{
    $amount = round(max(0.0, $amount), 2);
    if ($amount <= 0) {
        return null;
    }
    $bv = round($bv > 0 ? $bv : $amount, 2);

    try {
        // Ensure capping column exists (packages UI may be hidden in Product Only)
        static $cappingReady = false;
        if (!$cappingReady) {
            try {
                $col = $pdo->query("SHOW COLUMNS FROM packages LIKE 'capping'")->fetch();
                if (!$col) {
                    $pdo->exec("ALTER TABLE packages ADD COLUMN capping DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER bv");
                }
            } catch (Throwable $e) {
                // continue — INSERT without capping may still work on some schemas
            }
            $cappingReady = true;
        }

        $marker = 'Auto-created for Product Only activation';
        $name = 'Shop Activation ' . number_format($amount, 2, '.', '');

        // Prefer dedicated shop-activation package (do not hijack starter plans by amount)
        $stmt = $pdo->prepare("
            SELECT * FROM packages
            WHERE status = 'active'
              AND ROUND(amount, 2) = ?
              AND (name = ? OR description LIKE ?)
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([$amount, $name, '%' . $marker . '%']);
        $pkg = $stmt->fetch();
        if ($pkg) {
            if (abs(round((float) ($pkg['bv'] ?? 0), 2) - $bv) > 0.009) {
                $pdo->prepare('UPDATE packages SET bv = ? WHERE id = ?')->execute([$bv, (int) $pkg['id']]);
                $pkg['bv'] = $bv;
            }
            return $pkg;
        }

        try {
            $pdo->prepare("
                INSERT INTO packages (name, amount, bv, capping, daily_roi, validity_days, description, status)
                VALUES (?, ?, ?, 0, 0, 0, ?, 'active')
            ")->execute([
                $name,
                $amount,
                $bv,
                $marker . ' (value-matched).',
            ]);
        } catch (Throwable $e) {
            // Fallback without capping for older schemas
            $pdo->prepare("
                INSERT INTO packages (name, amount, bv, daily_roi, validity_days, description, status)
                VALUES (?, ?, ?, 0, 0, ?, 'active')
            ")->execute([
                $name,
                $amount,
                $bv,
                $marker . ' (value-matched).',
            ]);
        }
        $id = (int) $pdo->lastInsertId();
        if ($id < 1) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM packages WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $pkg = $stmt->fetch();
        return $pkg ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * After a paid order: activate member.
 * Product Only: any line with unit_price >= activation value qualifies (no package link required).
 * Otherwise: uses products.package_id / package_products link.
 * @param list<array<string,mixed>> $lines
 * @return array{activated:?bool,error:?string,package_name:?string}
 */
function product_orders_try_activate(PDO $pdo, array $member, array $lines): array
{
    if (!feature_enabled('feature_product_activates_package')) {
        return ['activated' => null, 'error' => null, 'package_name' => null];
    }
    if (!empty($member['package_id'])) {
        return ['activated' => null, 'error' => null, 'package_name' => null];
    }

    $minAmount = function_exists('product_activate_min_amount')
        ? product_activate_min_amount()
        : max(0.0, (float) setting('product_activate_min_amount', '0'));
    $productOnly = function_exists('feature_product_only_activation') && feature_product_only_activation();

    // —— Product Only: activate by product selling price (same/above set value) ——
    if ($productOnly) {
        $best = null;
        foreach ($lines as $line) {
            $unit = round((float) ($line['unit_price'] ?? 0), 2);
            if ($minAmount > 0 && $unit + 0.00001 < $minAmount) {
                continue;
            }
            if ($unit <= 0) {
                continue;
            }
            $lineBv = round((float) ($line['unit_bv'] ?? 0), 2);
            if ($best === null
                || $unit > (float) $best['unit']
                || ($unit === (float) $best['unit'] && $lineBv > (float) $best['bv'])
            ) {
                $best = [
                    'unit' => $unit,
                    'bv' => $lineBv > 0 ? $lineBv : $unit,
                    'name' => (string) ($line['product_name'] ?? 'Product'),
                ];
            }
        }
        if ($best === null) {
            return [
                'activated' => false,
                'error' => $minAmount > 0
                    ? 'Buy a product priced at least ' . strip_tags(currency($minAmount)) . ' to activate your ID.'
                    : 'No qualifying product in this order.',
                'package_name' => null,
            ];
        }

        $pkg = product_orders_ensure_value_package($pdo, (float) $best['unit'], (float) $best['bv']);
        if (!$pkg) {
            return ['activated' => false, 'error' => 'Could not prepare activation for this product value.', 'package_name' => null];
        }
        $res = activation_apply($pdo, $member, (int) $pkg['id']);
        if ($res['ok']) {
            return [
                'activated' => true,
                'error' => null,
                'package_name' => (string) ($best['name'] ?: ($res['package']['name'] ?? $pkg['name'])),
            ];
        }
        return [
            'activated' => false,
            'error' => $res['error'] ?: 'Activation failed after purchase.',
            'package_name' => null,
        ];
    }

    // —— Classic: product must be linked to a package ——
    $pkgIds = [];
    $sawQualifyingProduct = false;
    foreach ($lines as $line) {
        $unit = (float) ($line['unit_price'] ?? 0);
        if ($minAmount > 0 && $unit + 0.00001 < $minAmount) {
            continue;
        }
        $sawQualifyingProduct = true;
        $pid = product_orders_resolve_package_id(
            $pdo,
            (int) ($line['product_id'] ?? 0),
            $line['package_id'] ?? null
        );
        if ($pid > 0) {
            $pkgIds[$pid] = max($pkgIds[$pid] ?? 0.0, $unit);
        }
    }
    if (!$pkgIds) {
        if (!$sawQualifyingProduct && $minAmount > 0) {
            return [
                'activated' => false,
                'error' => 'Product price is below the minimum activation amount (' . strip_tags(currency($minAmount)) . ').',
                'package_name' => null,
            ];
        }
        return [
            'activated' => false,
            'error' => 'Purchased product is not linked to any package. Admin must set “Activates package” on the product (or assign it under Assign Product).',
            'package_name' => null,
        ];
    }

    $ids = array_keys($pkgIds);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, amount FROM packages WHERE id IN ($in) AND status = 'active'");
    $stmt->execute($ids);
    $pkgs = $stmt->fetchAll();
    if (!$pkgs) {
        return ['activated' => false, 'error' => 'Linked package is unavailable.', 'package_name' => null];
    }

    usort($pkgs, static function ($a, $b) {
        return (float) $b['amount'] <=> (float) $a['amount'];
    });
    $chosen = $pkgs[0];
    $res = activation_apply($pdo, $member, (int) $chosen['id']);
    if ($res['ok']) {
        return [
            'activated' => true,
            'error' => null,
            'package_name' => (string) ($res['package']['name'] ?? $chosen['name']),
        ];
    }

    return [
        'activated' => false,
        'error' => $res['error'] ?: 'Activation failed after purchase.',
        'package_name' => null,
    ];
}

function product_order_get(PDO $pdo, int $orderId, ?int $memberId = null): ?array
{
    product_orders_ensure_tables($pdo);
    $sql = 'SELECT * FROM product_orders WHERE id = ?';
    $params = [$orderId];
    if ($memberId !== null) {
        $sql .= ' AND member_id = ?';
        $params[] = $memberId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function product_order_items(PDO $pdo, int $orderId): array
{
    product_orders_ensure_tables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM product_order_items WHERE order_id = ? ORDER BY id ASC');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

function product_orders_for_member(PDO $pdo, int $memberId, int $limit = 50, int $offset = 0, string $q = ''): array
{
    product_orders_ensure_tables($pdo);
    $where = 'member_id = ?';
    $params = [$memberId];
    if ($q !== '') {
        $where .= ' AND invoice_no LIKE ?';
        $params[] = '%' . $q . '%';
    }
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $stmt = $pdo->prepare("
        SELECT * FROM product_orders
        WHERE {$where}
        ORDER BY id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function product_orders_count_for_member(PDO $pdo, int $memberId, string $q = ''): int
{
    product_orders_ensure_tables($pdo);
    $where = 'member_id = ?';
    $params = [$memberId];
    if ($q !== '') {
        $where .= ' AND invoice_no LIKE ?';
        $params[] = '%' . $q . '%';
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_orders WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function product_orders_stats(PDO $pdo, int $memberId): array
{
    product_orders_ensure_tables($pdo);
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS order_count,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) AS paid_total,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN total_bv ELSE 0 END), 0) AS paid_bv
        FROM product_orders
        WHERE member_id = ?
    ");
    $stmt->execute([$memberId]);
    $row = $stmt->fetch() ?: [];
    return [
        'order_count' => (int) ($row['order_count'] ?? 0),
        'paid_total' => (float) ($row['paid_total'] ?? 0),
        'paid_bv' => (float) ($row['paid_bv'] ?? 0),
    ];
}

/** Admin: paginated product orders across all members. */
function product_orders_admin_list(PDO $pdo, int $limit = 50, int $offset = 0, string $q = '', string $status = '', string $delivery = ''): array
{
    product_orders_ensure_tables($pdo);
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(o.invoice_no LIKE ? OR m.member_id LIKE ? OR m.full_name LIKE ? OR m.username LIKE ? OR o.tracking_no LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($status !== '' && in_array($status, ['pending', 'paid', 'cancelled', 'refunded'], true)) {
        $where[] = 'o.status = ?';
        $params[] = $status;
    }
    if ($delivery !== '' && isset(product_delivery_statuses()[$delivery])) {
        $where[] = 'o.delivery_status = ?';
        $params[] = $delivery;
    }
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $sql = '
        SELECT o.*, m.member_id AS mid, m.full_name, m.username, m.phone AS member_phone
        FROM product_orders o
        JOIN members m ON m.id = o.member_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY o.id DESC
        LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function product_orders_admin_count(PDO $pdo, string $q = '', string $status = '', string $delivery = ''): int
{
    product_orders_ensure_tables($pdo);
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(o.invoice_no LIKE ? OR m.member_id LIKE ? OR m.full_name LIKE ? OR m.username LIKE ? OR o.tracking_no LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($status !== '' && in_array($status, ['pending', 'paid', 'cancelled', 'refunded'], true)) {
        $where[] = 'o.status = ?';
        $params[] = $status;
    }
    if ($delivery !== '' && isset(product_delivery_statuses()[$delivery])) {
        $where[] = 'o.delivery_status = ?';
        $params[] = $delivery;
    }
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM product_orders o
        JOIN members m ON m.id = o.member_id
        WHERE ' . implode(' AND ', $where)
    );
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function product_orders_admin_stats(PDO $pdo): array
{
    product_orders_ensure_tables($pdo);
    $row = $pdo->query("
        SELECT
            COUNT(*) AS order_count,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) AS paid_total,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN total_bv ELSE 0 END), 0) AS paid_bv,
            COALESCE(SUM(CASE WHEN COALESCE(delivery_status, 'pending') = 'pending' THEN 1 ELSE 0 END), 0) AS pending_ship,
            COALESCE(SUM(CASE WHEN delivery_status IN ('shipped','out_for_delivery') THEN 1 ELSE 0 END), 0) AS in_transit,
            COALESCE(SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered_count
        FROM product_orders
    ")->fetch() ?: [];
    return [
        'order_count' => (int) ($row['order_count'] ?? 0),
        'paid_total' => (float) ($row['paid_total'] ?? 0),
        'paid_bv' => (float) ($row['paid_bv'] ?? 0),
        'pending_ship' => (int) ($row['pending_ship'] ?? 0),
        'in_transit' => (int) ($row['in_transit'] ?? 0),
        'delivered_count' => (int) ($row['delivered_count'] ?? 0),
    ];
}

function product_thumb_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return '../' . ltrim(str_replace('\\', '/', $path), '/');
}

function product_shipping_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS member_shipping_addresses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            label VARCHAR(40) NOT NULL DEFAULT 'Home',
            full_name VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            address_line TEXT NOT NULL,
            city VARCHAR(100) NOT NULL,
            state VARCHAR(100) NOT NULL,
            pincode VARCHAR(20) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_msa_member (member_id),
            KEY idx_msa_default (member_id, is_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

/** @return list<array<string,mixed>> */
function product_shipping_list(PDO $pdo, int $memberId): array
{
    product_shipping_ensure_table($pdo);
    $stmt = $pdo->prepare('
        SELECT * FROM member_shipping_addresses
        WHERE member_id = ?
        ORDER BY is_default DESC, id DESC
    ');
    $stmt->execute([$memberId]);
    return $stmt->fetchAll();
}

function product_shipping_get(PDO $pdo, int $addressId, int $memberId): ?array
{
    product_shipping_ensure_table($pdo);
    $stmt = $pdo->prepare('SELECT * FROM member_shipping_addresses WHERE id = ? AND member_id = ? LIMIT 1');
    $stmt->execute([$addressId, $memberId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @param array{label?:string,full_name:string,phone:string,address_line:string,city:string,state:string,pincode:string,is_default?:bool} $data
 * @return array{ok:bool,error:?string,id:?int}
 */
function product_shipping_save(PDO $pdo, int $memberId, array $data, int $addressId = 0): array
{
    product_shipping_ensure_table($pdo);
    $label = trim((string) ($data['label'] ?? 'Home'));
    if ($label === '') {
        $label = 'Home';
    }
    $name = trim((string) ($data['full_name'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $line = trim((string) ($data['address_line'] ?? ''));
    $city = trim((string) ($data['city'] ?? ''));
    $state = trim((string) ($data['state'] ?? ''));
    $pin = trim((string) ($data['pincode'] ?? ''));
    $isDefault = !empty($data['is_default']);

    if ($name === '' || $phone === '' || $line === '' || $city === '' || $state === '' || $pin === '') {
        return ['ok' => false, 'error' => 'Please fill all address fields.', 'id' => null];
    }

    try {
        $pdo->beginTransaction();
        if ($isDefault) {
            $pdo->prepare('UPDATE member_shipping_addresses SET is_default = 0 WHERE member_id = ?')
                ->execute([$memberId]);
        }

        $existing = product_shipping_list($pdo, $memberId);
        if (!$existing) {
            $isDefault = true;
        }

        if ($addressId > 0) {
            $row = product_shipping_get($pdo, $addressId, $memberId);
            if (!$row) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Address not found.', 'id' => null];
            }
            $pdo->prepare('
                UPDATE member_shipping_addresses
                SET label=?, full_name=?, phone=?, address_line=?, city=?, state=?, pincode=?, is_default=?
                WHERE id=? AND member_id=?
            ')->execute([$label, $name, $phone, $line, $city, $state, $pin, $isDefault ? 1 : 0, $addressId, $memberId]);
            $id = $addressId;
        } else {
            $pdo->prepare('
                INSERT INTO member_shipping_addresses
                    (member_id, label, full_name, phone, address_line, city, state, pincode, is_default)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([$memberId, $label, $name, $phone, $line, $city, $state, $pin, $isDefault ? 1 : 0]);
            $id = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
        return ['ok' => true, 'error' => null, 'id' => $id];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not save address.', 'id' => null];
    }
}

/** @return array{ok:bool,error:?string} */
function product_shipping_delete(PDO $pdo, int $memberId, int $addressId): array
{
    product_shipping_ensure_table($pdo);
    $row = product_shipping_get($pdo, $addressId, $memberId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Address not found.'];
    }
    $pdo->prepare('DELETE FROM member_shipping_addresses WHERE id = ? AND member_id = ?')
        ->execute([$addressId, $memberId]);
    if (!empty($row['is_default'])) {
        $next = $pdo->prepare('SELECT id FROM member_shipping_addresses WHERE member_id = ? ORDER BY id DESC LIMIT 1');
        $next->execute([$memberId]);
        $nid = (int) $next->fetchColumn();
        if ($nid > 0) {
            $pdo->prepare('UPDATE member_shipping_addresses SET is_default = 1 WHERE id = ? AND member_id = ?')
                ->execute([$nid, $memberId]);
        }
    }
    return ['ok' => true, 'error' => null];
}

function product_checkout_set_address(int $addressId): void
{
    $_SESSION['shop_checkout_address_id'] = $addressId > 0 ? $addressId : null;
}

function product_checkout_get_address_id(): int
{
    return (int) ($_SESSION['shop_checkout_address_id'] ?? 0);
}

function product_checkout_clear(): void
{
    unset($_SESSION['shop_checkout_address_id']);
}
