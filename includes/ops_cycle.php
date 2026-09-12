<?php
/**
 * Operations & Payment cycle:
 * - Daily ledger recording (wallet_ledger)
 * - Binary closing: daily at 00:00 IST (pending pairs flush once per day)
 * - Weekly reconciliation snapshot (reporting only)
 * - Verified bank payout window (default: every Saturday)
 *
 * Timezone: Asia/Kolkata. Week = Sunday 00:00 → Saturday 23:59.
 */

require_once __DIR__ . '/closing.php';
require_once __DIR__ . '/withdrawal.php';

function ops_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Kolkata');
}

function ops_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', ops_timezone());
}

function ops_parse_date(string $ymd): DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, ops_timezone());
    if (!$d) {
        return ops_now();
    }
    return $d->setTime(12, 0, 0);
}

/**
 * Operational week containing $when (default now).
 * @return array{
 *   start:DateTimeImmutable,end:DateTimeImmutable,
 *   start_date:string,end_date:string,label:string
 * }
 */
function ops_week_for(?DateTimeImmutable $when = null): array
{
    $when = $when ?? ops_now();
    $w = (int) $when->format('w'); // 0 Sun … 6 Sat
    $start = $when->modify('-' . $w . ' days')->setTime(0, 0, 0);
    $end = $start->modify('+6 days')->setTime(23, 59, 59);
    return [
        'start' => $start,
        'end' => $end,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
        'label' => $start->format('d M Y') . ' → ' . $end->format('d M Y'),
    ];
}

function ops_save_setting(PDO $pdo, string $key, string $val): void
{
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $val]);
    if (function_exists('clear_setting_cache')) {
        clear_setting_cache($key);
    }
}

