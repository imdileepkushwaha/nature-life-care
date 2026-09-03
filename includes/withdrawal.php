<?php
/**
 * User withdrawal helpers
 */

function wd_assert_enabled(): bool
{
    return feature_enabled('feature_withdrawals_enabled');
}

function wd_ensure_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $cols = [
        'tds_amount' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount",
        'fee_amount' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER tds_amount",
        'other_deduction' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER fee_amount",
        'net_amount' => "DECIMAL(12,2) NULL AFTER other_deduction",
    ];
    foreach ($cols as $name => $def) {
        try {
            $check = $pdo->query("SHOW COLUMNS FROM withdrawals LIKE " . $pdo->quote($name));
            if ($check && !$check->fetch()) {
                $pdo->exec("ALTER TABLE withdrawals ADD COLUMN {$name} {$def}");
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    // Backfill net for old rows
    try {
        $pdo->exec("UPDATE withdrawals SET net_amount = ROUND(amount - tds_amount - fee_amount - other_deduction, 2) WHERE net_amount IS NULL");
    } catch (Throwable $e) {
        // ignore
    }
    $done = true;
}

function wd_pending_sum(PDO $pdo, int $memberId): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE member_id = ? AND status = 'pending'");
    $stmt->execute([$memberId]);
    return (float) $stmt->fetchColumn();
}

function wd_pending_count(PDO $pdo, int $memberId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE member_id = ? AND status = 'pending'");
    $stmt->execute([$memberId]);
    return (int) $stmt->fetchColumn();
}

function wd_available_balance(PDO $pdo, array $user): float
{
    $wallet = (float) ($user['wallet_balance'] ?? 0);
    $pending = wd_pending_sum($pdo, (int) $user['id']);
    return max(0, $wallet - $pending);
}

function wd_min_amount(): float
{
    return max(0, (float) setting('min_withdrawal', '500'));
}

function wd_max_amount(): float
{
    return max(0, (float) setting('max_withdrawal', '0'));
}

function wd_tds_percent(): float
{
    return max(0.0, (float) setting('tds_deduction_percent', '5'));
}

function wd_fee_percent(): float
{
    return max(0.0, (float) setting('processing_fee_percent', '1'));
}

/**
 * Calculate TDS, processing fee, other deductions, and net payout.
 *
 * @return array{
 *   gross:float,tds_percent:float,fee_percent:float,
 *   tds_amount:float,fee_amount:float,other_deduction:float,net_amount:float,
 *   lines:array<int,array{name:string,amount:float}>
 * }
 */
function wd_calc_breakdown(PDO $pdo, float $gross): array
{
    wd_ensure_columns($pdo);
    $gross = round(max(0, $gross), 2);
    $tdsPct = wd_tds_percent();
    $feePct = wd_fee_percent();
    $tds = round($gross * $tdsPct / 100, 2);
    $fee = round($gross * $feePct / 100, 2);
    $other = 0.0;
    $otherPct = 0.0;
    $otherFixed = 0.0;
    $lines = [];

    if ($tds > 0) {
        $lines[] = ['name' => 'TDS (' . rtrim(rtrim(number_format($tdsPct, 2, '.', ''), '0'), '.') . '%)', 'amount' => $tds];
    }
    if ($fee > 0) {
        $lines[] = ['name' => 'Admin charges (' . rtrim(rtrim(number_format($feePct, 2, '.', ''), '0'), '.') . '%)', 'amount' => $fee];
    }

    try {
        $rows = $pdo->query("SELECT name, deduction_type, value FROM deductions WHERE status = 'active' ORDER BY id ASC")->fetchAll();
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $lower = strtolower($name);
            // Avoid double-counting with settings TDS / processing fee
            if ($tdsPct > 0 && (str_contains($lower, 'tds') || str_contains($lower, 'tax'))) {
                continue;
            }
            if ($feePct > 0 && (str_contains($lower, 'processing') || str_contains($lower, 'admin charge') || str_contains($lower, 'admin charges') || $lower === 'admin')) {
                continue;
            }
            $val = (float) ($row['value'] ?? 0);
            if ($val <= 0) {
                continue;
            }
            if (($row['deduction_type'] ?? '') === 'fixed') {
                $amt = round($val, 2);
                $otherFixed = round($otherFixed + $amt, 2);
            } else {
                $amt = round($gross * $val / 100, 2);
                $otherPct = round($otherPct + $val, 4);
            }
            if ($amt <= 0) {
                continue;
            }
            $other = round($other + $amt, 2);
            $lines[] = ['name' => $name !== '' ? $name : 'Deduction', 'amount' => $amt];
        }
    } catch (Throwable $e) {
        // deductions table may not exist
    }

    $net = round(max(0, $gross - $tds - $fee - $other), 2);

    return [
        'gross' => $gross,
        'tds_percent' => $tdsPct,
        'fee_percent' => $feePct,
        'tds_amount' => $tds,
        'fee_amount' => $fee,
        'other_deduction' => $other,
        'other_percent' => $otherPct,
        'other_fixed' => $otherFixed,
        'net_amount' => $net,
        'lines' => $lines,
    ];
}

