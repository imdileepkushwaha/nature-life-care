<?php
/**
 * Franchisee Master — types, franchisees, purchases, stock.
 */

function franchise_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $sqlFile = dirname(__DIR__) . '/sql/franchise_tables.sql';
    if (!is_file($sqlFile)) {
        return;
    }

    $sql = (string) file_get_contents($sqlFile);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $parts = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($parts as $stmt) {
        if ($stmt === '') {
            continue;
        }
        try {
            $pdo->exec($stmt);
        } catch (Throwable $e) {
            // ignore already-exists / FK timing on partial installs
        }
    }

    // Extra columns for wizard registration (safe on older installs)
    $cols = [
        'sponsor_id' => 'INT NULL AFTER type_id',
        'wallet_balance' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER status',
        'gender' => "VARCHAR(20) NULL AFTER contact_person",
        'dob' => 'DATE NULL AFTER gender',
        'aadhaar_no' => 'VARCHAR(20) NULL AFTER email',
        'pan_no' => 'VARCHAR(20) NULL AFTER aadhaar_no',
        'gst_no' => 'VARCHAR(30) NULL AFTER pan_no',
        'aadhaar_file' => 'VARCHAR(255) NULL AFTER pan_no',
        'pan_file' => 'VARCHAR(255) NULL AFTER aadhaar_file',
        'photo_file' => 'VARCHAR(255) NULL AFTER pan_file',
        'gst_file' => 'VARCHAR(255) NULL AFTER photo_file',
    ];
    foreach ($cols as $col => $def) {
        try {
            $exists = $pdo->query('SHOW COLUMNS FROM franchisees LIKE ' . $pdo->quote($col))->fetch();
            if (!$exists) {
                $pdo->exec("ALTER TABLE franchisees ADD COLUMN `$col` $def");
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Extra columns for franchisee_types (hierarchy & commissions)
    $typeCols = [
        'commission_percent' => 'DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER description',
        'hierarchy_level' => 'INT NULL DEFAULT NULL AFTER commission_percent',
        'can_create_types' => 'VARCHAR(255) NULL AFTER hierarchy_level',
    ];
    foreach ($typeCols as $col => $def) {
        try {
            $exists = $pdo->query('SHOW COLUMNS FROM franchisee_types LIKE ' . $pdo->quote($col))->fetch();
            if (!$exists) {
                $pdo->exec("ALTER TABLE franchisee_types ADD COLUMN `$col` $def");
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Create franchisee_commissions table if not exists
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS franchisee_commissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                franchisee_id INT NOT NULL,
                source_franchisee_id INT NOT NULL,
                sale_id INT NOT NULL,
                bill_no VARCHAR(60) NOT NULL,
                bill_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                commission_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_fc_franchisee (franchisee_id),
                KEY idx_fc_source (source_franchisee_id),
                KEY idx_fc_sale (sale_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    // Seed standard types only once if table is empty and not yet seeded
    try {
        $seeded = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'franchise_types_seeded'")->fetchColumn();
        if ($seeded !== '1') {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM franchisee_types')->fetchColumn();
            if ($count === 0) {
                $defaultTypes = [
                    ['name' => 'BHEO', 'code' => 'BHEO', 'desc' => 'Business Head / Top Tier Franchise', 'comm' => 0.00, 'level' => 1, 'can_create' => 'SDIST,DIST,RETAIL'],
                    ['name' => 'Super Distributer', 'code' => 'SDIST', 'desc' => 'Super Distributor (10% Commission)', 'comm' => 10.00, 'level' => 2, 'can_create' => 'DIST,RETAIL'],
                    ['name' => 'Distributer', 'code' => 'DIST', 'desc' => 'Distributor (5% Commission)', 'comm' => 5.00, 'level' => 3, 'can_create' => 'RETAIL'],
                    ['name' => 'Retailer', 'code' => 'RETAIL', 'desc' => 'Retailer Counter / Billing Point', 'comm' => 0.00, 'level' => 4, 'can_create' => ''],
                ];
                $ins = $pdo->prepare('INSERT INTO franchisee_types (name, code, description, commission_percent, hierarchy_level, can_create_types, status) VALUES (?,?,?,?,?,?,"active")');
                foreach ($defaultTypes as $dt) {
                    $ins->execute([$dt['name'], $dt['code'], $dt['desc'], $dt['comm'], $dt['level'], $dt['can_create']]);
                }
            }
            $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('franchise_types_seeded', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** Lookup franchisee by code for sponsor field. */
function franchise_find_by_code(PDO $pdo, string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT f.id, f.franchisee_code, f.name, f.status, t.name AS type_name
        FROM franchisees f
        LEFT JOIN franchisee_types t ON t.id = f.type_id
        WHERE f.franchisee_code = ?
        LIMIT 1
    ');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Store franchisee document upload.
 * @return array{ok:bool,path?:?string,error?:string}
 */
function franchise_store_doc(array $file, string $kind, int $franchiseeId = 0): array
{
    if (empty($file['name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }
    if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed for ' . $kind . '.'];
    }
    $max = 2 * 1024 * 1024;
    if ((int) ($file['size'] ?? 0) > $max) {
        return ['ok' => false, 'error' => ucfirst($kind) . ' must be under 2MB.'];
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    if (!in_array($ext, $allowed, true)) {
        return ['ok' => false, 'error' => ucfirst($kind) . ': use JPG, PNG, WebP or PDF.'];
    }
    $dir = dirname(__DIR__) . '/uploads/franchisee';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not create upload folder.'];
    }
    $name = $kind . '_' . ($franchiseeId > 0 ? $franchiseeId . '_' : '') . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save ' . $kind . ' file.'];
    }
    return ['ok' => true, 'path' => 'uploads/franchisee/' . $name];
}

function franchise_doc_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }
    return '../' . ltrim($path, '/');
}

function franchise_next_code(PDO $pdo, string $prefix = 'FR'): string
{
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix) ?: 'FR');
    $stmt = $pdo->query("SELECT franchisee_code FROM franchisees WHERE franchisee_code LIKE " . $pdo->quote($prefix . '%') . " ORDER BY id DESC LIMIT 1");
    $last = $stmt ? (string) $stmt->fetchColumn() : '';
    $n = 1;
    if (preg_match('/(\d+)$/', $last, $m)) {
        $n = (int) $m[1] + 1;
    }
    return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/** @return list<array<string,mixed>> */
function franchise_types(PDO $pdo, bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM franchisee_types';
    if ($activeOnly) {
        $sql .= " WHERE status='active'";
    }
    $sql .= ' ORDER BY hierarchy_level ASC, name ASC';
    return $pdo->query($sql)->fetchAll();
}

/** @return list<array<string,mixed>> */
function franchise_list(PDO $pdo, array $filters = []): array
{
    $where = ['1=1'];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'f.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['type_id'])) {
        $where[] = 'f.type_id = ?';
        $params[] = (int) $filters['type_id'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(f.name LIKE ? OR f.franchisee_code LIKE ? OR f.phone LIKE ? OR f.email LIKE ? OR f.city LIKE ?)';
        $q = '%' . $filters['q'] . '%';
        array_push($params, $q, $q, $q, $q, $q);
    }
    $sql = '
        SELECT f.*, t.name AS type_name
        FROM franchisees f
        LEFT JOIN franchisee_types t ON t.id = f.type_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY f.id DESC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function franchise_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT f.*, t.name AS type_name, t.code AS type_code,
               COALESCE(t.commission_percent, 0) AS commission_percent,
               COALESCE(t.hierarchy_level, 4) AS hierarchy_level,
               t.can_create_types,
               s.franchisee_code AS sponsor_code, s.name AS sponsor_name, st.name AS sponsor_type_name
        FROM franchisees f
        LEFT JOIN franchisee_types t ON t.id = f.type_id
        LEFT JOIN franchisees s ON s.id = f.sponsor_id
        LEFT JOIN franchisee_types st ON st.id = s.type_id
        WHERE f.id = ?
        LIMIT 1
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Save franchisee purchase: debit company stock, credit franchisee stock.
 *
 * @param list<array{product_id:int,qty:int,rate:float}> $items
 * @return array{ok:bool,id?:int,error?:string}
 */
function franchise_save_purchase(PDO $pdo, int $franchiseeId, string $purchaseDate, string $invoiceNo, string $note, array $items, string $role = 'admin', ?int $actorId = null): array
{
    if ($franchiseeId < 1) {
        return ['ok' => false, 'error' => 'Select a franchisee.'];
    }
    if ($purchaseDate === '') {
        return ['ok' => false, 'error' => 'Purchase date is required.'];
    }
    if (!$items) {
        return ['ok' => false, 'error' => 'Add at least one product line.'];
    }

    $fr = franchise_get($pdo, $franchiseeId);
    if (!$fr || ($fr['status'] ?? '') !== 'active') {
        return ['ok' => false, 'error' => 'Franchisee not found or inactive.'];
    }

    $total = 0.0;
    foreach ($items as $it) {
        $total += (float) $it['amount'];
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare('
            INSERT INTO franchisee_purchases
                (franchisee_id, invoice_no, purchase_date, total_amount, note, status, created_by_role, created_by_id)
            VALUES (?,?,?,?,?,?,?,?)
        ')->execute([
            $franchiseeId,
            $invoiceNo !== '' ? $invoiceNo : null,
            $purchaseDate,
            round($total, 2),
            $note !== '' ? $note : null,
            'completed',
            in_array($role, ['admin', 'superadmin'], true) ? $role : 'admin',
            $actorId,
        ]);
        $purchaseId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare('
            INSERT INTO franchisee_purchase_items (purchase_id, product_id, qty, rate, amount)
            VALUES (?,?,?,?,?)
        ');
        $stockCheck = $pdo->prepare('SELECT id, name, stock_qty FROM products WHERE id = ? FOR UPDATE');
        $stockDebit = $pdo->prepare('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?');
        $stockCredit = $pdo->prepare('
            INSERT INTO franchisee_stock (franchisee_id, product_id, qty)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
        ');

        foreach ($items as $it) {
            $pid = (int) $it['product_id'];
            $qty = (int) $it['qty'];
            $rate = (float) $it['rate'];
            $amount = (float) $it['amount'];

            $stockCheck->execute([$pid]);
            $prod = $stockCheck->fetch();
            if (!$prod) {
                throw new RuntimeException('Product #' . $pid . ' not found.');
            }
            if ((int) $prod['stock_qty'] < $qty) {
                throw new RuntimeException('Insufficient company stock for ' . $prod['name'] . ' (have ' . (int) $prod['stock_qty'] . ').');
            }

            $itemStmt->execute([$purchaseId, $pid, $qty, $rate, $amount]);
            $stockDebit->execute([$qty, $pid, $qty]);
            if ($stockDebit->rowCount() < 1) {
                throw new RuntimeException('Could not debit stock for ' . $prod['name'] . '.');
            }
            $stockCredit->execute([$franchiseeId, $pid, $qty]);
        }

        $pdo->commit();
        log_activity('franchise_purchase', "Franchisee purchase #$purchaseId for FR#$franchiseeId total $total");
        return ['ok' => true, 'id' => $purchaseId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** @return list<array<string,mixed>> */
function franchise_purchases(PDO $pdo, array $filters = []): array
{
    $where = ['1=1'];
    $params = [];
    if (!empty($filters['franchisee_id'])) {
        $where[] = 'p.franchisee_id = ?';
        $params[] = (int) $filters['franchisee_id'];
    }
    if (!empty($filters['from'])) {
        $where[] = 'p.purchase_date >= ?';
        $params[] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $where[] = 'p.purchase_date <= ?';
        $params[] = $filters['to'];
    }
    $sql = '
        SELECT p.*, f.name AS franchisee_name, f.franchisee_code
        FROM franchisee_purchases p
        JOIN franchisees f ON f.id = p.franchisee_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY p.id DESC
        LIMIT 500
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function franchise_purchase_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT p.*, f.name AS franchisee_name, f.franchisee_code
        FROM franchisee_purchases p
        JOIN franchisees f ON f.id = p.franchisee_id
        WHERE p.id = ?
        LIMIT 1
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return list<array<string,mixed>> */
function franchise_purchase_items(PDO $pdo, int $purchaseId): array
{
    $stmt = $pdo->prepare('
        SELECT i.*, pr.name AS product_name, pr.sku
        FROM franchisee_purchase_items i
        JOIN products pr ON pr.id = i.product_id
        WHERE i.purchase_id = ?
        ORDER BY i.id
    ');
    $stmt->execute([$purchaseId]);
    return $stmt->fetchAll();
}

/** @return list<array<string,mixed>> */
function franchise_stock_rows(PDO $pdo, ?int $franchiseeId = null): array
{
    $where = 's.qty > 0';
    $params = [];
    if ($franchiseeId && $franchiseeId > 0) {
        $where = 's.franchisee_id = ?';
        $params[] = $franchiseeId;
    }
    $sql = '
        SELECT s.*, f.name AS franchisee_name, f.franchisee_code,
               pr.name AS product_name, pr.sku, pr.price
        FROM franchisee_stock s
        JOIN franchisees f ON f.id = s.franchisee_id
        JOIN products pr ON pr.id = s.product_id
        WHERE ' . $where . '
        ORDER BY f.name, pr.name
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Record a sale / product issue from franchisee to a member. */
function franchise_record_sale(PDO $pdo, int $franchiseeId, int $memberId, string $saleDate, array $items, string $paymentMode = 'cash', string $note = ''): array
{
    if (!$items) {
        return ['ok' => false, 'error' => 'Add at least one product item.'];
    }
    $fr = franchise_get($pdo, $franchiseeId);
    if (!$fr || ($fr['status'] ?? '') !== 'active') {
        return ['ok' => false, 'error' => 'Franchisee account is not active.'];
    }

    $memStmt = $pdo->prepare('SELECT id, member_id, full_name, phone, status FROM members WHERE id = ? LIMIT 1');
    $memStmt->execute([$memberId]);
    $member = $memStmt->fetch();
    if (!$member) {
        return ['ok' => false, 'error' => 'Member not found.'];
    }

    $billNo = 'FRB-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
    $totalAmount = 0.0;
    foreach ($items as $it) {
        $totalAmount += (float) ($it['amount'] ?? 0);
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare('
            INSERT INTO franchisee_sales
                (franchisee_id, member_id, bill_no, sale_date, total_amount, payment_mode, note, status)
            VALUES (?,?,?,?,?,?,?,?)
        ')->execute([
            $franchiseeId,
            $memberId,
            $billNo,
            $saleDate ?: date('Y-m-d'),
            round($totalAmount, 2),
            $paymentMode,
            $note !== '' ? $note : null,
            'completed'
        ]);
        $saleId = (int) $pdo->lastInsertId();

        $insItem = $pdo->prepare('
            INSERT INTO franchisee_sale_items (sale_id, product_id, qty, rate, amount)
            VALUES (?,?,?,?,?)
        ');
        $checkStock = $pdo->prepare('SELECT qty FROM franchisee_stock WHERE franchisee_id = ? AND product_id = ? FOR UPDATE');
        $debitStock = $pdo->prepare('UPDATE franchisee_stock SET qty = qty - ? WHERE franchisee_id = ? AND product_id = ? AND qty >= ?');

        foreach ($items as $it) {
            $pid = (int) $it['product_id'];
            $qty = (int) $it['qty'];
            $rate = (float) $it['rate'];
            $amount = (float) $it['amount'];

            if ($qty <= 0) {
                continue;
            }

            $checkStock->execute([$franchiseeId, $pid]);
            $currentQty = (int) ($checkStock->fetchColumn() ?: 0);
            if ($currentQty < $qty) {
                throw new RuntimeException("Insufficient stock for Product #$pid (Available: $currentQty, Requested: $qty).");
            }

            $insItem->execute([$saleId, $pid, $qty, $rate, $amount]);
            $debitStock->execute([$qty, $franchiseeId, $pid, $qty]);
            if ($debitStock->rowCount() < 1) {
                throw new RuntimeException("Could not debit stock for Product #$pid.");
            }
        }

        // Distribute multi-tier franchise commissions up the sponsor chain
        franchise_distribute_commission($pdo, $saleId, $franchiseeId, $billNo, (float) $totalAmount);

        $pdo->commit();
        log_activity('franchise_sale', "Franchisee #$franchiseeId billed Member {$member['member_id']} Bill #$billNo total $totalAmount");
        return ['ok' => true, 'id' => $saleId, 'bill_no' => $billNo];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** @return list<array<string,mixed>> */
function franchise_sales(PDO $pdo, array $filters = []): array
{
    $where = ['1=1'];
    $params = [];
    if (!empty($filters['franchisee_id'])) {
        $where[] = 's.franchisee_id = ?';
        $params[] = (int) $filters['franchisee_id'];
    }
    if (!empty($filters['member_id'])) {
        $where[] = 's.member_id = ?';
        $params[] = (int) $filters['member_id'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(s.bill_no LIKE ? OR m.member_id LIKE ? OR m.full_name LIKE ?)';
        $q = '%' . $filters['q'] . '%';
        array_push($params, $q, $q, $q);
    }
    if (!empty($filters['from'])) {
        $where[] = 's.sale_date >= ?';
        $params[] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $where[] = 's.sale_date <= ?';
        $params[] = $filters['to'];
    }

    $sql = '
        SELECT s.*, m.member_id AS member_code, m.full_name AS member_name, m.phone AS member_phone,
               f.name AS franchisee_name, f.franchisee_code
        FROM franchisee_sales s
        JOIN members m ON m.id = s.member_id
        JOIN franchisees f ON f.id = s.franchisee_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY s.id DESC
        LIMIT 500
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function franchise_sale_get(PDO $pdo, int $id, ?int $franchiseeId = null): ?array
{
    $sql = '
        SELECT s.*, m.member_id AS member_code, m.full_name AS member_name, m.phone AS member_phone,
               m.email AS member_email,
               f.name AS franchisee_name, f.franchisee_code, f.phone AS franchisee_phone, f.address AS franchisee_address,
               f.gst_no AS franchisee_gst
        FROM franchisee_sales s
        JOIN members m ON m.id = s.member_id
        JOIN franchisees f ON f.id = s.franchisee_id
        WHERE s.id = ?
    ';
    $params = [$id];
    if ($franchiseeId && $franchiseeId > 0) {
        $sql .= ' AND s.franchisee_id = ?';
        $params[] = $franchiseeId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return list<array<string,mixed>> */
function franchise_sale_items(PDO $pdo, int $saleId): array
{
    $stmt = $pdo->prepare('
        SELECT i.*, pr.name AS product_name, pr.sku
        FROM franchisee_sale_items i
        JOIN products pr ON pr.id = i.product_id
        WHERE i.sale_id = ?
        ORDER BY i.id
    ');
    $stmt->execute([$saleId]);
    return $stmt->fetchAll();
}

/** Summary statistics for franchise dashboard */
function franchise_stat_summary(PDO $pdo, int $franchiseeId): array
{
    $stockStmt = $pdo->prepare('
        SELECT COALESCE(SUM(s.qty), 0) AS total_units,
               COALESCE(SUM(s.qty * pr.price), 0) AS total_stock_value,
               COUNT(s.product_id) AS distinct_products
        FROM franchisee_stock s
        JOIN products pr ON pr.id = s.product_id
        WHERE s.franchisee_id = ? AND s.qty > 0
    ');
    $stockStmt->execute([$franchiseeId]);
    $stock = $stockStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $purStmt = $pdo->prepare('
        SELECT COUNT(*) AS total_invoices,
               COALESCE(SUM(total_amount), 0) AS total_purchase_amount
        FROM franchisee_purchases
        WHERE franchisee_id = ? AND status = "completed"
    ');
    $purStmt->execute([$franchiseeId]);
    $pur = $purStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $saleStmt = $pdo->prepare('
        SELECT COUNT(*) AS total_sales,
               COALESCE(SUM(total_amount), 0) AS total_sales_amount
        FROM franchisee_sales
        WHERE franchisee_id = ? AND status = "completed"
    ');
    $saleStmt->execute([$franchiseeId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $commStmt = $pdo->prepare('
        SELECT COALESCE(SUM(commission_amount), 0) AS total_commission
        FROM franchisee_commissions
        WHERE franchisee_id = ?
    ');
    $commStmt->execute([$franchiseeId]);
    $totalComm = (float) ($commStmt->fetchColumn() ?: 0);

    $wStmt = $pdo->prepare('SELECT COALESCE(wallet_balance, 0) FROM franchisees WHERE id = ?');
    $wStmt->execute([$franchiseeId]);
    $walletBal = (float) ($wStmt->fetchColumn() ?: 0);

    $teamStmt = $pdo->prepare('SELECT COUNT(*) FROM franchisees WHERE sponsor_id = ?');
    $teamStmt->execute([$franchiseeId]);
    $teamCount = (int) ($teamStmt->fetchColumn() ?: 0);

    return [
        'stock_units' => (int) ($stock['total_units'] ?? 0),
        'stock_value' => (float) ($stock['total_stock_value'] ?? 0),
        'products_count' => (int) ($stock['distinct_products'] ?? 0),
        'purchase_invoices' => (int) ($pur['total_invoices'] ?? 0),
        'purchase_amount' => (float) ($pur['total_purchase_amount'] ?? 0),
        'sales_count' => (int) ($sale['total_sales'] ?? 0),
        'sales_amount' => (float) ($sale['total_sales_amount'] ?? 0),
        'commission_total' => $totalComm,
        'wallet_balance' => $walletBal,
        'team_count' => $teamCount,
    ];
}

/**
 * Distribute multi-tier commissions up the franchise hierarchy when a bill is generated.
 */
function franchise_distribute_commission(PDO $pdo, int $saleId, int $sellerFranchiseeId, string $billNo, float $billAmount): void
{
    if ($billAmount <= 0) {
        return;
    }

    $seller = franchise_get($pdo, $sellerFranchiseeId);
    if (!$seller) {
        return;
    }

    $insComm = $pdo->prepare('
        INSERT INTO franchisee_commissions 
            (franchisee_id, source_franchisee_id, sale_id, bill_no, bill_amount, commission_percent, commission_amount)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $addWallet = $pdo->prepare('
        UPDATE franchisees 
        SET wallet_balance = COALESCE(wallet_balance, 0) + ? 
        WHERE id = ?
    ');

    // 1. Direct seller commission (if their own type has commission > 0)
    $sellerPercent = (float) ($seller['commission_percent'] ?? 0);
    if ($sellerPercent > 0) {
        $commAmount = round($billAmount * ($sellerPercent / 100), 2);
        if ($commAmount > 0) {
            $insComm->execute([
                $sellerFranchiseeId,
                $sellerFranchiseeId,
                $saleId,
                $billNo,
                $billAmount,
                $sellerPercent,
                $commAmount
            ]);
            $addWallet->execute([$commAmount, $sellerFranchiseeId]);
        }
    }

    // 2. Traverse up the sponsor chain (Distributor -> Super Distributor -> BHEO)
    $currentSponsorId = (int) ($seller['sponsor_id'] ?? 0);
    $visited = [$sellerFranchiseeId];
    $maxLevels = 10;
    $level = 0;

    while ($currentSponsorId > 0 && $level < $maxLevels) {
        $level++;
        if (in_array($currentSponsorId, $visited, true)) {
            break;
        }
        $visited[] = $currentSponsorId;

        $parent = franchise_get($pdo, $currentSponsorId);
        if (!$parent || ($parent['status'] ?? '') !== 'active') {
            break;
        }

        $parentPercent = (float) ($parent['commission_percent'] ?? 0);
        if ($parentPercent > 0) {
            $parentComm = round($billAmount * ($parentPercent / 100), 2);
            if ($parentComm > 0) {
                $insComm->execute([
                    $currentSponsorId,
                    $sellerFranchiseeId,
                    $saleId,
                    $billNo,
                    $billAmount,
                    $parentPercent,
                    $parentComm
                ]);
                $addWallet->execute([$parentComm, $currentSponsorId]);
            }
        }

        $currentSponsorId = (int) ($parent['sponsor_id'] ?? 0);
    }
}

/**
 * Return allowed child types that a franchise can create.
 * @return list<array<string,mixed>>
 */
function franchise_allowed_child_types(PDO $pdo, int $hierarchyLevel, ?string $canCreateCodes = null): array
{
    $all = franchise_types($pdo, true);
    if ($canCreateCodes !== null && trim($canCreateCodes) !== '') {
        $allowedCodes = array_map('trim', explode(',', strtoupper($canCreateCodes)));
        return array_values(array_filter($all, static function ($t) use ($allowedCodes) {
            return in_array(strtoupper((string) ($t['code'] ?? '')), $allowedCodes, true);
        }));
    }

    return array_values(array_filter($all, static function ($t) use ($hierarchyLevel) {
        return (int) ($t['hierarchy_level'] ?? 4) > $hierarchyLevel;
    }));
}

/**
 * Get commissions earned by a franchisee.
 * @return list<array<string,mixed>>
 */
function franchise_commissions(PDO $pdo, array $filters = []): array
{
    $where = ['1=1'];
    $params = [];
    if (!empty($filters['franchisee_id'])) {
        $where[] = 'c.franchisee_id = ?';
        $params[] = (int) $filters['franchisee_id'];
    }
    if (!empty($filters['source_franchisee_id'])) {
        $where[] = 'c.source_franchisee_id = ?';
        $params[] = (int) $filters['source_franchisee_id'];
    }

    $sql = '
        SELECT c.*,
               f.franchisee_code AS earner_code, f.name AS earner_name,
               sf.franchisee_code AS source_code, sf.name AS source_name,
               sft.name AS source_type_name
        FROM franchisee_commissions c
        JOIN franchisees f ON f.id = c.franchisee_id
        JOIN franchisees sf ON sf.id = c.source_franchisee_id
        LEFT JOIN franchisee_types sft ON sft.id = sf.type_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY c.id DESC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get downline franchisees of a given franchisee.
 * @return list<array<string,mixed>>
 */
function franchise_downline(PDO $pdo, int $franchiseeId): array
{
    $stmt = $pdo->prepare('
        SELECT f.*, t.name AS type_name, t.code AS type_code,
               COALESCE(t.commission_percent, 0) AS commission_percent,
               COALESCE(t.hierarchy_level, 4) AS hierarchy_level,
               s.franchisee_code AS sponsor_code, s.name AS sponsor_name
        FROM franchisees f
        LEFT JOIN franchisee_types t ON t.id = f.type_id
        LEFT JOIN franchisees s ON s.id = f.sponsor_id
        WHERE f.sponsor_id = ?
        ORDER BY f.id DESC
    ');
    $stmt->execute([$franchiseeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Franchise Password Reset Functions
 */
function franchise_pw_find_for_reset(PDO $pdo, string $login, string $verify): ?array
{
    $login = trim($login);
    $verify = trim($verify);
    if ($login === '' || $verify === '') {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT f.*, t.name AS type_name
        FROM franchisees f
        LEFT JOIN franchisee_types t ON t.id = f.type_id
        WHERE (f.franchisee_code = ? OR f.username = ? OR f.email = ?)
          AND f.status = "active"
        LIMIT 1
    ');
    $stmt->execute([$login, $login, $login]);
    $fr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fr) {
        return null;
    }

    // Verify phone, email, PAN, or GST (case-insensitive)
    $cleanVerify = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $verify));
    $cleanPhone = preg_replace('/[^0-9]/', '', (string) ($fr['phone'] ?? ''));
    $cleanEmail = strtolower(trim((string) ($fr['email'] ?? '')));
    $cleanPan = strtoupper(trim((string) ($fr['pan_no'] ?? '')));
    $cleanGst = strtoupper(trim((string) ($fr['gst_no'] ?? '')));

    $matched = false;
    if ($cleanPhone !== '' && str_ends_with($cleanPhone, preg_replace('/[^0-9]/', '', $verify))) {
        $matched = true;
    } elseif ($cleanEmail !== '' && strtolower($verify) === $cleanEmail) {
        $matched = true;
    } elseif ($cleanPan !== '' && $cleanVerify === $cleanPan) {
        $matched = true;
    } elseif ($cleanGst !== '' && $cleanVerify === $cleanGst) {
        $matched = true;
    }

    return $matched ? $fr : null;
}

function franchise_pw_create_token(PDO $pdo, int $franchiseeId): string
{
    try {
        $pdo->prepare('UPDATE franchise_password_resets SET used_at = NOW() WHERE franchisee_id = ? AND used_at IS NULL')
            ->execute([$franchiseeId]);
    } catch (Throwable $e) {}

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $pdo->prepare('INSERT INTO franchise_password_resets (franchisee_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
        ->execute([$franchiseeId, $hash]);

    return $token;
}

function franchise_pw_find_valid_token(PDO $pdo, string $token): ?array
{
    if (strlen($token) < 32) {
        return null;
    }
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare('
        SELECT r.id AS reset_id, r.franchisee_id, r.expires_at, r.used_at,
               f.franchisee_code, f.username, f.name, f.phone, f.email, f.status
        FROM franchise_password_resets r
        JOIN franchisees f ON f.id = r.franchisee_id
        WHERE r.token_hash = ?
        LIMIT 1
    ');
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    if (!empty($row['used_at'])) {
        return null;
    }
    if (strtotime($row['expires_at']) < time()) {
        return null;
    }
    if (($row['status'] ?? '') !== 'active') {
        return null;
    }
    return $row;
}

function franchise_pw_complete_reset(PDO $pdo, int $resetId, int $franchiseeId, string $newPassword): bool
{
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE franchisees SET password = ? WHERE id = ?')->execute([$hash, $franchiseeId]);
    $pdo->prepare('UPDATE franchise_password_resets SET used_at = NOW() WHERE id = ?')->execute([$resetId]);
    log_activity('franchise_password_reset', "Password reset completed for franchisee #$franchiseeId");
    return true;
}