function ops_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weekly_reconciliations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            week_start DATE NOT NULL,
            week_end DATE NOT NULL,
            ledger_credits DECIMAL(14,2) NOT NULL DEFAULT 0,
            ledger_debits DECIMAL(14,2) NOT NULL DEFAULT 0,
            commissions_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
            closing_binary_net DECIMAL(14,2) NOT NULL DEFAULT 0,
            closing_matching DECIMAL(14,2) NOT NULL DEFAULT 0,
            withdrawals_requested DECIMAL(14,2) NOT NULL DEFAULT 0,
            withdrawals_approved_net DECIMAL(14,2) NOT NULL DEFAULT 0,
            withdrawals_paid_net DECIMAL(14,2) NOT NULL DEFAULT 0,
            tds_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            admin_charges DECIMAL(14,2) NOT NULL DEFAULT 0,
            joins_count INT NOT NULL DEFAULT 0,
            closing_id INT NULL,
            closing_done TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('open','reconciled') NOT NULL DEFAULT 'open',
            admin_id INT NULL,
            notes VARCHAR(255) NULL,
            snapshot_json MEDIUMTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_week_end (week_end),
            KEY idx_wr_start (week_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ops_cron_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job VARCHAR(40) NOT NULL,
            ok TINYINT(1) NOT NULL DEFAULT 1,
            message VARCHAR(500) NULL,
            payload_json TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ops_cron_job (job, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $defaults = [
        'ops_weekly_closing_enabled' => '1',
        'ops_closing_time' => '00:00',
        'ops_payout_window_enabled' => '1',
        'ops_payout_window_days' => '6',
    ];
    foreach ($defaults as $key => $val) {
        try {
            $chk = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
            $chk->execute([$key]);
            if (!$chk->fetch()) {
                $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $val]);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Force daily-only closing (no weekly closing day).
    try {
        $chk = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ops_schedule_v3' LIMIT 1");
        $chk->execute();
        $row = $chk->fetch();
        if (!$row) {
            ops_save_setting($pdo, 'ops_closing_time', '00:00');
            ops_save_setting($pdo, 'ops_weekly_closing_enabled', '1');
            ops_save_setting($pdo, 'ops_payout_window_enabled', '1');
            ops_save_setting($pdo, 'ops_payout_window_days', '6');
            ops_save_setting($pdo, 'ops_closing_mode', 'daily');
            ops_save_setting($pdo, 'ops_schedule_v2', '1');
            ops_save_setting($pdo, 'ops_schedule_v3', '1');
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $chk = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ops_cron_secret' LIMIT 1");
        $chk->execute();
        $row = $chk->fetch();
        if (!$row || trim((string) $row['setting_value']) === '') {
            ops_save_setting($pdo, 'ops_cron_secret', bin2hex(random_bytes(16)));
        }
    } catch (Throwable $e) {
        // ignore
    }

    $done = true;
}

function ops_cron_secret(): string
{
    return trim((string) setting('ops_cron_secret', ''));
}

function ops_weekly_closing_enabled(): bool
{
    return setting('ops_weekly_closing_enabled', '1') === '1';
}

function ops_payout_window_enabled(): bool
{
    return setting('ops_payout_window_enabled', '1') === '1';
}

/** Closing is always daily. Kept for older call sites. */
function ops_closing_mode(): string
{
    return 'daily';
}

function ops_is_daily_closing(): bool
{
    return true;
}

/** HH:MM in Asia/Kolkata. Default midnight. */
function ops_closing_time(): string
{
    $raw = trim((string) setting('ops_closing_time', '00:00'));
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $raw, $m)) {
        return '00:00';
    }
    return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
}

function ops_closing_time_parts(): array
{
    [$h, $i] = array_map('intval', explode(':', ops_closing_time()));
    return [$h, $i];
}

function ops_closing_schedule_label(): string
{
    $t = ops_closing_time();
    if ($t === '00:00') {
        return 'Daily at 12:00 AM IST';
    }
    return 'Daily at ' . date('g:i A', strtotime('2000-01-01 ' . $t)) . ' IST';
}

/** ISO-8601 date('N'): 1=Mon … 7=Sun. Default Saturday. */
function ops_payout_weekdays(): array
{
    $raw = strtolower(str_replace(' ', '', (string) setting('ops_payout_window_days', '6')));
    $out = [];
    foreach (explode(',', $raw) as $p) {
        $n = (int) $p;
        if ($n >= 1 && $n <= 7) {
            $out[] = $n;
        }
    }
    return $out !== [] ? array_values(array_unique($out)) : [6];
}

function ops_weekday_name(int $w, bool $iso = false): string
{
    if ($iso) {
        $map = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        return $map[$w] ?? '—';
    }
    $map = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
    return $map[$w] ?? '—';
}

/** Every calendar day is a closing day. */
function ops_is_closing_day(?DateTimeImmutable $when = null): bool
{
    return true;
}

function ops_is_payout_day(?DateTimeImmutable $when = null): bool
{
    $when = $when ?? ops_now();
    return in_array((int) $when->format('N'), ops_payout_weekdays(), true);
}

function ops_next_closing_day(?DateTimeImmutable $when = null): DateTimeImmutable
{
    $when = $when ?? ops_now();
    [$h, $i] = ops_closing_time_parts();
    $todayAt = $when->setTime($h, $i, 0);
    if ($when < $todayAt) {
        return $todayAt;
    }
    return $when->modify('+1 day')->setTime($h, $i, 0);
}

function ops_next_payout_day(?DateTimeImmutable $when = null): DateTimeImmutable
{
    $when = ($when ?? ops_now())->setTime(10, 0, 0);
    $days = ops_payout_weekdays();
    for ($i = 0; $i < 8; $i++) {
        $d = $when->modify('+' . $i . ' days');
        if (in_array((int) $d->format('N'), $days, true)) {
            return $d->setTime(10, 0, 0);
        }
    }
    return $when;
}

function ops_payout_days_label(): string
{
    $names = array_map(static fn ($n) => ops_weekday_name((int) $n, true), ops_payout_weekdays());
    return implode('–', $names);
}

/**
 * @return array{ok:bool,already:bool,run:?array,message:string}
 */
function ops_day_closing_status(PDO $pdo, ?string $ymd = null): array
{
    ops_ensure_tables($pdo);
    closing_ensure_tables($pdo);
    $ymd = $ymd ?: ops_now()->format('Y-m-d');
    try {
        $st = $pdo->prepare("
            SELECT * FROM closing_runs
            WHERE DATE(created_at) = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$ymd]);
        $run = $st->fetch() ?: null;
        if ($run) {
            return [
                'ok' => true,
                'already' => true,
                'run' => $run,
                'message' => 'Daily closing recorded on ' . date('d M Y H:i', strtotime((string) $run['created_at'])) . '.',
            ];
        }
    } catch (Throwable $e) {
        // ignore
    }
    return ['ok' => true, 'already' => false, 'run' => null, 'message' => 'No daily closing for ' . date('d M Y', strtotime($ymd)) . ' yet.'];
}

