<?php
/**
 * Multi-wallet helpers: Income / Topup / Shopping.
 * Income wallet maps to existing members.wallet_balance.
 */

function wallet_types(): array
{
    return [
        'income' => [
            'key' => 'income',
            'label' => 'Income Wallet',
            'short' => 'Income',
            'column' => 'wallet_balance',
            'page' => 'wallet-income.php',
            'desc' => 'Commission earnings. Withdraw or transfer to other wallets.',
            'tone' => 'green',
        ],
        'topup' => [
            'key' => 'topup',
            'label' => 'Topup Wallet',
            'short' => 'Topup',
            'column' => 'topup_wallet_balance',
            'page' => 'wallet-topup.php',
            'desc' => 'Add money (UTR proof), activate members, or transfer to Shopping.',
            'tone' => 'blue',
        ],
        'shopping' => [
            'key' => 'shopping',
            'label' => 'Shopping Wallet',
            'short' => 'Shopping',
            'column' => 'shopping_wallet_balance',
            'page' => 'wallet-shopping.php',
            'desc' => 'Spendable balance for product / shopping purchases.',
            'tone' => 'purple',
        ],
    ];
}

/** Wallet cards / nav types enabled for this install. */
function wallet_types_enabled(): array
{
    $all = wallet_types();
    $out = ['income' => $all['income']];
    if (function_exists('feature_module_allowed') && feature_module_allowed('wallet_topup')) {
        $out['topup'] = $all['topup'];
    }
    if (function_exists('feature_module_allowed') && feature_module_allowed('products')) {
        $out['shopping'] = $all['shopping'];
    }
    return $out;
}