function wd_net_display(array $row): float
{
    if (isset($row['net_amount']) && $row['net_amount'] !== null && $row['net_amount'] !== '') {
        return (float) $row['net_amount'];
    }
    $gross = (float) ($row['amount'] ?? 0);
    $tds = (float) ($row['tds_amount'] ?? 0);
    $fee = (float) ($row['fee_amount'] ?? 0);
    $other = (float) ($row['other_deduction'] ?? 0);
    return round(max(0, $gross - $tds - $fee - $other), 2);
}

function wd_status_pill(string $status): string
{
    $s = strtolower($status);
    $cls = match ($s) {
        'pending' => 'is-wait',
        'approved' => 'is-ok',
        'paid' => 'is-ok',
        'rejected' => 'is-bad',
        default => 'is-muted',
    };
    return '<span class="wd-pill ' . $cls . '">' . e(ucfirst($s)) . '</span>';
}

/** True when Super Admin requires approved KYC before withdrawal requests. */
function wd_require_kyc(): bool
{
    return feature_enabled('feature_kyc_enabled')
        && feature_enabled('feature_withdraw_require_kyc', false);
}

/**
 * Member may request withdrawal under KYC gate.
 * @return array{ok:bool,status:string,message:string}
 */
function wd_kyc_gate_check(PDO $pdo, int $memberId): array
{
    if (!wd_require_kyc()) {
        return ['ok' => true, 'status' => '', 'message' => ''];
    }
    $status = 'not_submitted';
    try {
        $stmt = $pdo->prepare('SELECT kyc_status FROM members WHERE id = ? LIMIT 1');
        $stmt->execute([$memberId]);
        $status = strtolower(trim((string) ($stmt->fetchColumn() ?: 'not_submitted')));
    } catch (Throwable $e) {
        $status = 'not_submitted';
    }
    if ($status === 'approved') {
        return ['ok' => true, 'status' => $status, 'message' => ''];
    }
    $label = function_exists('kyc_status_label') ? kyc_status_label($status) : ucfirst($status);
    return [
        'ok' => false,
        'status' => $status,
        'message' => 'Complete and get KYC approved before requesting a withdrawal. Current status: ' . $label . '.',
    ];
}