/**
 * Week view helper for reconciliation (any closing in the week).
 *
 * @return array{ok:bool,already:bool,run:?array,message:string}
 */
function ops_week_closing_status(PDO $pdo, string $weekEnd): array
{
    ops_ensure_tables($pdo);
    closing_ensure_tables($pdo);
    try {
        $st = $pdo->prepare("
            SELECT * FROM closing_runs
            WHERE DATE(created_at) BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$weekEnd, $weekEnd]);
        $run = $st->fetch() ?: null;
        if ($run) {
            return [
                'ok' => true,
                'already' => true,
                'run' => $run,
                'message' => 'Closing recorded on ' . date('d M Y H:i', strtotime((string) $run['created_at'])) . '.',
            ];
        }
    } catch (Throwable $e) {
        // ignore
    }
    return ['ok' => true, 'already' => false, 'run' => null, 'message' => 'No closing for this week yet.'];
}

/**
 * Active schedule status for gates / banners (always daily).
 *
 * @return array{ok:bool,already:bool,run:?array,message:string}
 */
function ops_active_closing_status(PDO $pdo): array
{
    return ops_day_closing_status($pdo);
}

/**
 * @return array{ok:bool,message:string,override:bool}
 */
function ops_closing_gate(PDO $pdo, bool $override = false): array
{
    ops_ensure_tables($pdo);

    if ($override) {
        return ['ok' => true, 'message' => 'Override confirmed.', 'override' => true];
    }

    if (!ops_weekly_closing_enabled()) {
        return ['ok' => true, 'message' => 'Closing schedule lock is off.', 'override' => false];
    }

    $st = ops_active_closing_status($pdo);
    if ($st['already']) {
        return [
            'ok' => false,
            'message' => $st['message'] . ' Type CLOSE OVERRIDE to run an extra closing.',
            'override' => false,
        ];
    }

    $next = ops_next_closing_day();
    return [
        'ok' => true,
        'message' => 'Daily closing is open. Auto-run at ' . $next->format('g:i A') . ' IST (pending pairs close daily-wise).',
        'override' => false,
    ];
}

/**
 * @return array{ok:bool,message:string,payout_day:string,next:?string}
 */
function ops_payout_gate(): array
{
    $label = ops_payout_days_label();
    if (!ops_payout_window_enabled()) {
        return [
            'ok' => true,
            'message' => 'Payout day lock is off — requests allowed any day.',
            'payout_day' => $label,
            'next' => null,
        ];
    }
    if (ops_is_payout_day()) {
        return [
            'ok' => true,
            'message' => 'Payout Day is open today: ' . $label . ' (set by admin). You can request withdrawal now.',
            'payout_day' => $label,
            'next' => null,
        ];
    }
    $next = ops_next_payout_day();
    return [
        'ok' => false,
        'message' => 'Payout Day: ' . $label . ' (set by admin). Withdrawal / bank payout is allowed only on that day. Next payout day: '
            . $next->format('l, d M Y') . '.',
        'payout_day' => $label,
        'next' => $next->format('l, d M Y'),
    ];
}

