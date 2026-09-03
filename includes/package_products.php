<?php
/**
 * Package ↔ Product assignment helpers.
 */

function package_products_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS package_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            package_id INT NOT NULL,
            product_id INT NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_pkg_product (package_id, product_id),
            INDEX idx_pp_package (package_id),
            INDEX idx_pp_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

/**
 * @return list<array>
 */
function package_products_list(PDO $pdo, int $packageId): array
{
    package_products_ensure_table($pdo);
    if ($packageId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('
        SELECT pp.*, p.name AS product_name, p.sku, p.price AS current_price, p.status AS product_status
        FROM package_products pp
        JOIN products p ON p.id = pp.product_id
        WHERE pp.package_id = ?
        ORDER BY pp.sort_order ASC, pp.id ASC
    ');
    $stmt->execute([$packageId]);
    return $stmt->fetchAll();
}

/**
 * Replace all product rows for a package.
 * @param list<array{product_id:int,qty:int,unit_price:float}> $rows
 * @return array{ok:bool,error:?string,count:int,products_total:float,package_amount:float,discount_percent:float}
 */
function package_products_save(PDO $pdo, int $packageId, array $rows): array
{
    package_products_ensure_table($pdo);

    $pkg = $pdo->prepare('SELECT id, name, amount FROM packages WHERE id = ? LIMIT 1');
    $pkg->execute([$packageId]);
    $package = $pkg->fetch();
    if (!$package) {
        return [
            'ok' => false,
            'error' => 'Package not found.',
            'count' => 0,
            'products_total' => 0,
            'package_amount' => 0,
            'discount_percent' => 0,
        ];
    }

    $clean = [];
    $seen = [];
    foreach ($rows as $r) {
        $productId = (int) ($r['product_id'] ?? 0);
        $qty = (int) ($r['qty'] ?? 0);
        $unitPrice = (float) ($r['unit_price'] ?? 0);
        if ($productId <= 0 || $qty < 1) {
            continue;
        }
        if (isset($seen[$productId])) {
            return [
                'ok' => false,
                'error' => 'Same product cannot be added twice in one package.',
                'count' => 0,
                'products_total' => 0,
                'package_amount' => (float) $package['amount'],
                'discount_percent' => 0,
            ];
        }
        $seen[$productId] = true;

        $p = $pdo->prepare("SELECT id, price, status FROM products WHERE id = ? LIMIT 1");
        $p->execute([$productId]);
        $product = $p->fetch();
        if (!$product) {
            return [
                'ok' => false,
                'error' => 'One or more selected products were not found.',
                'count' => 0,
                'products_total' => 0,
                'package_amount' => (float) $package['amount'],
                'discount_percent' => 0,
            ];
        }
        if ($unitPrice <= 0) {
            $unitPrice = (float) $product['price'];
        }
        $clean[] = [
            'product_id' => $productId,
            'qty' => $qty,
            'unit_price' => $unitPrice,
        ];
    }

    $productsTotal = 0.0;
    foreach ($clean as $c) {
        $productsTotal += $c['unit_price'] * $c['qty'];
    }
    $packageAmount = (float) $package['amount'];
    $discount = 0.0;
    if ($productsTotal > 0 && $packageAmount < $productsTotal) {
        $discount = (($productsTotal - $packageAmount) / $productsTotal) * 100;
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM package_products WHERE package_id = ?')->execute([$packageId]);
        $ins = $pdo->prepare('
            INSERT INTO package_products (package_id, product_id, qty, unit_price, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ');
        foreach ($clean as $i => $c) {
            $ins->execute([$packageId, $c['product_id'], $c['qty'], $c['unit_price'], $i + 1]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'error' => 'Could not save package products.',
            'count' => 0,
            'products_total' => $productsTotal,
            'package_amount' => $packageAmount,
            'discount_percent' => $discount,
        ];
    }

    return [
        'ok' => true,
        'error' => null,
        'count' => count($clean),
        'products_total' => $productsTotal,
        'package_amount' => $packageAmount,
        'discount_percent' => $discount,
    ];
}

/**
 * Product counts for one or many packages.
 * @param list<int>|int $packageIds
 * @return array<int, array{product_count:int, total_qty:int}>
 */
function package_products_counts(PDO $pdo, $packageIds): array
{
    package_products_ensure_table($pdo);
    if (!is_array($packageIds)) {
        $packageIds = [(int) $packageIds];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $packageIds), static fn ($id) => $id > 0)));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT package_id,
               COUNT(*) AS product_count,
               COALESCE(SUM(qty), 0) AS total_qty
        FROM package_products
        WHERE package_id IN ($placeholders)
        GROUP BY package_id
    ");
    $stmt->execute($ids);
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = ['product_count' => 0, 'total_qty' => 0];
    }
    foreach ($stmt->fetchAll() as $row) {
        $pid = (int) $row['package_id'];
        $out[$pid] = [
            'product_count' => (int) $row['product_count'],
            'total_qty' => (int) $row['total_qty'],
        ];
    }
    return $out;
}