function wallet_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    foreach ([
        'topup_wallet_balance' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER wallet_balance',
        'shopping_wallet_balance' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER topup_wallet_balance',
    ] as $col => $def) {
        try {
            $chk = $pdo->query('SHOW COLUMNS FROM members LIKE ' . $pdo->quote($col));
            if ($chk && !$chk->fetch()) {
                $pdo->exec("ALTER TABLE members ADD COLUMN {$col} {$def}");
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS wallet_ledger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                wallet_type ENUM('income','topup','shopping') NOT NULL,
                direction ENUM('credit','debit') NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                balance_after DECIMAL(12,2) NOT NULL DEFAULT 0,
                ref_type VARCHAR(50) NULL,
                ref_id INT NULL,
                note VARCHAR(255) NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_wl_member (member_id),
                KEY idx_wl_wallet (wallet_type),
                KEY idx_wl_created (created_at),
                KEY idx_wl_ref (ref_type, ref_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS wallet_transfers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                from_wallet ENUM('income','topup','shopping') NOT NULL,
                to_wallet ENUM('income','topup','shopping') NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                note VARCHAR(255) NULL,
                status ENUM('success','failed') NOT NULL DEFAULT 'success',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_wt_member (member_id),
                KEY idx_wt_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    $done = true;
}

function wallet_column(string $type): ?string
{
    $types = wallet_types();
    return $types[$type]['column'] ?? null;
}

function wallet_label(string $type): string
{
    $types = wallet_types();
    return $types[$type]['label'] ?? ucfirst($type);
}

function wallet_get_balances(PDO $pdo, int $memberId): array
{
    wallet_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT wallet_balance, topup_wallet_balance, shopping_wallet_balance FROM members WHERE id = ? LIMIT 1');
    $stmt->execute([$memberId]);
    $row = $stmt->fetch() ?: [];
    return [
        'income' => (float) ($row['wallet_balance'] ?? 0),
        'topup' => (float) ($row['topup_wallet_balance'] ?? 0),
        'shopping' => (float) ($row['shopping_wallet_balance'] ?? 0),
    ];
}

function wallet_balance(PDO $pdo, int $memberId, string $type): float
{
    $all = wallet_get_balances($pdo, $memberId);
    return (float) ($all[$type] ?? 0);
}

/**
 * Credit a wallet and write ledger row. Caller may wrap in transaction.
 * @return array{ok:bool,error:?string,balance:float,ledger_id:?int}
 */
function wallet_credit(PDO $pdo, int $memberId, string $type, float $amount, string $refType = 'manual', ?int $refId = null, ?string $note = null, ?int $createdBy = null): array
{
    wallet_ensure_schema($pdo);
    $col = wallet_column($type);
    if (!$col) {
        return ['ok' => false, 'error' => 'Invalid wallet type.', 'balance' => 0, 'ledger_id' => null];
    }
    $amount = round($amount, 2);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Amount must be greater than zero.', 'balance' => wallet_balance($pdo, $memberId, $type), 'ledger_id' => null];
    }

    try {
        $pdo->prepare("UPDATE members SET {$col} = {$col} + ? WHERE id = ?")->execute([$amount, $memberId]);
        if ($type === 'income') {
            try {
                $pdo->prepare('UPDATE members SET total_earnings = total_earnings + ? WHERE id = ?')->execute([$amount, $memberId]);
            } catch (Throwable $e) {
                // ignore if column missing
            }
        }
        $bal = wallet_balance($pdo, $memberId, $type);
        $pdo->prepare('INSERT INTO wallet_ledger (member_id, wallet_type, direction, amount, balance_after, ref_type, ref_id, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$memberId, $type, 'credit', $amount, $bal, $refType, $refId, $note, $createdBy]);
        $ledgerId = (int) $pdo->lastInsertId();
        return ['ok' => true, 'error' => null, 'balance' => $bal, 'ledger_id' => $ledgerId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not credit wallet.', 'balance' => wallet_balance($pdo, $memberId, $type), 'ledger_id' => null];
    }
}

/**
 * Debit a wallet and write ledger row.
 * @return array{ok:bool,error:?string,balance:float,ledger_id:?int}
 */
function wallet_debit(PDO $pdo, int $memberId, string $type, float $amount, string $refType = 'manual', ?int $refId = null, ?string $note = null, ?int $createdBy = null): array
{
    wallet_ensure_schema($pdo);
    $col = wallet_column($type);
    if (!$col) {
        return ['ok' => false, 'error' => 'Invalid wallet type.', 'balance' => 0, 'ledger_id' => null];
    }
    $amount = round($amount, 2);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Amount must be greater than zero.', 'balance' => wallet_balance($pdo, $memberId, $type), 'ledger_id' => null];
    }

    $bal = wallet_balance($pdo, $memberId, $type);
    if ($bal + 0.00001 < $amount) {
        return ['ok' => false, 'error' => 'Insufficient ' . wallet_label($type) . ' balance.', 'balance' => $bal, 'ledger_id' => null];
    }

    try {
        $stmt = $pdo->prepare("UPDATE members SET {$col} = {$col} - ? WHERE id = ? AND {$col} >= ?");
        $stmt->execute([$amount, $memberId, $amount]);
        if ($stmt->rowCount() < 1) {
            return ['ok' => false, 'error' => 'Insufficient ' . wallet_label($type) . ' balance.', 'balance' => wallet_balance($pdo, $memberId, $type), 'ledger_id' => null];
        }
        $newBal = wallet_balance($pdo, $memberId, $type);
        $pdo->prepare('INSERT INTO wallet_ledger (member_id, wallet_type, direction, amount, balance_after, ref_type, ref_id, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$memberId, $type, 'debit', $amount, $newBal, $refType, $refId, $note, $createdBy]);
        $ledgerId = (int) $pdo->lastInsertId();
        return ['ok' => true, 'error' => null, 'balance' => $newBal, 'ledger_id' => $ledgerId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not debit wallet.', 'balance' => wallet_balance($pdo, $memberId, $type), 'ledger_id' => null];
    }
}

/**
 * Transfer between own wallets.
 * Allowed: income → topup, income → shopping, topup → shopping.
 * @return array{ok:bool,error:?string,transfer_id:?int}
 */
function wallet_transfer(PDO $pdo, int $memberId, string $from, string $to, float $amount, ?string $note = null): array
{
    wallet_ensure_schema($pdo);
    $amount = round($amount, 2);
    $allowed = [
        'income' => ['topup', 'shopping'],
        'topup' => ['shopping'],
        'shopping' => [],
    ];
    if ($from === $to) {
        return ['ok' => false, 'error' => 'Select two different wallets.', 'transfer_id' => null];
    }
    if (!isset($allowed[$from]) || !in_array($to, $allowed[$from], true)) {
        return ['ok' => false, 'error' => 'This wallet transfer direction is not allowed.', 'transfer_id' => null];
    }
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Enter a valid transfer amount.', 'transfer_id' => null];
    }

    try {
        $pdo->beginTransaction();
        $debit = wallet_debit($pdo, $memberId, $from, $amount, 'transfer_out', null, $note ?: ('Transfer to ' . wallet_label($to)));
        if (!$debit['ok']) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $debit['error'], 'transfer_id' => null];
        }
        $credit = wallet_credit($pdo, $memberId, $to, $amount, 'transfer_in', null, $note ?: ('Transfer from ' . wallet_label($from)));
        if (!$credit['ok']) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $credit['error'], 'transfer_id' => null];
        }
        $pdo->prepare('INSERT INTO wallet_transfers (member_id, from_wallet, to_wallet, amount, note, status) VALUES (?,?,?,?,?,\'success\')')
            ->execute([$memberId, $from, $to, $amount, $note]);
        $tid = (int) $pdo->lastInsertId();
        // Link ledger refs
        if (!empty($debit['ledger_id'])) {
            $pdo->prepare("UPDATE wallet_ledger SET ref_id = ? WHERE id = ?")->execute([$tid, $debit['ledger_id']]);
        }
        if (!empty($credit['ledger_id'])) {
            $pdo->prepare("UPDATE wallet_ledger SET ref_id = ? WHERE id = ?")->execute([$tid, $credit['ledger_id']]);
        }
        $pdo->commit();
        return ['ok' => true, 'error' => null, 'transfer_id' => $tid];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Transfer failed. Please try again.', 'transfer_id' => null];
    }
}