function ops_scalar(PDO $pdo, string $sql, array $params = [], $default = 0)
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : $v;
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Live week snapshot (daily ledger + closing + payouts).
 *
 * @return array<string,mixed>
 */
function ops_week_snapshot(PDO $pdo, array $week): array
{
    ops_ensure_tables($pdo);
    if (function_exists('wallet_ensure_schema')) {
        try {
            wallet_ensure_schema($pdo);
        } catch (Throwable $e) {
            // ignore
        }
    }
    closing_ensure_tables($pdo);
    wd_ensure_columns($pdo);

    $from = $week['start_date'];
    $to = $week['end_date'];
    $days = [];
    $cursor = $week['start'];
    while ($cursor->format('Y-m-d') <= $to) {
        $ymd = $cursor->format('Y-m-d');
        $days[$ymd] = [
            'date' => $ymd,
            'label' => $cursor->format('D d M'),
            'is_today' => $ymd === ops_now()->format('Y-m-d'),
            'is_closing_day' => true,
            'is_payout_day' => in_array((int) $cursor->format('N'), ops_payout_weekdays(), true),
            'credits' => 0.0,
            'debits' => 0.0,
            'commissions' => 0.0,
            'joins' => 0,
            'wd_requested' => 0.0,
            'wd_paid_net' => 0.0,
        ];
        $cursor = $cursor->modify('+1 day');
    }

    try {
        $st = $pdo->prepare("
            SELECT DATE(created_at) AS d,
                   COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) AS credits,
                   COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0) AS debits
            FROM wallet_ledger
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
        ");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $d = (string) $r['d'];
            if (isset($days[$d])) {
                $days[$d]['credits'] = (float) $r['credits'];
                $days[$d]['debits'] = (float) $r['debits'];
            }
        }
    } catch (Throwable $e) {
        // ledger table may be missing on very old installs
    }

    try {
        require_once __DIR__ . '/income_tables.php';
        income_tables_ensure($pdo);
        $st = $pdo->prepare("
            SELECT d, COALESCE(SUM(amt), 0) AS amt FROM (
                SELECT DATE(created_at) AS d, amount AS amt
                FROM commissions
                WHERE status != 'cancelled' AND DATE(created_at) BETWEEN ? AND ?
                UNION ALL
                SELECT DATE(created_at) AS d, amount AS amt
                FROM income_matching
                WHERE status != 'cancelled' AND DATE(created_at) BETWEEN ? AND ?
                UNION ALL
                SELECT DATE(created_at) AS d, amount AS amt
                FROM income_dsi
                WHERE status != 'cancelled' AND DATE(created_at) BETWEEN ? AND ?
            ) x
            GROUP BY d
        ");
        $st->execute([$from, $to, $from, $to, $from, $to]);
        foreach ($st->fetchAll() as $r) {
            $d = (string) $r['d'];
            if (isset($days[$d])) {
                $days[$d]['commissions'] = (float) $r['amt'];
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $st = $pdo->prepare("
            SELECT DATE(join_date) AS d, COUNT(*) AS n
            FROM members
            WHERE DATE(join_date) BETWEEN ? AND ?
            GROUP BY DATE(join_date)
        ");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $d = (string) $r['d'];
            if (isset($days[$d])) {
                $days[$d]['joins'] = (int) $r['n'];
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $st = $pdo->prepare("
            SELECT DATE(requested_at) AS d, COALESCE(SUM(amount), 0) AS amt
            FROM withdrawals
            WHERE DATE(requested_at) BETWEEN ? AND ?
            GROUP BY DATE(requested_at)
        ");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $d = (string) $r['d'];
            if (isset($days[$d])) {
                $days[$d]['wd_requested'] = (float) $r['amt'];
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $st = $pdo->prepare("
            SELECT DATE(COALESCE(processed_at, requested_at)) AS d,
                   COALESCE(SUM(COALESCE(net_amount, amount)), 0) AS amt
            FROM withdrawals
            WHERE status = 'paid' AND DATE(COALESCE(processed_at, requested_at)) BETWEEN ? AND ?
            GROUP BY DATE(COALESCE(processed_at, requested_at))
        ");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $d = (string) $r['d'];
            if (isset($days[$d])) {
                $days[$d]['wd_paid_net'] = (float) $r['amt'];
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $credits = array_sum(array_column($days, 'credits'));
    $debits = array_sum(array_column($days, 'debits'));
    $commissions = array_sum(array_column($days, 'commissions'));
    $joins = (int) array_sum(array_column($days, 'joins'));
    $wdReq = array_sum(array_column($days, 'wd_requested'));
    $wdPaid = array_sum(array_column($days, 'wd_paid_net'));

    $closingBinary = (float) ops_scalar($pdo, "
        SELECT COALESCE(SUM(binary_net_total), 0) FROM closing_runs
        WHERE DATE(created_at) BETWEEN ? AND ?
    ", [$from, $to]);
    $closingMatch = (float) ops_scalar($pdo, "
        SELECT COALESCE(SUM(matching_total), 0) FROM closing_runs
        WHERE DATE(created_at) BETWEEN ? AND ?
    ", [$from, $to]);
    $closingId = (int) ops_scalar($pdo, "
        SELECT id FROM closing_runs
        WHERE DATE(created_at) BETWEEN ? AND ?
        ORDER BY id DESC LIMIT 1
    ", [$from, $to], 0);

    $wdApprovedNet = (float) ops_scalar($pdo, "
        SELECT COALESCE(SUM(COALESCE(net_amount, amount)), 0) FROM withdrawals
        WHERE status = 'approved' AND DATE(COALESCE(processed_at, requested_at)) BETWEEN ? AND ?
    ", [$from, $to]);
    $tds = (float) ops_scalar($pdo, "
        SELECT COALESCE(SUM(tds_amount), 0) FROM withdrawals
        WHERE status IN ('approved','paid') AND DATE(COALESCE(processed_at, requested_at)) BETWEEN ? AND ?
    ", [$from, $to]);
    $fees = (float) ops_scalar($pdo, "
        SELECT COALESCE(SUM(fee_amount + other_deduction), 0) FROM withdrawals
        WHERE status IN ('approved','paid') AND DATE(COALESCE(processed_at, requested_at)) BETWEEN ? AND ?
    ", [$from, $to]);

    $closingStatus = ops_week_closing_status($pdo, $to);
    $recon = null;
    try {
        $st = $pdo->prepare('SELECT * FROM weekly_reconciliations WHERE week_end = ? LIMIT 1');
        $st->execute([$to]);
        $recon = $st->fetch() ?: null;
    } catch (Throwable $e) {
        $recon = null;
    }

    $checks = [
        [
            'key' => 'daily',
            'label' => 'Daily transaction recording',
            'ok' => $credits > 0 || $debits > 0 || $joins > 0 || $commissions > 0,
            'detail' => 'Ledger credits ' . number_format($credits, 2) . ' · debits ' . number_format($debits, 2),
        ],
        [
            'key' => 'closing',
            'label' => 'Daily midnight closing',
            'ok' => $closingStatus['already'],
            'detail' => $closingStatus['message'],
        ],
        [
            'key' => 'payout',
            'label' => 'Verified payable (approved / paid)',
            'ok' => $wdApprovedNet > 0 || $wdPaid > 0,
            'detail' => 'Approved net ' . number_format($wdApprovedNet, 2) . ' · Paid net ' . number_format($wdPaid, 2),
        ],
        [
            'key' => 'tds',
            'label' => 'TDS & admin charges on statements',
            'ok' => true,
            'detail' => 'TDS ' . number_format($tds, 2) . ' · Admin charges ' . number_format($fees, 2),
        ],
        [
            'key' => 'recon',
            'label' => 'Weekly reconciliation signed off',
            'ok' => $recon && ($recon['status'] ?? '') === 'reconciled',
            'detail' => $recon
                ? ('Status: ' . $recon['status'] . (!empty($recon['updated_at']) ? ' · ' . date('d M Y H:i', strtotime((string) $recon['updated_at'])) : ''))
                : 'Not signed off yet',
        ],
    ];

    return [
        'week' => $week,
        'days' => array_values($days),
        'totals' => [
            'ledger_credits' => round($credits, 2),
            'ledger_debits' => round($debits, 2),
            'commissions_paid' => round($commissions, 2),
            'closing_binary_net' => round($closingBinary, 2),
            'closing_matching' => round($closingMatch, 2),
            'withdrawals_requested' => round($wdReq, 2),
            'withdrawals_approved_net' => round($wdApprovedNet, 2),
            'withdrawals_paid_net' => round($wdPaid, 2),
            'tds_amount' => round($tds, 2),
            'admin_charges' => round($fees, 2),
            'joins_count' => $joins,
            'closing_id' => $closingId > 0 ? $closingId : null,
            'closing_done' => $closingStatus['already'] ? 1 : 0,
        ],
        'closing' => $closingStatus,
        'recon' => $recon,
        'checks' => $checks,
    ];
}

/**
 * Upsert live snapshot. Does not change reconciled status unless $markReconciled.
 *
 * @return array{ok:bool,id:?int,message:string}
 */
function ops_save_reconciliation(PDO $pdo, array $week, ?int $adminId = null, bool $markReconciled = false, string $notes = ''): array
{
    ops_ensure_tables($pdo);
    $snap = ops_week_snapshot($pdo, $week);
    $t = $snap['totals'];
    $status = $markReconciled ? 'reconciled' : (string) (($snap['recon']['status'] ?? 'open') ?: 'open');
    if ($markReconciled) {
        $status = 'reconciled';
    }
    $json = json_encode([
        'days' => $snap['days'],
        'checks' => $snap['checks'],
        'saved_at' => ops_now()->format('c'),
    ], JSON_UNESCAPED_UNICODE);

    try {
        $st = $pdo->prepare("
            INSERT INTO weekly_reconciliations (
                week_start, week_end, ledger_credits, ledger_debits, commissions_paid,
                closing_binary_net, closing_matching, withdrawals_requested,
                withdrawals_approved_net, withdrawals_paid_net, tds_amount, admin_charges,
                joins_count, closing_id, closing_done, status, admin_id, notes, snapshot_json
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                ledger_credits = VALUES(ledger_credits),
                ledger_debits = VALUES(ledger_debits),
                commissions_paid = VALUES(commissions_paid),
                closing_binary_net = VALUES(closing_binary_net),
                closing_matching = VALUES(closing_matching),
                withdrawals_requested = VALUES(withdrawals_requested),
                withdrawals_approved_net = VALUES(withdrawals_approved_net),
                withdrawals_paid_net = VALUES(withdrawals_paid_net),
                tds_amount = VALUES(tds_amount),
                admin_charges = VALUES(admin_charges),
                joins_count = VALUES(joins_count),
                closing_id = VALUES(closing_id),
                closing_done = VALUES(closing_done),
                status = VALUES(status),
                admin_id = COALESCE(VALUES(admin_id), admin_id),
                notes = VALUES(notes),
                snapshot_json = VALUES(snapshot_json)
        ");
        $st->execute([
            $week['start_date'],
            $week['end_date'],
            $t['ledger_credits'],
            $t['ledger_debits'],
            $t['commissions_paid'],
            $t['closing_binary_net'],
            $t['closing_matching'],
            $t['withdrawals_requested'],
            $t['withdrawals_approved_net'],
            $t['withdrawals_paid_net'],
            $t['tds_amount'],
            $t['admin_charges'],
            $t['joins_count'],
            $t['closing_id'],
            $t['closing_done'],
            $status,
            $adminId,
            $notes !== '' ? $notes : ($snap['recon']['notes'] ?? null),
            $json,
        ]);
        $idSt = $pdo->prepare('SELECT id FROM weekly_reconciliations WHERE week_end = ? LIMIT 1');
        $idSt->execute([$week['end_date']]);
        $id = (int) $idSt->fetchColumn();
        return [
            'ok' => true,
            'id' => $id > 0 ? $id : null,
            'message' => $markReconciled ? 'Week marked reconciled.' : 'Weekly snapshot saved.',
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'id' => null, 'message' => 'Could not save reconciliation.'];
    }
}

function ops_cron_log(PDO $pdo, string $job, bool $ok, string $message, array $payload = []): void
{
    ops_ensure_tables($pdo);
    try {
        $pdo->prepare('INSERT INTO ops_cron_logs (job, ok, message, payload_json) VALUES (?,?,?,?)')
            ->execute([
                $job,
                $ok ? 1 : 0,
                substr($message, 0, 500),
                $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            ]);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Auto daily close + week snapshot.
 * Closes pending pairs once per calendar day at ops_closing_time (default 00:00 IST).
 *
 * @return array{ok:bool,skipped:bool,message:string,closing:?array,recon:?array}
 */
function ops_run_weekly_jobs(PDO $pdo, bool $forceClose = false): array
{
    ops_ensure_tables($pdo);
    $week = ops_week_for();
    $closing = null;
    $skipped = true;
    $messages = [];

    $gate = ops_closing_gate($pdo, $forceClose);
    $shouldClose = $forceClose
        || (ops_weekly_closing_enabled() && $gate['ok']);

    if ($shouldClose && plan_uses_binary() && setting('binary_income_enabled', '1') === '1') {
        $notes = $forceClose
            ? 'Daily closing (cron override) — pending pairs closed'
            : 'Daily closing (midnight cron) — pending pairs closed';
        $closing = closing_run_binary($pdo, null, true, $notes);
        $skipped = false;
        $messages[] = $closing['message'] ?? 'Closing ran.';
        ops_cron_log($pdo, 'daily_closing', !empty($closing['ok']), (string) ($closing['message'] ?? ''), [
            'closing_id' => $closing['closing_id'] ?? null,
            'week_end' => $week['end_date'],
        ]);
    } elseif (!$gate['ok']) {
        $messages[] = $gate['message'];
        ops_cron_log($pdo, 'daily_closing', true, 'Skipped: ' . $gate['message'], ['week_end' => $week['end_date']]);
    } else {
        $messages[] = 'Closing not required.';
        ops_cron_log($pdo, 'daily_closing', true, 'Skipped (closing not required)', ['week_end' => $week['end_date']]);
    }

    $recon = ops_save_reconciliation($pdo, $week, null, false, 'Cron snapshot');
    $messages[] = $recon['message'];
    ops_cron_log($pdo, 'weekly_recon', !empty($recon['ok']), (string) $recon['message'], [
        'week_end' => $week['end_date'],
        'id' => $recon['id'] ?? null,
    ]);

    $ok = ($closing === null || !empty($closing['ok'])) && !empty($recon['ok']);
    return [
        'ok' => $ok,
        'skipped' => $skipped,
        'message' => implode(' ', $messages),
        'closing' => $closing,
        'recon' => $recon,
        'week' => $week,
    ];
}

function ops_cron_url(): string
{
    $base = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
    $key = ops_cron_secret();
    return $base . '/cron/weekly-closing.php?key=' . rawurlencode($key);
}

function ops_recent_cron_logs(PDO $pdo, int $limit = 20): array
{
    ops_ensure_tables($pdo);
    $limit = max(1, min(100, $limit));
    try {
        return $pdo->query("SELECT * FROM ops_cron_logs ORDER BY id DESC LIMIT {$limit}")->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string,mixed>> */
function ops_recent_reconciliations(PDO $pdo, int $limit = 12): array
{
    ops_ensure_tables($pdo);
    $limit = max(1, min(52, $limit));
    try {
        return $pdo->query("SELECT * FROM weekly_reconciliations ORDER BY week_end DESC LIMIT {$limit}")->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
