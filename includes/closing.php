<?php
/**
 * Binary / matching / level closing engine.
 *
 * Rules (fixed & consistent):
 * 1) On package activation → package BV walks the PLACEMENT upline and adds to left_bv / right_bv.
 * 2) Level income (if enabled) → % of package amount up the SPONSOR chain (L1 = direct sponsor).
 * 3) Binary closing → match BV 1:1 on both legs in pair units; pay % of matched BV; carry leftover.
 * 4) Matching → % of that member's binary gross to their direct sponsor.
 * 5) Admin charge % (optional) is deducted from binary before wallet credit.
 */

require_once __DIR__ . '/wallet.php';

function closing_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
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

/**
 * Push BV up the placement tree (left_bv / right_bv). Does not touch bv_credits.
 */
function closing_push_bv_upline(PDO $pdo, int $memberId, float $bv): void
{
    $bv = round($bv, 2);
    if ($memberId <= 0 || $bv <= 0) {
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

    $updL = $pdo->prepare('UPDATE members SET left_bv = ROUND(left_bv + ?, 2) WHERE id = ?');
    $updR = $pdo->prepare('UPDATE members SET right_bv = ROUND(right_bv + ?, 2) WHERE id = ?');
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
        closing_credit_bv_upline($pdo, $uid, $bv, $packageId);
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
        closing_credit_bv_delta($pdo, $uid, $deltaBv, $packageId);
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
        return ['ok' => false, 'message' => 'Binary BV rebuild is disabled for this client plan.', 'members' => 0];
    }
    closing_ensure_tables($pdo);

    try {
        $pdo->beginTransaction();

        $pdo->exec('UPDATE members SET left_bv = 0, right_bv = 0');
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
            'message' => "BV rebuilt for {$count} activated member(s).",
            'members' => $count,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'BV rebuild failed.', 'members' => 0];
    }
}

/**
 * Compute binary match for one member from current BV legs.
 * @return array{pairs:float,matched_bv:float,left_after:float,right_after:float,left_before:float,right_before:float}
 */
function closing_compute_match(float $leftBv, float $rightBv, float $pairBv, int $flushPairs): array
{
    $leftBv = max(0.0, round($leftBv, 2));
    $rightBv = max(0.0, round($rightBv, 2));
    $pairBv = $pairBv > 0 ? $pairBv : 1.0;

    $leftPairs = floor($leftBv / $pairBv + 1e-9);
    $rightPairs = floor($rightBv / $pairBv + 1e-9);
    $pairs = (float) min($leftPairs, $rightPairs);

    if ($flushPairs > 0) {
        $pairs = min($pairs, (float) $flushPairs);
    }

    $matched = round($pairs * $pairBv, 2);
    $leftAfter = round($leftBv - $matched, 2);
    $rightAfter = round($rightBv - $matched, 2);

    return [
        'pairs' => $pairs,
        'matched_bv' => $matched,
        'left_before' => $leftBv,
        'right_before' => $rightBv,
        'left_after' => max(0.0, $leftAfter),
        'right_after' => max(0.0, $rightAfter),
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
function closing_run_binary(PDO $pdo, ?int $adminId = null, bool $commit = true): array
{
    closing_ensure_tables($pdo);

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
    $binaryPct = (float) setting('binary_commission_percent', '10');
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
                        'Binary closing: %s pair(s), matched BV %s',
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
                        'Binary closing: %s pair(s), matched BV %s',
                        rtrim(rtrim(number_format($match['pairs'], 2, '.', ''), '0'), '.'),
                        number_format($match['matched_bv'], 2, '.', '')
                    )
                );
            }

            if ($matchingAmt > 0 && $matchingTo) {
                $descM = 'Matching bonus on binary of ' . $m['member_id'];
                $insComm->execute([
                    $matchingTo,
                    $mid,
                    'matching',
                    $matchingAmt,
                    $descM,
                    'paid',
                ]);
                $cid = (int) $pdo->lastInsertId();
                wallet_credit($pdo, $matchingTo, 'income', $matchingAmt, 'commission', $cid ?: null, $descM);
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
                'Binary + matching closing',
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

            $pdo->commit();
            if ($adminId) {
                log_activity('binary_closing', "Closing #{$closingId}: paid {$paid} member(s), binary net " . round($binaryNetTotal, 2));
            }
        }

        $msg = $commit
            ? ($paid > 0
                ? "Closing complete. Paid {$paid} member(s)."
                : 'Closing complete. No pairs available to match.')
            : ($paid > 0
                ? "Preview: {$paid} member(s) would be paid."
                : 'Preview: no pairs available to match.');

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
    $binaryPct = (float) setting('binary_commission_percent', '10');

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