/**
 * @return list<array>
 */
function wallet_ledger_rows(PDO $pdo, int $memberId, ?string $type = null, int $limit = 50, int $offset = 0): array
{
    wallet_ensure_schema($pdo);
    $sql = 'SELECT * FROM wallet_ledger WHERE member_id = ?';
    $params = [$memberId];
    if ($type && isset(wallet_types()[$type])) {
        $sql .= ' AND wallet_type = ?';
        $params[] = $type;
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function wallet_ledger_count(PDO $pdo, int $memberId, ?string $type = null): int
{
    wallet_ensure_schema($pdo);
    $sql = 'SELECT COUNT(*) FROM wallet_ledger WHERE member_id = ?';
    $params = [$memberId];
    if ($type && isset(wallet_types()[$type])) {
        $sql .= ' AND wallet_type = ?';
        $params[] = $type;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * @return list<array>
 */
function wallet_transfer_rows(PDO $pdo, int $memberId, int $limit = 20): array
{
    wallet_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM wallet_transfers WHERE member_id = ? ORDER BY id DESC LIMIT ' . (int) $limit);
    $stmt->execute([$memberId]);
    return $stmt->fetchAll() ?: [];
}

function wallet_ref_label(string $refType): string
{
    return match ($refType) {
        'commission' => 'Commission',
        'withdrawal' => 'Withdrawal',
        'transfer_in' => 'Transfer In',
        'transfer_out' => 'Transfer Out',
        'admin_credit' => 'Admin Credit',
        'admin_debit' => 'Admin Debit',
        'activation' => 'Activation',
        'activation_refund' => 'Activation Refund',
        'topup_request' => 'Topup Request',
        'shopping' => 'Shopping',
        'product_order' => 'Product Order',
        'manual' => 'Manual',
        default => ucwords(str_replace('_', ' ', $refType)),
    };
}

/**
 * Available income for withdrawal (income wallet − pending withdrawal requests).
 */
function wallet_income_available(PDO $pdo, array $user): float
{
    require_once __DIR__ . '/withdrawal.php';
    return wd_available_balance($pdo, $user);
}
