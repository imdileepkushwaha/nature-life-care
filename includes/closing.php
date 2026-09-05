<?php
/**
 * Binary / matching / level closing engine.
 *
 * Rules (fixed & consistent):
 * 1) On package activation → package BV walks the PLACEMENT upline and adds to left_bv / right_bv.
 * 2) Level income (if enabled) → % of package amount up the SPONSOR chain (L1 = direct sponsor).
 * 3) Binary closing → 1:2 / 2:1 matches the weaker leg's full eligible PV only when legs differ.
 *    Example: 1,600 + 3,200 → match 1,600 (carry 1,600 on strong). Equal legs (3,200 + 3,200) → no pay.
 *    Income = matched PV × binary %.
 * 4) Matching payout uses company-approved eligible PV only (paid kit / paid product, not reversed).
 * 5) Return / cancel / refund reverses unmatched eligible PV (net settlement). Already-paid pairs are not clawed back.
 * 6) Matching bonus → % of that member's binary gross to their direct sponsor.
 * 7) Admin charge % (optional) is deducted from binary before wallet credit.
 * 8) DSI → % of that member's binary net (wallet credit) up the SPONSOR line L1–L5
 *    (default 50/20/15/10/5). Paid immediately on closing to active packaged uplines only.
 * 9) After closing, lifetime pairs update rank promotions and unlock pair rewards.
 */

require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/plan_incentives.php';

function closing_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    // MySQL DDL implicitly commits — never run mid-transaction.
    if ($pdo->inTransaction()) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bv_credits (
            member_id INT NOT NULL PRIMARY KEY,
            package_id INT NULL,
            bv DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_bv_credits_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS closing_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NULL,
            members_processed INT NOT NULL DEFAULT 0,
            members_paid INT NOT NULL DEFAULT 0,
            pairs_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            matched_bv_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            binary_gross_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            binary_net_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            matching_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            admin_charge_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            pair_bv DECIMAL(12,2) NOT NULL DEFAULT 1,
            binary_percent DECIMAL(8,2) NOT NULL DEFAULT 0,
            matching_percent DECIMAL(8,2) NOT NULL DEFAULT 0,
            flush_pairs INT NOT NULL DEFAULT 0,
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS closing_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            closing_id INT NOT NULL,
            member_id INT NOT NULL,
            left_bv_before DECIMAL(12,2) NOT NULL DEFAULT 0,
            right_bv_before DECIMAL(12,2) NOT NULL DEFAULT 0,
            pairs DECIMAL(12,2) NOT NULL DEFAULT 0,
            matched_bv DECIMAL(12,2) NOT NULL DEFAULT 0,
            binary_gross DECIMAL(12,2) NOT NULL DEFAULT 0,
            admin_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
            binary_net DECIMAL(12,2) NOT NULL DEFAULT 0,
            matching_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            matching_to INT NULL,
            left_bv_after DECIMAL(12,2) NOT NULL DEFAULT 0,
            right_bv_after DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_closing_items_run (closing_id),
            INDEX idx_closing_items_member (member_id)
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bv_lots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_member_id INT NOT NULL,
            source_type VARCHAR(32) NOT NULL,
            source_id INT NOT NULL DEFAULT 0,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('eligible','reversed') NOT NULL DEFAULT 'eligible',
            reversed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_bv_lot (from_member_id, source_type, source_id),
            KEY idx_bv_lots_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try {
        $pdo->exec("
            INSERT IGNORE INTO bv_lots (from_member_id, source_type, source_id, amount, status)
            SELECT member_id, 'activation', member_id, bv, 'eligible'
            FROM bv_credits
            WHERE bv > 0
        ");
    } catch (Throwable $e) {
        // ignore
    }

    // Ensure pair BV setting exists
    try {
        $chk = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'binary_pair_bv' LIMIT 1");
        $chk->execute();
        if (!$chk->fetch()) {
            $defaultPair = 1000.0;
            try {
                $minBv = $pdo->query("SELECT MIN(bv) FROM packages WHERE status = 'active' AND bv > 0")->fetchColumn();
                if ($minBv !== false && $minBv !== null && (float) $minBv > 0) {
                    $defaultPair = (float) $minBv;
                }
            } catch (Throwable $e) {
                // keep 1000
            }
            $ins = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
            $ins->execute(['binary_pair_bv', (string) $defaultPair]);
        }
        $ratioChk = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'binary_match_ratio' LIMIT 1");
        $ratioChk->execute();
        if (!$ratioChk->fetch()) {
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')
                ->execute(['binary_match_ratio', '1:2']);
        }
    } catch (Throwable $e) {
        // ignore
    }

    $done = true;
}

function closing_pair_bv(): float
{
    $v = (float) setting('binary_pair_bv', '1000');
    return $v > 0 ? $v : 1.0;
}

/** Pair ratio: 1:2 (weaker:stronger, also 2:1) or 1:1 equal-leg. */
function closing_match_ratio(): string
{
    $r = strtolower(str_replace(' ', '', (string) setting('binary_match_ratio', '1:2')));
    if ($r === 'consume' || $r === '1:2c' || $r === '12c' || $r === 'strict') {
        return 'consume';
    }
    if ($r === '1:1' || $r === '11' || $r === 'equal') {
        return '1:1';
    }
    return '1:2';
}

function closing_bv_suppress(?bool $set = null): bool
{
    static $on = false;
    if ($set !== null) {
        $on = $set;
    }
    return $on;
}

/**
 * Push BV up the placement tree (left_bv / right_bv). Negative amount reverses unmatched leftover (floored at 0).
 */
function closing_push_bv_upline(PDO $pdo, int $memberId, float $bv): void
{
    $bv = round($bv, 2);
    if ($memberId <= 0 || $bv == 0.0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT placement_id, position FROM members WHERE id = ? LIMIT 1');
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['placement_id'])) {
        return;
    }

    $currentId = (int) $row['placement_id'];
    $side = strtolower(trim((string) ($row['position'] ?? '')));
    $guard = 0;

    $updL = $pdo->prepare('UPDATE members SET left_bv = GREATEST(0, ROUND(left_bv + ?, 2)) WHERE id = ?');
    $updR = $pdo->prepare('UPDATE members SET right_bv = GREATEST(0, ROUND(right_bv + ?, 2)) WHERE id = ?');
    $parentStmt = $pdo->prepare('SELECT placement_id, position FROM members WHERE id = ? LIMIT 1');

    while ($currentId > 0 && $guard < 200) {
        if ($side === 'left') {
            $updL->execute([$bv, $currentId]);
        } elseif ($side === 'right') {
            $updR->execute([$bv, $currentId]);
        } else {
            break;
        }

        $parentStmt->execute([$currentId]);
        $parent = $parentStmt->fetch();
        if (!$parent || empty($parent['placement_id'])) {
            break;
        }
        $side = strtolower(trim((string) ($parent['position'] ?? '')));
        $currentId = (int) $parent['placement_id'];
        $guard++;
    }
}