/** Prefill bank details from approved KYC bank doc if available. */
function wd_kyc_bank_prefills(PDO $pdo, int $memberId): ?array
{
    if (!function_exists('ensure_kyc_documents_table')) {
        return null;
    }
    try {
        ensure_kyc_documents_table($pdo);
        $stmt = $pdo->prepare("SELECT * FROM member_kyc_documents WHERE member_id = ? AND doc_type = 'bank' AND status = 'approved' LIMIT 1");
        $stmt->execute([$memberId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $lines = array_filter([
            !empty($row['account_holder']) ? 'Holder: ' . $row['account_holder'] : null,
            !empty($row['account_number']) ? 'A/C: ' . $row['account_number'] : null,
            !empty($row['ifsc_code']) ? 'IFSC: ' . $row['ifsc_code'] : null,
            !empty($row['bank_name']) ? 'Bank: ' . $row['bank_name'] : null,
            !empty($row['branch_name']) ? 'Branch: ' . $row['branch_name'] : null,
        ]);
        return [
            'method' => 'Bank Transfer',
            'details' => implode("\n", $lines),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/** Append-only payout audit trail (never update/delete from app). */
function wd_ensure_payout_log_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS withdrawal_payout_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                withdrawal_id INT UNSIGNED NOT NULL,
                member_id INT UNSIGNED NOT NULL,
                admin_id INT UNSIGNED NULL,
                event_type VARCHAR(20) NOT NULL,
                gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                net_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                tds_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                other_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
                payment_method VARCHAR(80) NULL,
                account_details TEXT NULL,
                payout_ref VARCHAR(120) NULL,
                admin_note TEXT NULL,
                ip_address VARCHAR(45) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_wpl_withdrawal (withdrawal_id),
                KEY idx_wpl_member (member_id),
                KEY idx_wpl_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }
    $done = true;
}

/**
 * Immutable insert — do not expose UPDATE/DELETE for this table.
 *
 * @param array{
 *   withdrawal_id:int,member_id:int,event_type:string,gross?:float,net?:float,
 *   tds?:float,fee?:float,other?:float,payment_method?:?string,account_details?:?string,
 *   payout_ref?:?string,admin_note?:?string,admin_id?:?int
 * } $data
 */
function wd_payout_log_append(PDO $pdo, array $data): void
{
    wd_ensure_payout_log_table($pdo);
    $event = strtolower(trim((string) ($data['event_type'] ?? '')));
    if (!in_array($event, ['approve', 'reject', 'paid'], true)) {
        return;
    }
    try {
        $pdo->prepare("
            INSERT INTO withdrawal_payout_logs
                (withdrawal_id, member_id, admin_id, event_type, gross_amount, net_amount,
                 tds_amount, fee_amount, other_deduction, payment_method, account_details,
                 payout_ref, admin_note, ip_address)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            (int) ($data['withdrawal_id'] ?? 0),
            (int) ($data['member_id'] ?? 0),
            isset($data['admin_id']) ? (int) $data['admin_id'] : ($_SESSION['admin_id'] ?? null),
            $event,
            round((float) ($data['gross'] ?? 0), 2),
            round((float) ($data['net'] ?? 0), 2),
            round((float) ($data['tds'] ?? 0), 2),
            round((float) ($data['fee'] ?? 0), 2),
            round((float) ($data['other'] ?? 0), 2),
            $data['payment_method'] ?? null,
            $data['account_details'] ?? null,
            $data['payout_ref'] ?? null,
            $data['admin_note'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // never block payout flow on log failure
    }
}

/** Build log payload from a withdrawals row. */
function wd_payout_log_from_row(array $wd, string $event, string $note = '', string $payoutRef = ''): array
{
    return [
        'withdrawal_id' => (int) ($wd['id'] ?? 0),
        'member_id' => (int) ($wd['member_id'] ?? 0),
        'event_type' => $event,
        'gross' => (float) ($wd['amount'] ?? 0),
        'net' => wd_net_display($wd),
        'tds' => (float) ($wd['tds_amount'] ?? 0),
        'fee' => (float) ($wd['fee_amount'] ?? 0),
        'other' => (float) ($wd['other_deduction'] ?? 0),
        'payment_method' => $wd['payment_method'] ?? null,
        'account_details' => $wd['account_details'] ?? null,
        'payout_ref' => $payoutRef !== '' ? $payoutRef : null,
        'admin_note' => $note !== '' ? $note : ($wd['admin_note'] ?? null),
    ];
}

/** @return list<array<string,mixed>> */
function wd_payout_logs_recent(PDO $pdo, int $limit = 40): array
{
    wd_ensure_payout_log_table($pdo);
    $limit = max(1, min(200, $limit));
    try {
        return $pdo->query("
            SELECT l.*, a.username AS admin_username, m.full_name, m.member_id AS mid
            FROM withdrawal_payout_logs l
            LEFT JOIN admins a ON a.id = l.admin_id
            LEFT JOIN members m ON m.id = l.member_id
            ORDER BY l.id DESC
            LIMIT {$limit}
        ")->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
