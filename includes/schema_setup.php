<?php
/**
 * Shared schema installer: base tables + extra modules + stored procedures.
 */

function mlm_exec_sql_file(PDO $pdo, string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        return;
    }

    // Strip UTF-8 BOM (breaks MariaDB when before -- comments)
    if (strncmp($sql, "\xEF\xBB\xBF", 3) === 0) {
        $sql = substr($sql, 3);
    }
    $sql = preg_replace('/^\x{FEFF}/u', '', $sql) ?? $sql;

    $sql = preg_replace('/^\s*CREATE DATABASE\b.*?;\s*/im', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*USE\s+\S+;\s*/im', '', $sql) ?? $sql;

    // Remove SQL line comments (-- ...) and block comments
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;

    $parts = preg_split('/;\s*(?:\r\n|\n|\r|$)/', $sql) ?: [];
    foreach ($parts as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        try {
            $pdo->exec($stmt);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (
                stripos($msg, 'Duplicate') !== false
                || stripos($msg, 'already exists') !== false
            ) {
                continue;
            }
            throw $e;
        }
    }
}

/**
 * Required tables for a "complete" install (approx local set).
 * @return list<string>
 */
function mlm_expected_tables(): array
{
    return [
        'admins', 'packages', 'members', 'commissions', 'withdrawals', 'settings',
        'activity_logs', 'contact_inquiries', 'topup_pins', 'topup_pin_transfers',
        'countries', 'states', 'cities', 'banks', 'bank_accounts', 'deductions',
        'news', 'plans', 'package_plans',
        'product_categories', 'product_subcategories', 'product_sizes', 'product_colors',
        'subcategory_settings', 'products', 'product_images', 'product_vendors',
        'stock_purchases', 'stock_purchase_items', 'commodity_prices',
        'password_resets', 'member_kyc_documents', 'member_kyc_upi', 'activation_requests',
        'bv_credits', 'closing_runs', 'closing_items', 'package_products',
        'super_admins', 'withdrawal_payout_logs',
    ];
}

/**
 * @return array{missing: list<string>, existing: list<string>, complete: bool}
 */
function mlm_schema_status(PDO $pdo): array
{
    $existing = [];
    try {
        $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $r) {
            $existing[] = (string) $r[0];
        }
    } catch (Throwable $e) {
        // ignore
    }

    $existingLower = array_map('strtolower', $existing);
    $missing = [];
    foreach (mlm_expected_tables() as $t) {
        if (!in_array(strtolower($t), $existingLower, true)) {
            $missing[] = $t;
        }
    }

    return [
        'missing' => $missing,
        'existing' => $existing,
        'complete' => $missing === [],
    ];
}

/**
 * Create/update all tables + procedures. Safe to re-run.
 *
 * @return array{ok: bool, message: string, tables: int, procedures: int, created: list<string>}
 */
function mlm_run_schema_setup(PDO $pdo): array
{
    $before = mlm_schema_status($pdo);
    $hasAdmins = in_array('admins', array_map('strtolower', $before['existing']), true);

    $base = dirname(__DIR__) . '/sql/binarymlm_db_live.sql';
    if (!is_file($base)) {
        $base = dirname(__DIR__) . '/sql/binarymlm_db.sql';
    }
    if (!is_file($base) && !$hasAdmins) {
        throw new RuntimeException('SQL file missing: sql/binarymlm_db_live.sql');
    }

    // Base schema only when core table missing (avoids duplicate seed INSERT errors)
    if (!$hasAdmins && is_file($base)) {
        mlm_exec_sql_file($pdo, $base);
    }
    mlm_exec_sql_file($pdo, dirname(__DIR__) . '/sql/binarymlm_extra_tables_live.sql');

    // Runtime-created tables that may not be in older SQL dumps yet
    try {
        require_once dirname(__DIR__) . '/includes/withdrawal.php';
        wd_ensure_payout_log_table($pdo);
        wd_ensure_columns($pdo);
    } catch (Throwable $e) {
        // ignore
    }

    require_once dirname(__DIR__) . '/includes/procedures.php';
    ensure_mlm_procedures($pdo);

    // Ensure default admin can login (admin / admin123)
    $adminHash = password_hash('admin123', PASSWORD_DEFAULT);
    try {
        $row = $pdo->query("SELECT id FROM admins WHERE username = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare('UPDATE admins SET password = ?, status = ? WHERE id = ?')
                ->execute([$adminHash, 'active', $row['id']]);
        } else {
            $any = $pdo->query('SELECT id FROM admins ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if ($any) {
                $pdo->prepare('UPDATE admins SET username = ?, email = ?, password = ?, full_name = ?, status = ? WHERE id = ?')
                    ->execute(['admin', 'admin@binarymlm.com', $adminHash, 'Client Admin', 'active', $any['id']]);
            } else {
                $pdo->prepare('INSERT INTO admins (username, email, password, full_name, status) VALUES (?, ?, ?, ?, ?)')
                    ->execute(['admin', 'admin@binarymlm.com', $adminHash, 'Client Admin', 'active']);
            }
        }
    } catch (Throwable $e) {
        // tables may still be incomplete
    }

    // Super Admin + feature defaults for this install
    try {
        require_once dirname(__DIR__) . '/includes/features.php';
        feature_ensure_superadmin_table($pdo);
        feature_ensure_defaults($pdo);
    } catch (Throwable $e) {
        // ignore
    }

    $after = mlm_schema_status($pdo);
    $created = array_values(array_diff($before['missing'], $after['missing']));

    $procCount = 0;
    try {
        $procs = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE()")->fetchAll();
        $procCount = count($procs);
    } catch (Throwable $e) {
        $procCount = 0;
    }

    $msg = $after['complete']
        ? 'Database setup complete. Super Admin: superadmin / superadmin123 · Client Admin: admin / admin123'
        : ('Setup ran, but still missing: ' . implode(', ', $after['missing']) . '. Re-run setup from Super Admin login.');

    return [
        'ok' => $after['complete'],
        'message' => $msg,
        'tables' => count($after['existing']),
        'procedures' => $procCount,
        'created' => $created,
    ];
}