/**
 * Company-approved eligible PV lot. Pushed to placement upline once.
 */
function closing_credit_eligible(PDO $pdo, int $fromMemberId, float $bv, string $sourceType, int $sourceId): bool
{
    if (closing_bv_suppress()) {
        return false;
    }
    if (!plan_uses_binary()) {
        return false;
    }
    closing_ensure_tables($pdo);
    $bv = round($bv, 2);
    $sourceType = preg_replace('/[^a-z0-9_]/', '', strtolower($sourceType)) ?: 'manual';
    if ($fromMemberId <= 0 || $bv <= 0) {
        return false;
    }

    try {
        $pdo->prepare('INSERT INTO bv_lots (from_member_id, source_type, source_id, amount, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$fromMemberId, $sourceType, $sourceId, $bv, 'eligible']);
    } catch (Throwable $e) {
        return false;
    }

    if ($sourceType === 'activation' || $sourceType === 'upgrade') {
        try {
            $pdo->prepare('INSERT INTO bv_credits (member_id, package_id, bv) VALUES (?, NULL, ?)')
                ->execute([$fromMemberId, $bv]);
        } catch (Throwable $e) {
            try {
                $pdo->prepare('UPDATE bv_credits SET bv = ROUND(bv + ?, 2) WHERE member_id = ?')
                    ->execute([$bv, $fromMemberId]);
            } catch (Throwable $e2) {
                // ignore
            }
        }
    }

    closing_push_bv_upline($pdo, $fromMemberId, $bv);
    return true;
}

/**
 * Reverse an eligible lot after return / cancel / refund. Unmatched leftover only (legs floored at 0).
 */
function closing_reverse_eligible(PDO $pdo, int $fromMemberId, string $sourceType, int $sourceId): bool
{
    if (!plan_uses_binary()) {
        return false;
    }
    closing_ensure_tables($pdo);
    $sourceType = preg_replace('/[^a-z0-9_]/', '', strtolower($sourceType)) ?: 'manual';

    $stmt = $pdo->prepare("
        SELECT id, amount FROM bv_lots
        WHERE from_member_id = ? AND source_type = ? AND source_id = ? AND status = 'eligible'
        LIMIT 1
    ");
    $stmt->execute([$fromMemberId, $sourceType, $sourceId]);
    $lot = $stmt->fetch();
    if (!$lot) {
        return false;
    }

    $amt = round((float) $lot['amount'], 2);
    if ($amt > 0) {
        closing_push_bv_upline($pdo, $fromMemberId, -$amt);
    }
    $pdo->prepare("UPDATE bv_lots SET status = 'reversed', reversed_at = NOW() WHERE id = ?")
        ->execute([(int) $lot['id']]);
    return true;
}

function closing_restore_eligible(PDO $pdo, int $fromMemberId, string $sourceType, int $sourceId): bool
{
    if (!plan_uses_binary()) {
        return false;
    }
    closing_ensure_tables($pdo);
    $sourceType = preg_replace('/[^a-z0-9_]/', '', strtolower($sourceType)) ?: 'manual';

    $stmt = $pdo->prepare("
        SELECT id, amount FROM bv_lots
        WHERE from_member_id = ? AND source_type = ? AND source_id = ? AND status = 'reversed'
        LIMIT 1
    ");
    $stmt->execute([$fromMemberId, $sourceType, $sourceId]);
    $lot = $stmt->fetch();
    if (!$lot) {
        return false;
    }
    $amt = round((float) $lot['amount'], 2);
    $pdo->prepare("UPDATE bv_lots SET status = 'eligible', reversed_at = NULL WHERE id = ?")
        ->execute([(int) $lot['id']]);
    if ($amt > 0) {
        closing_push_bv_upline($pdo, $fromMemberId, $amt);
    }
    return true;
}

/**
 * Credit package BV to every placement ancestor (once per activated member).
 * @return bool true if BV was newly credited
 */
function closing_credit_bv_upline(PDO $pdo, int $memberId, float $bv, ?int $packageId = null): bool
{
    if (!plan_uses_binary()) {
        return false;
    }
    closing_ensure_tables($pdo);
    $bv = round($bv, 2);
    if ($memberId <= 0 || $bv <= 0) {
        return false;
    }

    try {
        $ins = $pdo->prepare('INSERT INTO bv_credits (member_id, package_id, bv) VALUES (?, ?, ?)');
        $ins->execute([$memberId, $packageId, $bv]);
    } catch (Throwable $e) {
        // Already credited (unique member_id) — do not push again
        return false;
    }

    closing_push_bv_upline($pdo, $memberId, $bv);
    return true;
}

/**
 * Credit only the BV difference on package upgrade (updates bv_credits total + pushes delta).
 * @return bool true if delta was credited
 */
function closing_credit_bv_delta(PDO $pdo, int $memberId, float $deltaBv, ?int $packageId = null): bool
{
    if (!plan_uses_binary()) {
        return false;
    }
    closing_ensure_tables($pdo);
    $deltaBv = round($deltaBv, 2);
    if ($memberId <= 0 || $deltaBv <= 0) {
        return false;
    }

    $chk = $pdo->prepare('SELECT bv FROM bv_credits WHERE member_id = ? LIMIT 1');
    $chk->execute([$memberId]);
    $existing = $chk->fetchColumn();

    if ($existing === false) {
        return closing_credit_bv_upline($pdo, $memberId, $deltaBv, $packageId);
    }

    $pdo->prepare('UPDATE bv_credits SET bv = ROUND(bv + ?, 2), package_id = COALESCE(?, package_id) WHERE member_id = ?')
        ->execute([$deltaBv, $packageId, $memberId]);
    closing_push_bv_upline($pdo, $memberId, $deltaBv);
    return true;
}

/**
 * Pay level income up the sponsor chain.
 * Default: idempotent once per member (first activation).
 * Pass $eventKey (e.g. upgrade:12) to allow a separate payout for upgrades.
 * @return float total paid
 */
function closing_pay_level_income(PDO $pdo, int $fromMemberId, string $memberCode, float $packageAmount, ?string $eventKey = null): float
{
    if ($fromMemberId <= 0 || $packageAmount <= 0) {
        return 0.0;
    }
    if (!plan_uses_level()) {
        return 0.0;
    }
    if (setting('level_income_enabled', '1') !== '1') {
        return 0.0;
    }

    if ($eventKey !== null && $eventKey !== '') {
        $tag = '[' . $eventKey . ']';
        $exists = $pdo->prepare("SELECT id FROM commissions WHERE from_member_id = ? AND type = 'level' AND description LIKE ? LIMIT 1");
        $exists->execute([$fromMemberId, '%' . $tag . '%']);
        if ($exists->fetch()) {
            return 0.0;
        }
    } else {
        // Idempotent: any prior level row from this activation source
        $exists = $pdo->prepare("SELECT id FROM commissions WHERE from_member_id = ? AND type = 'level' LIMIT 1");
        $exists->execute([$fromMemberId]);
        if ($exists->fetch()) {
            return 0.0;
        }
        $tag = '';
    }

    $levels = max(1, min(20, (int) setting('level_income_levels', '10')));
    $stmt = $pdo->prepare('SELECT sponsor_id FROM members WHERE id = ? LIMIT 1');
    $stmt->execute([$fromMemberId]);
    $sponsorId = (int) ($stmt->fetchColumn() ?: 0);

    $total = 0.0;
    $ins = $pdo->prepare('INSERT INTO commissions (member_id, from_member_id, type, amount, description, status) VALUES (?, ?, ?, ?, ?, ?)');
    $load = $pdo->prepare("SELECT id, sponsor_id, status, package_id FROM members WHERE id = ? LIMIT 1");

    for ($level = 1; $level <= $levels && $sponsorId > 0; $level++) {
        $load->execute([$sponsorId]);
        $up = $load->fetch();
        if (!$up) {
            break;
        }

        $pct = (float) setting('level_' . $level . '_percent', '0');
        $comm = round($packageAmount * $pct / 100, 2);

        if (
            $comm > 0
            && ($up['status'] ?? '') !== 'blocked'
            && !empty($up['package_id'])
        ) {
            $desc = "Level {$level} income from {$memberCode}";
            if ($tag !== '') {
                $desc .= ' ' . $tag;
            }
            $ins->execute([(int) $up['id'], $fromMemberId, 'level', $comm, $desc, 'paid']);
            $cid = (int) $pdo->lastInsertId();
            wallet_credit($pdo, (int) $up['id'], 'income', $comm, 'commission', $cid ?: null, $desc);
            $total += $comm;
        }

        $sponsorId = (int) ($up['sponsor_id'] ?? 0);
    }

    return $total;
}

/**
 * Post-activation hooks: BV upline + level income.
 */
function closing_on_activation(PDO $pdo, array $user, array $pkg): void
{
    closing_ensure_tables($pdo);
    $uid = (int) ($user['id'] ?? 0);
    if ($uid <= 0) {
        return;
    }
    $bv = round((float) ($pkg['bv'] ?? 0), 2);
    $amount = round((float) ($pkg['amount'] ?? 0), 2);
    $code = (string) ($user['member_id'] ?? '');
    $packageId = isset($pkg['id']) ? (int) $pkg['id'] : null;

    if ($bv > 0 && plan_uses_binary()) {
        closing_credit_eligible($pdo, $uid, $bv, 'activation', $uid);
    }
    if ($amount > 0 && plan_uses_level()) {
        closing_pay_level_income($pdo, $uid, $code, $amount);
    }
}

/**
 * Post-upgrade hooks: difference BV + level income on difference amount.
 */
function closing_on_upgrade(PDO $pdo, array $user, array $oldPkg, array $newPkg, ?string $eventKey = null): void
{
    closing_ensure_tables($pdo);
    $uid = (int) ($user['id'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    $deltaBv = round((float) ($newPkg['bv'] ?? 0) - (float) ($oldPkg['bv'] ?? 0), 2);
    $deltaAmount = round((float) ($newPkg['amount'] ?? 0) - (float) ($oldPkg['amount'] ?? 0), 2);
    $code = (string) ($user['member_id'] ?? '');
    $packageId = isset($newPkg['id']) ? (int) $newPkg['id'] : null;

    if ($deltaBv > 0 && plan_uses_binary()) {
        closing_credit_eligible($pdo, $uid, $deltaBv, 'upgrade', $packageId ?: $uid);
    }
    if ($deltaAmount > 0 && plan_uses_level()) {
        $key = $eventKey !== null && $eventKey !== '' ? $eventKey : ('upgrade:' . $uid . ':' . ($packageId ?? 0));
        closing_pay_level_income($pdo, $uid, $code, $deltaAmount, $key);
    }
}

/**
 * Recalculate all left_bv / right_bv from activated members (admin tool).
 * Does not touch commissions.
 * @return array{ok:bool,message:string,members:int}
 */
function closing_rebuild_bv(PDO $pdo): array
{
    if (!plan_uses_binary()) {
        return ['ok' => false, 'message' => 'Binary PV rebuild is disabled for this client plan.', 'members' => 0];
    }
    closing_ensure_tables($pdo);

    try {
        $pdo->beginTransaction();

        $pdo->exec('UPDATE members SET left_bv = 0, right_bv = 0');

        $lots = [];
        try {
            $lots = $pdo->query("SELECT from_member_id, amount FROM bv_lots WHERE status = 'eligible' AND amount > 0 ORDER BY id ASC")->fetchAll();
        } catch (Throwable $e) {
            $lots = [];
        }

        $count = 0;
        if ($lots) {
            foreach ($lots as $lot) {
                closing_push_bv_upline($pdo, (int) $lot['from_member_id'], (float) $lot['amount']);
                $count++;
            }
            $pdo->commit();
            return [
                'ok' => true,
                'message' => "Eligible PV rebuilt from {$count} approved lot(s). Returns/cancellations stay reversed.",
                'members' => $count,
            ];
        }

        $pdo->exec('DELETE FROM bv_credits');

        $rows = $pdo->query("
            SELECT m.id, m.member_id, m.package_id, COALESCE(p.bv, 0) AS bv
            FROM members m
            INNER JOIN packages p ON p.id = m.package_id
            WHERE m.package_id IS NOT NULL
            ORDER BY m.id ASC
        ")->fetchAll();

        $count = 0;
        foreach ($rows as $row) {
            $bv = round((float) $row['bv'], 2);
            if ($bv <= 0) {
                continue;
            }
            if (closing_credit_bv_upline($pdo, (int) $row['id'], $bv, (int) $row['package_id'])) {
                $count++;
            }
        }

        $pdo->commit();
        return [
            'ok' => true,
            'message' => "PV rebuilt for {$count} activated member(s).",
            'members' => $count,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'PV rebuild failed.', 'members' => 0];
    }
}

/**
 * After package PV is edited: update locked activation lots / credits to the new package BV,
 * then rebuild open left/right PV. Does not change commissions already paid.
 *
 * @return array{ok:bool,message:string,updated:int}
 */
function closing_sync_package_bv(PDO $pdo, int $packageId, ?float $newBv = null): array
{
    if (!plan_uses_binary() || $packageId <= 0) {
        return ['ok' => false, 'message' => 'PV sync skipped.', 'updated' => 0];
    }
    closing_ensure_tables($pdo);

    if ($newBv === null) {
        $st = $pdo->prepare('SELECT bv FROM packages WHERE id = ? LIMIT 1');
        $st->execute([$packageId]);
        $newBv = (float) ($st->fetchColumn() ?: 0);
    }
    $newBv = round(max(0.0, $newBv), 2);
    if ($newBv <= 0) {
        return ['ok' => false, 'message' => 'Package PV is zero — nothing to sync.', 'updated' => 0];
    }

    $members = $pdo->prepare("
        SELECT id FROM members
        WHERE package_id = ?
        ORDER BY id ASC
    ");
    $members->execute([$packageId]);
    $ids = array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) {
        return ['ok' => true, 'message' => 'No activated members on this package.', 'updated' => 0];
    }

    $updated = 0;
    $updLot = $pdo->prepare("
        UPDATE bv_lots
        SET amount = ?
        WHERE from_member_id = ? AND source_type = 'activation' AND status = 'eligible'
    ");
    $updCredit = $pdo->prepare('UPDATE bv_credits SET bv = ?, package_id = COALESCE(package_id, ?) WHERE member_id = ?');
    $insCredit = $pdo->prepare('INSERT INTO bv_credits (member_id, package_id, bv) VALUES (?, ?, ?)');

    foreach ($ids as $mid) {
        $updLot->execute([$newBv, $mid]);
        if ($updLot->rowCount() > 0) {
            $updated++;
        }
        try {
            $updCredit->execute([$newBv, $packageId, $mid]);
            if ($updCredit->rowCount() < 1) {
                $insCredit->execute([$mid, $packageId, $newBv]);
            }
        } catch (Throwable $e) {
            // ignore credit upsert issues; lots drive rebuild
        }
    }

    $rb = closing_rebuild_bv($pdo);
    $msg = $updated > 0
        ? "Updated PV on {$updated} activation lot(s) to " . number_format($newBv, 2, '.', '') . '.'
        : 'No activation lots needed updating (or lots missing — rebuild used current package where possible).';
    if (!empty($rb['message'])) {
        $msg .= ' ' . $rb['message'];
    }

    return ['ok' => !empty($rb['ok']), 'message' => $msg, 'updated' => $updated];
}

/**
 * Compute binary match from current eligible PV legs.
 *
 * 1:1 — classic equal matching: match min(L,R) including when legs are equal.
 * 1:2 / 2:1 — match weaker PV only when legs are unequal; equal → no pay.
 * Strict "consume" mode still uses Pair-PV units (1 weak + 2 strong).
 */
function closing_compute_match(float $leftBv, float $rightBv, float $pairBv, int $flushPairs): array
{
    $leftBv = max(0.0, round($leftBv, 2));
    $rightBv = max(0.0, round($rightBv, 2));
    $pairBv = $pairBv > 0 ? $pairBv : 1.0;

    $ratio = closing_match_ratio();
    if ($ratio === 'consume') {
        $leftUnits = (int) floor($leftBv / $pairBv + 1e-9);
        $rightUnits = (int) floor($rightBv / $pairBv + 1e-9);
        $leftRem = round($leftBv - ($leftUnits * $pairBv), 2);
        $rightRem = round($rightBv - ($rightUnits * $pairBv), 2);

        if ($leftUnits <= $rightUnits) {
            $weak = $leftUnits;
            $strong = $rightUnits;
            $leftIsWeak = true;
        } else {
            $weak = $rightUnits;
            $strong = $leftUnits;
            $leftIsWeak = false;
        }
        $pairs = (float) min($weak, intdiv($strong, 2));
        if ($flushPairs > 0) {
            $pairs = min($pairs, (float) $flushPairs);
        }
        $p = (int) $pairs;
        $weakAfter = $weak - $p;
        $strongAfter = $strong - ($p * 2);
        if ($leftIsWeak) {
            $leftUnitsAfter = $weakAfter;
            $rightUnitsAfter = $strongAfter;
        } else {
            $leftUnitsAfter = $strongAfter;
            $rightUnitsAfter = $weakAfter;
        }

        $matched = round($pairs * $pairBv, 2);
        $leftAfter = max(0.0, round($leftRem + ($leftUnitsAfter * $pairBv), 2));
        $rightAfter = max(0.0, round($rightRem + ($rightUnitsAfter * $pairBv), 2));

        return [
            'pairs' => $pairs,
            'matched_bv' => $matched,
            'left_before' => $leftBv,
            'right_before' => $rightBv,
            'left_after' => $leftAfter,
            'right_after' => $rightAfter,
        ];
    }

    // 1:2 — equal legs do not pay (need imbalance).
    if ($ratio !== '1:1' && abs($leftBv - $rightBv) < 0.00001) {
        return [
            'pairs' => 0.0,
            'matched_bv' => 0.0,
            'left_before' => $leftBv,
            'right_before' => $rightBv,
            'left_after' => $leftBv,
            'right_after' => $rightBv,
        ];
    }

    $matched = round(min($leftBv, $rightBv), 2);
    if ($flushPairs > 0) {
        $maxMatch = round($flushPairs * $pairBv, 2);
        if ($maxMatch > 0 && $matched > $maxMatch) {
            $matched = $maxMatch;
        }
    }
    if ($matched <= 0) {
        return [
            'pairs' => 0.0,
            'matched_bv' => 0.0,
            'left_before' => $leftBv,
            'right_before' => $rightBv,
            'left_after' => $leftBv,
            'right_after' => $rightBv,
        ];
    }

    $leftAfter = max(0.0, round($leftBv - $matched, 2));
    $rightAfter = max(0.0, round($rightBv - $matched, 2));
    // Rank / "pairs" progress still uses Pair PV as a unit size (not for chunking match).
    $pairs = round($matched / $pairBv, 4);

    return [
        'pairs' => $pairs,
        'matched_bv' => $matched,
        'left_before' => $leftBv,
        'right_before' => $rightBv,
        'left_after' => $leftAfter,
        'right_after' => $rightAfter,
    ];
}

/**
 * Preview or run binary + matching closing for all eligible members.
 *
 * @param bool $commit false = dry-run preview
 * @return array{
 *   ok:bool,message:string,closing_id:?int,preview:bool,
 *   members_processed:int,members_paid:int,
 *   pairs_total:float,matched_bv_total:float,
 *   binary_gross_total:float,binary_net_total:float,
 *   matching_total:float,admin_charge_total:float,
 *   items:array
 * }
 */
function closing_run_binary(PDO $pdo, ?int $adminId = null, bool $commit = true, string $notes = ''): array
{
    // DDL (CREATE/ALTER) must run before BEGIN — MySQL auto-commits and would kill the closing TX.
    closing_ensure_tables($pdo);
    wallet_ensure_schema($pdo);
    require_once __DIR__ . '/income_tables.php';
    income_tables_ensure($pdo);
    plan_incentives_ensure($pdo);

    $empty = static function (string $msg, bool $ok = false) use ($commit): array {
        return [
            'ok' => $ok,
            'message' => $msg,
            'closing_id' => null,
            'preview' => !$commit,
            'members_processed' => 0,
            'members_paid' => 0,
            'pairs_total' => 0.0,
            'matched_bv_total' => 0.0,
            'binary_gross_total' => 0.0,
            'binary_net_total' => 0.0,
            'matching_total' => 0.0,
            'admin_charge_total' => 0.0,
            'items' => [],
        ];
    };

    if (!plan_uses_binary() || setting('binary_income_enabled', '1') !== '1') {
        return $empty('Binary income is disabled for this client plan.');
    }

    $pairBv = closing_pair_bv();
    $binaryPct = (float) setting('binary_commission_percent', '15');
    $matchingPct = feature_enabled('feature_matching_income')
        ? (float) setting('matching_commission_percent', '0')
        : 0.0;
    $flushPairs = max(0, (int) setting('binary_flush_pairs', '0'));
    $adminChargePct = max(0.0, (float) setting('daily_closing_admin_charge', '0'));

    if ($binaryPct <= 0) {
        return $empty('Binary commission % is zero. Nothing to pay.');
    }

    $items = [];
    $pairsTotal = 0.0;
    $matchedTotal = 0.0;
    $binaryGrossTotal = 0.0;
    $binaryNetTotal = 0.0;
    $matchingTotal = 0.0;
    $adminChargeTotal = 0.0;
    $processed = 0;
    $paid = 0;
    $dsiPaid = 0;
    $dsiAmt = 0.0;
    $dsiHeld = 0;

    try {
        if ($commit) {
            $pdo->beginTransaction();
            $stmt = $pdo->query("
                SELECT id, member_id, full_name, sponsor_id, left_bv, right_bv, status, package_id
                FROM members
                WHERE status = 'active' AND package_id IS NOT NULL
                ORDER BY id ASC
                FOR UPDATE
            ");
        } else {
            $stmt = $pdo->query("
                SELECT id, member_id, full_name, sponsor_id, left_bv, right_bv, status, package_id
                FROM members
                WHERE status = 'active' AND package_id IS NOT NULL
                ORDER BY id ASC
            ");
        }
        $rows = $stmt->fetchAll();

        $updBv = $pdo->prepare('UPDATE members SET left_bv = ?, right_bv = ? WHERE id = ?');
        $insComm = $pdo->prepare('INSERT INTO commissions (member_id, from_member_id, type, amount, description, status) VALUES (?, ?, ?, ?, ?, ?)');
        $sponsorStmt = $pdo->prepare("SELECT id, status, package_id FROM members WHERE id = ? LIMIT 1");

        foreach ($rows as $m) {
            $processed++;
            $mid = (int) $m['id'];
            $match = closing_compute_match((float) $m['left_bv'], (float) $m['right_bv'], $pairBv, $flushPairs);

            if ($match['matched_bv'] <= 0) {
                continue;
            }

            $gross = round($match['matched_bv'] * $binaryPct / 100, 2);
            $charge = $adminChargePct > 0 ? round($gross * $adminChargePct / 100, 2) : 0.0;
            $net = round(max(0.0, $gross - $charge), 2);

            $matchingAmt = 0.0;
            $matchingTo = null;
            $sponsorId = (int) ($m['sponsor_id'] ?? 0);
            if ($matchingPct > 0 && $gross > 0 && $sponsorId > 0) {
                $sponsorStmt->execute([$sponsorId]);
                $sp = $sponsorStmt->fetch();
                if (
                    $sp
                    && ($sp['status'] ?? '') === 'active'
                    && !empty($sp['package_id'])
                ) {
                    $matchingAmt = round($gross * $matchingPct / 100, 2);
                    $matchingTo = (int) $sp['id'];
                }
            }

            $item = [
                'member_id' => $mid,
                'member_code' => (string) $m['member_id'],
                'full_name' => (string) $m['full_name'],
                'left_bv_before' => $match['left_before'],
                'right_bv_before' => $match['right_before'],
                'pairs' => $match['pairs'],
                'matched_bv' => $match['matched_bv'],
                'binary_gross' => $gross,
                'admin_charge' => $charge,
                'binary_net' => $net,
                'matching_amount' => $matchingAmt,
                'matching_to' => $matchingTo,
                'left_bv_after' => $match['left_after'],
                'right_bv_after' => $match['right_after'],
            ];
            $items[] = $item;

            $pairsTotal += $match['pairs'];
            $matchedTotal += $match['matched_bv'];
            $binaryGrossTotal += $gross;
            $binaryNetTotal += $net;
            $matchingTotal += $matchingAmt;
            $adminChargeTotal += $charge;
            $paid++;

            if (!$commit) {
                continue;
            }

            // Deduct matched BV (carry forward leftover)
            $updBv->execute([$match['left_after'], $match['right_after'], $mid]);

            if ($net > 0) {
                $insComm->execute([
                    $mid,
                    null,
                    'binary',
                    $net,
                    sprintf(
                        'Binary closing: %s pair(s), matched PV %s',
                        rtrim(rtrim(number_format($match['pairs'], 2, '.', ''), '0'), '.'),
                        number_format($match['matched_bv'], 2, '.', '')
                    ),
                    'paid',
                ]);
                $cid = (int) $pdo->lastInsertId();
                wallet_credit(
                    $pdo,
                    $mid,
                    'income',
                    $net,
                    'commission',
                    $cid ?: null,
                    sprintf(
                        'Binary closing: %s pair(s), matched PV %s',
                        rtrim(rtrim(number_format($match['pairs'], 2, '.', ''), '0'), '.'),
                        number_format($match['matched_bv'], 2, '.', '')
                    )
                );
                // DSI = % of this member's binary net up sponsor L1–L5
                $dsiPay = plan_dsi_pay($pdo, $mid, (string) $m['member_id'], $net, 'dsi:bin:c' . $cid);
                $dsiPaid += (int) ($dsiPay['paid'] ?? 0);
                $dsiAmt += (float) ($dsiPay['amount'] ?? 0);
            }

            if ($matchingAmt > 0 && $matchingTo) {
                $descM = 'Matching bonus on binary of ' . $m['member_id'];
                $cid = income_split_insert($pdo, 'matching', (int) $matchingTo, $mid, $matchingAmt, $descM, 'paid');
                wallet_credit($pdo, $matchingTo, 'income', $matchingAmt, 'income_matching', $cid ?: null, $descM);
            }
        }

        $closingId = null;
        if ($commit) {
            $insRun = $pdo->prepare("
                INSERT INTO closing_runs (
                    admin_id, members_processed, members_paid, pairs_total, matched_bv_total,
                    binary_gross_total, binary_net_total, matching_total, admin_charge_total,
                    pair_bv, binary_percent, matching_percent, flush_pairs, notes
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $insRun->execute([
                $adminId,
                $processed,
                $paid,
                round($pairsTotal, 2),
                round($matchedTotal, 2),
                round($binaryGrossTotal, 2),
                round($binaryNetTotal, 2),
                round($matchingTotal, 2),
                round($adminChargeTotal, 2),
                $pairBv,
                $binaryPct,
                $matchingPct,
                $flushPairs,
                $notes !== '' ? $notes : 'Binary + matching closing',
            ]);
            $closingId = (int) $pdo->lastInsertId();

            $insItem = $pdo->prepare("
                INSERT INTO closing_items (
                    closing_id, member_id, left_bv_before, right_bv_before, pairs, matched_bv,
                    binary_gross, admin_charge, binary_net, matching_amount, matching_to,
                    left_bv_after, right_bv_after
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            foreach ($items as $it) {
                $insItem->execute([
                    $closingId,
                    $it['member_id'],
                    $it['left_bv_before'],
                    $it['right_bv_before'],
                    $it['pairs'],
                    $it['matched_bv'],
                    $it['binary_gross'],
                    $it['admin_charge'],
                    $it['binary_net'],
                    $it['matching_amount'],
                    $it['matching_to'],
                    $it['left_bv_after'],
                    $it['right_bv_after'],
                ]);
            }

            plan_closing_apply_pairs($pdo, $items);

            // Legacy kit-queued pending DSI (if any) — settle when recipient qualifies
            $dsiSettle = plan_dsi_settle_pending($pdo);
            $dsiPaid += (int) ($dsiSettle['settled'] ?? 0);
            $dsiAmt += (float) ($dsiSettle['amount'] ?? 0);
            $dsiHeld = (int) ($dsiSettle['held'] ?? 0);

            if (!$pdo->inTransaction()) {
                throw new RuntimeException('Closing transaction was closed early (schema DDL). Retry once.');
            }
            $pdo->commit();
            if ($adminId) {
                $extra = $dsiPaid > 0 ? "; DSI settled {$dsiPaid} (₹" . number_format($dsiAmt, 2, '.', '') . ')' : '';
                log_activity('binary_closing', "Closing #{$closingId}: paid {$paid} member(s), binary net " . round($binaryNetTotal, 2) . $extra);
            }
        }

        $msg = $commit
            ? ($paid > 0
                ? "Closing complete. Paid {$paid} member(s)."
                : 'Closing complete. No pairs available to match.')
            : ($paid > 0
                ? "Preview: {$paid} member(s) would be paid."
                : 'Preview: no pairs available to match.');

        if ($commit && !empty($dsiPaid) && $dsiPaid > 0) {
            $msg .= ' DSI paid for ' . $dsiPaid . ' entr' . ($dsiPaid === 1 ? 'y' : 'ies') . ' (₹' . number_format($dsiAmt, 2, '.', '') . ').';
        }
        if ($commit && !empty($dsiHeld) && $dsiHeld > 0) {
            $msg .= ' Legacy DSI held: ' . $dsiHeld . '.';
        }
        return [
            'ok' => true,
            'message' => $msg,
            'closing_id' => $closingId,
            'preview' => !$commit,
            'members_processed' => $processed,
            'members_paid' => $paid,
            'pairs_total' => round($pairsTotal, 2),
            'matched_bv_total' => round($matchedTotal, 2),
            'binary_gross_total' => round($binaryGrossTotal, 2),
            'binary_net_total' => round($binaryNetTotal, 2),
            'matching_total' => round($matchingTotal, 2),
            'admin_charge_total' => round($adminChargeTotal, 2),
            'items' => $items,
        ];
    } catch (Throwable $e) {
        if ($commit && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $detail = trim($e->getMessage());
        if (function_exists('app_is_local') && app_is_local() && $detail !== '') {
            return $empty('Closing failed due to a database error: ' . $detail);
        }
        return $empty('Closing failed due to a database error.');
    }
}

/**
 * Snapshot of current open BV that would match (for dashboard cards).
 */
function closing_open_pair_summary(PDO $pdo): array
{
    closing_ensure_tables($pdo);
    $pairBv = closing_pair_bv();
    $flush = max(0, (int) setting('binary_flush_pairs', '0'));
    $binaryPct = (float) setting('binary_commission_percent', '15');

    $rows = $pdo->query("
        SELECT left_bv, right_bv FROM members
        WHERE status = 'active' AND package_id IS NOT NULL
    ")->fetchAll();

    $pairs = 0.0;
    $matched = 0.0;
    $eligible = 0;
    foreach ($rows as $r) {
        $m = closing_compute_match((float) $r['left_bv'], (float) $r['right_bv'], $pairBv, $flush);
        if ($m['matched_bv'] > 0) {
            $eligible++;
            $pairs += $m['pairs'];
            $matched += $m['matched_bv'];
        }
    }

    return [
        'eligible_members' => $eligible,
        'pairs' => round($pairs, 2),
        'matched_bv' => round($matched, 2),
        'est_binary_gross' => round($matched * $binaryPct / 100, 2),
        'pair_bv' => $pairBv,
        'binary_percent' => $binaryPct,
        'flush_pairs' => $flush,
    ];
}
