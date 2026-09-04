<?php
/**
 * Bharat Seva plan extras: DSI, ranks, pair rewards, payout-rate freeze.
 */

require_once __DIR__ . '/wallet.php';

function plan_dsi_suppress(?bool $set = null): bool
{
    static $on = false;
    if ($set !== null) {
        $on = $set;
    }
    return $on;
}

/** @return list<array{rank_key:string,title:string,sort_order:int,pairs_required:int,bonus_amount:float}> */
function plan_rank_defaults(): array
{
    return [
        ['rank_key' => 'executive', 'title' => 'Executive', 'sort_order' => 1, 'pairs_required' => 25, 'bonus_amount' => 0],
        ['rank_key' => 'star', 'title' => 'Star', 'sort_order' => 2, 'pairs_required' => 50, 'bonus_amount' => 0],
        ['rank_key' => 'super_star', 'title' => 'Super Star', 'sort_order' => 3, 'pairs_required' => 100, 'bonus_amount' => 0],
        ['rank_key' => 'bronze', 'title' => 'Bronze', 'sort_order' => 4, 'pairs_required' => 250, 'bonus_amount' => 0],
        ['rank_key' => 'silver', 'title' => 'Silver', 'sort_order' => 5, 'pairs_required' => 500, 'bonus_amount' => 0],
        ['rank_key' => 'gold', 'title' => 'Gold', 'sort_order' => 6, 'pairs_required' => 1000, 'bonus_amount' => 0],
        ['rank_key' => 'platinum', 'title' => 'Platinum', 'sort_order' => 7, 'pairs_required' => 2500, 'bonus_amount' => 0],
        ['rank_key' => 'emerald', 'title' => 'Emerald', 'sort_order' => 8, 'pairs_required' => 5000, 'bonus_amount' => 0],
        ['rank_key' => 'ruby', 'title' => 'Ruby', 'sort_order' => 9, 'pairs_required' => 10000, 'bonus_amount' => 0],
        ['rank_key' => 'diamond_1', 'title' => 'Diamond 1', 'sort_order' => 10, 'pairs_required' => 25000, 'bonus_amount' => 0],
        ['rank_key' => 'diamond_2', 'title' => 'Diamond 2', 'sort_order' => 11, 'pairs_required' => 50000, 'bonus_amount' => 0],
        ['rank_key' => 'diamond_3', 'title' => 'Diamond 3', 'sort_order' => 12, 'pairs_required' => 100000, 'bonus_amount' => 0],
        ['rank_key' => 'director', 'title' => 'Director', 'sort_order' => 13, 'pairs_required' => 250000, 'bonus_amount' => 0],
    ];
}

/** @return list<array{reward_key:string,rank_key:string,pairs_required:int,title:string,gift_label:string,cash_value:float,sort_order:int}> */
function plan_reward_defaults(): array
{
    return [
        ['reward_key' => 'exec_bag', 'rank_key' => 'executive', 'pairs_required' => 25, 'title' => '25 Pair', 'gift_label' => 'Best Marketing Bag', 'cash_value' => 0, 'sort_order' => 1],
        ['reward_key' => 'star_cooker', 'rank_key' => 'star', 'pairs_required' => 50, 'title' => '50 Pair', 'gift_label' => 'Induction Cooker', 'cash_value' => 0, 'sort_order' => 2],
        ['reward_key' => 'ss_travel', 'rank_key' => 'super_star', 'pairs_required' => 100, 'title' => '100 Pair', 'gift_label' => 'Travel Bag', 'cash_value' => 0, 'sort_order' => 3],
        ['reward_key' => 'bronze_tablet', 'rank_key' => 'bronze', 'pairs_required' => 250, 'title' => '250 Pair', 'gift_label' => 'Tablet', 'cash_value' => 0, 'sort_order' => 4],
        ['reward_key' => 'silver_phone', 'rank_key' => 'silver', 'pairs_required' => 500, 'title' => '500 Pair', 'gift_label' => 'Branded Android Mobile', 'cash_value' => 0, 'sort_order' => 5],
        ['reward_key' => 'gold_ebike', 'rank_key' => 'gold', 'pairs_required' => 1000, 'title' => '1,000 Pair', 'gift_label' => 'E-bike', 'cash_value' => 0, 'sort_order' => 6],
        ['reward_key' => 'plat_2l', 'rank_key' => 'platinum', 'pairs_required' => 2500, 'title' => '2,500 Pair', 'gift_label' => '₹2L benefit', 'cash_value' => 200000, 'sort_order' => 7],
        ['reward_key' => 'em_silver', 'rank_key' => 'emerald', 'pairs_required' => 5000, 'title' => '5,000 Pair', 'gift_label' => '750g Silver', 'cash_value' => 0, 'sort_order' => 8],
        ['reward_key' => 'ruby_gold', 'rank_key' => 'ruby', 'pairs_required' => 10000, 'title' => '10,000 Pair', 'gift_label' => '20g Gold', 'cash_value' => 0, 'sort_order' => 9],
        ['reward_key' => 'd1_car', 'rank_key' => 'diamond_1', 'pairs_required' => 25000, 'title' => '25,000 Pair', 'gift_label' => '₹10L Car Cash', 'cash_value' => 1000000, 'sort_order' => 10],
        ['reward_key' => 'd2_flat', 'rank_key' => 'diamond_2', 'pairs_required' => 50000, 'title' => '50,000 Pair', 'gift_label' => '₹25L Luxury Flat', 'cash_value' => 2500000, 'sort_order' => 11],
        ['reward_key' => 'd3_bungalow', 'rank_key' => 'diamond_3', 'pairs_required' => 100000, 'title' => '1,00,000 Pair', 'gift_label' => '₹60L Bungalow', 'cash_value' => 6000000, 'sort_order' => 12],
        ['reward_key' => 'dir_cr', 'rank_key' => 'director', 'pairs_required' => 250000, 'title' => '2,50,000 Pair', 'gift_label' => '₹1 Cr + Salary', 'cash_value' => 10000000, 'sort_order' => 13],
    ];
}

function plan_incentives_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $col = $pdo->query("SHOW COLUMNS FROM commissions LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
        if ($col) {
            $typeDef = strtolower((string) ($col['Type'] ?? ''));
            $need = ['dsi', 'rank', 'reward'];
            $missing = false;
            foreach ($need as $t) {
                if (!str_contains($typeDef, "'" . $t . "'") && !str_starts_with($typeDef, 'varchar') && !str_starts_with($typeDef, 'char')) {
                    $missing = true;
                    break;
                }
            }
            if ($missing && str_starts_with($typeDef, 'enum')) {
                $pdo->exec("
                    ALTER TABLE commissions
                    MODIFY COLUMN type ENUM('binary','referral','matching','level','dsi','rank','reward','other')
                    NOT NULL DEFAULT 'binary'
                ");
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    foreach ([
        'rank_key' => "VARCHAR(40) NOT NULL DEFAULT '' AFTER package_id",
        'lifetime_pairs' => 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER right_bv',
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
            CREATE TABLE IF NOT EXISTS plan_ranks (
                rank_key VARCHAR(40) NOT NULL PRIMARY KEY,
                title VARCHAR(80) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                pairs_required INT NOT NULL DEFAULT 0,
                bonus_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS plan_rewards (
                reward_key VARCHAR(40) NOT NULL PRIMARY KEY,
                rank_key VARCHAR(40) NOT NULL DEFAULT '',
                pairs_required INT NOT NULL DEFAULT 0,
                title VARCHAR(80) NOT NULL,
                gift_label VARCHAR(255) NOT NULL,
                cash_value DECIMAL(14,2) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS member_rank_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                rank_key VARCHAR(40) NOT NULL,
                pairs_at DECIMAL(14,2) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_mrh_member (member_id),
                KEY idx_mrh_rank (rank_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS member_rewards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                reward_key VARCHAR(40) NOT NULL,
                pairs_at DECIMAL(14,2) NOT NULL DEFAULT 0,
                status ENUM('eligible','fulfilled','cancelled') NOT NULL DEFAULT 'eligible',
                commission_id INT NULL,
                admin_note VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                fulfilled_at DATETIME NULL,
                fulfilled_by INT NULL,
                UNIQUE KEY uk_member_reward (member_id, reward_key),
                KEY idx_mr_status (status),
                KEY idx_mr_member (member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $insR = $pdo->prepare("
            INSERT INTO plan_ranks (rank_key, title, sort_order, pairs_required, bonus_amount, status)
            VALUES (?, ?, ?, ?, ?, 'active')
            ON DUPLICATE KEY UPDATE rank_key = rank_key
        ");
        foreach (plan_rank_defaults() as $row) {
            $insR->execute([
                $row['rank_key'],
                $row['title'],
                $row['sort_order'],
                $row['pairs_required'],
                $row['bonus_amount'],
            ]);
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $insG = $pdo->prepare("
            INSERT INTO plan_rewards (reward_key, rank_key, pairs_required, title, gift_label, cash_value, sort_order, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ON DUPLICATE KEY UPDATE reward_key = reward_key
        ");
        foreach (plan_reward_defaults() as $row) {
            $insG->execute([
                $row['reward_key'],
                $row['rank_key'],
                $row['pairs_required'],
                $row['title'],
                $row['gift_label'],
                $row['cash_value'],
                $row['sort_order'],
            ]);
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $pdo->query('SELECT 1 FROM closing_items LIMIT 1');
        $pdo->exec("
            UPDATE members m
            LEFT JOIN (
                SELECT member_id, COALESCE(SUM(pairs), 0) AS p
                FROM closing_items
                GROUP BY member_id
            ) x ON x.member_id = m.id
            SET m.lifetime_pairs = COALESCE(x.p, m.lifetime_pairs)
            WHERE m.lifetime_pairs = 0 AND COALESCE(x.p, 0) > 0
        ");
    } catch (Throwable $e) {
        // ignore
    }

    $done = true;
}

/** @return list<array<string,mixed>> */
function plan_ranks_list(PDO $pdo, bool $activeOnly = true): array
{
    plan_incentives_ensure($pdo);
    $sql = 'SELECT * FROM plan_ranks';
    if ($activeOnly) {
        $sql .= " WHERE status = 'active'";
    }
    $sql .= ' ORDER BY sort_order ASC, pairs_required ASC';
    try {
        return $pdo->query($sql)->fetchAll() ?: [];
    } catch (Throwable $e) {
        return plan_rank_defaults();
    }
}

/** @return list<array<string,mixed>> */
function plan_rewards_list(PDO $pdo, bool $activeOnly = true): array
{
    plan_incentives_ensure($pdo);
    $sql = 'SELECT * FROM plan_rewards';
    if ($activeOnly) {
        $sql .= " WHERE status = 'active'";
    }
    $sql .= ' ORDER BY sort_order ASC, pairs_required ASC';
    try {
        return $pdo->query($sql)->fetchAll() ?: [];
    } catch (Throwable $e) {
        return plan_reward_defaults();
    }
}

function plan_rank_title(PDO $pdo, string $rankKey): string
{
    $rankKey = trim($rankKey);
    if ($rankKey === '') {
        return 'Associate';
    }
    foreach (plan_ranks_list($pdo, false) as $r) {
        if ((string) $r['rank_key'] === $rankKey) {
            return (string) $r['title'];
        }
    }
    return ucwords(str_replace('_', ' ', $rankKey));
}

/**
 * Highest rank whose pair threshold is met.
 * @return array<string,mixed>|null
 */
function plan_rank_for_pairs(PDO $pdo, float $pairs): ?array
{
    $best = null;
    foreach (plan_ranks_list($pdo) as $r) {
        if ($pairs + 0.00001 >= (float) $r['pairs_required']) {
            $best = $r;
        }
    }
    return $best;
}

/** @return array{rank:?array,next:?array,pairs:float,progress:float} */
function plan_member_rank_progress(PDO $pdo, array $member): array
{
    plan_incentives_ensure($pdo);
    $pairs = (float) ($member['lifetime_pairs'] ?? 0);
    $current = plan_rank_for_pairs($pdo, $pairs);
    $next = null;
    $prevReq = $current ? (float) $current['pairs_required'] : 0.0;
    foreach (plan_ranks_list($pdo) as $r) {
        if ((float) $r['pairs_required'] > $pairs + 0.00001) {
            $next = $r;
            break;
        }
    }
    $span = $next ? max(0.01, (float) $next['pairs_required'] - $prevReq) : 1.0;
    $into = $next ? max(0.0, $pairs - $prevReq) : $span;
    $progress = $next ? min(100.0, round($into / $span * 100, 1)) : 100.0;

    return [
        'rank' => $current,
        'next' => $next,
        'pairs' => $pairs,
        'progress' => $progress,
    ];
}

/**
 * Queue Direct Sponsor Incentive up 5 sponsor levels from a distributable pool.
 * Creates pending commissions only — wallet credit happens on binary closing via plan_dsi_settle_pending().
 * Pool = dsi_pool_percent of $baseAmount. Each level takes its % of that pool.
 * Default split: L1 50% · L2 20% · L3 15% · L4 10% · L5 5%.
 */
function plan_dsi_pay(PDO $pdo, int $fromMemberId, string $memberCode, float $baseAmount, string $eventKey): float
{
    if (plan_dsi_suppress()) {
        return 0.0;
    }
    if (!feature_enabled('feature_dsi_income')) {
        return 0.0;
    }
    // Settlement runs only inside binary closing — do not queue on non-binary plans.
    if (!plan_uses_binary() || !feature_enabled('feature_binary_income')) {
        return 0.0;
    }
    $fromMemberId = (int) $fromMemberId;
    $baseAmount = round($baseAmount, 2);
    $eventKey = trim($eventKey);
    if ($fromMemberId <= 0 || $baseAmount <= 0 || $eventKey === '') {
        return 0.0;
    }

    plan_incentives_ensure($pdo);

    $poolPct = max(0.0, (float) setting('dsi_pool_percent', '10'));
    $pool = round($baseAmount * $poolPct / 100, 2);
    if ($pool <= 0) {
        return 0.0;
    }

    require_once __DIR__ . '/income_tables.php';
    income_tables_ensure($pdo);

    $tag = '[' . $eventKey . ']';
    try {
        $exists = $pdo->prepare("SELECT id FROM income_dsi WHERE from_member_id = ? AND description LIKE ? LIMIT 1");
        $exists->execute([$fromMemberId, '%' . $tag . '%']);
        if ($exists->fetch()) {
            return 0.0;
        }
    } catch (Throwable $e) {
        return 0.0;
    }

    $stmt = $pdo->prepare('SELECT sponsor_id FROM members WHERE id = ? LIMIT 1');
    $stmt->execute([$fromMemberId]);
    $sponsorId = (int) ($stmt->fetchColumn() ?: 0);

    $load = $pdo->prepare("SELECT id, sponsor_id, status, package_id FROM members WHERE id = ? LIMIT 1");

    $total = 0.0;
    for ($level = 1; $level <= 5 && $sponsorId > 0; $level++) {
        $load->execute([$sponsorId]);
        $up = $load->fetch();
        if (!$up) {
            break;
        }

        $pct = max(0.0, (float) setting('dsi_level_' . $level . '_percent', match ($level) {
            1 => '50',
            2 => '20',
            3 => '15',
            4 => '10',
            default => '5',
        }));
        $comm = round($pool * $pct / 100, 2);

        if (
            $comm > 0
            && ($up['status'] ?? '') === 'active'
            && !empty($up['package_id'])
        ) {
            $desc = "DSI L{$level} from {$memberCode} {$tag}";
            // Held until binary closing — no wallet credit yet.
            income_split_insert($pdo, 'dsi', (int) $up['id'], $fromMemberId, $comm, $desc, 'pending');
            $total += $comm;
        }

        $sponsorId = (int) ($up['sponsor_id'] ?? 0);
    }

    return $total;
}

/**
 * Credit pending DSI commissions to Income Wallet (call inside an open closing transaction).
 * @return array{ok:bool,settled:int,amount:float}
 */
function plan_dsi_settle_pending(PDO $pdo): array
{
    if (!feature_enabled('feature_dsi_income')) {
        return ['ok' => true, 'settled' => 0, 'amount' => 0.0];
    }

    plan_incentives_ensure($pdo);
    require_once __DIR__ . '/income_tables.php';
    require_once __DIR__ . '/wallet.php';
    income_tables_ensure($pdo);

    try {
        $rows = $pdo->query("
            SELECT c.id, c.member_id, c.amount, c.description
            FROM income_dsi c
            INNER JOIN members m ON m.id = c.member_id
            WHERE c.status = 'pending'
              AND m.status = 'active'
              AND m.package_id IS NOT NULL
            ORDER BY c.id ASC
            FOR UPDATE
        ")->fetchAll();
    } catch (Throwable $e) {
        return ['ok' => false, 'settled' => 0, 'amount' => 0.0];
    }

    $upd = $pdo->prepare("UPDATE income_dsi SET status = 'paid' WHERE id = ? AND status = 'pending'");
    $settled = 0;
    $amount = 0.0;

    foreach ($rows as $row) {
        $cid = (int) ($row['id'] ?? 0);
        $mid = (int) ($row['member_id'] ?? 0);
        $amt = round((float) ($row['amount'] ?? 0), 2);
        if ($cid <= 0 || $mid <= 0 || $amt <= 0) {
            continue;
        }
        $upd->execute([$cid]);
        if ($upd->rowCount() < 1) {
            continue;
        }
        $desc = (string) ($row['description'] ?? 'DSI closing settlement');
        wallet_credit($pdo, $mid, 'income', $amt, 'income_dsi', $cid, $desc);
        $settled++;
        $amount += $amt;
    }

    return ['ok' => true, 'settled' => $settled, 'amount' => round($amount, 2)];
}

/**
 * After binary closing: add pairs, promote ranks, unlock rewards.
 * @param list<array<string,mixed>> $items
 */
function plan_closing_apply_pairs(PDO $pdo, array $items): void
{
    if (!feature_enabled('feature_ranks_enabled') && !feature_enabled('feature_rewards_enabled')) {
        return;
    }
    if (!plan_uses_binary()) {
        return;
    }
    plan_incentives_ensure($pdo);

    $updPairs = $pdo->prepare('UPDATE members SET lifetime_pairs = ROUND(lifetime_pairs + ?, 2) WHERE id = ?');
    $seen = [];

    foreach ($items as $it) {
        $mid = (int) ($it['member_id'] ?? 0);
        $pairs = round((float) ($it['pairs'] ?? 0), 2);
        if ($mid <= 0 || $pairs <= 0) {
            continue;
        }
        $updPairs->execute([$pairs, $mid]);
        $seen[$mid] = true;
    }

    foreach (array_keys($seen) as $mid) {
        plan_member_evaluate($pdo, (int) $mid);
    }
}

function plan_member_evaluate(PDO $pdo, int $memberId): void
{
    if ($memberId <= 0) {
        return;
    }
    plan_incentives_ensure($pdo);

    $stmt = $pdo->prepare('SELECT id, member_id, rank_key, lifetime_pairs, status, package_id FROM members WHERE id = ? LIMIT 1');
    $stmt->execute([$memberId]);
    $m = $stmt->fetch();
    if (!$m) {
        return;
    }

    $pairs = (float) ($m['lifetime_pairs'] ?? 0);
    $code = (string) ($m['member_id'] ?? '');
    $currentKey = trim((string) ($m['rank_key'] ?? ''));

    if (feature_enabled('feature_ranks_enabled')) {
        $target = plan_rank_for_pairs($pdo, $pairs);
        $targetKey = $target ? (string) $target['rank_key'] : '';
        if ($targetKey !== '' && $targetKey !== $currentKey) {
            $order = [];
            foreach (plan_ranks_list($pdo) as $r) {
                $order[(string) $r['rank_key']] = (int) $r['sort_order'];
            }
            $curOrd = $order[$currentKey] ?? 0;
            $newOrd = $order[$targetKey] ?? 0;
            if ($newOrd > $curOrd) {
                foreach (plan_ranks_list($pdo) as $r) {
                    $rk = (string) $r['rank_key'];
                    $ord = (int) $r['sort_order'];
                    if ($ord <= $curOrd || $ord > $newOrd) {
                        continue;
                    }
                    if ($pairs + 0.00001 < (float) $r['pairs_required']) {
                        continue;
                    }
                    plan_rank_promote($pdo, $memberId, $code, $r, $pairs);
                }
            }
        }
    }

    if (feature_enabled('feature_rewards_enabled')) {
        plan_rewards_unlock($pdo, $memberId, $pairs);
    }
}

function plan_rank_promote(PDO $pdo, int $memberId, string $memberCode, array $rank, float $pairs): void
{
    $key = (string) ($rank['rank_key'] ?? '');
    if ($key === '') {
        return;
    }

    $hist = $pdo->prepare('SELECT id FROM member_rank_history WHERE member_id = ? AND rank_key = ? LIMIT 1');
    $hist->execute([$memberId, $key]);
    if ($hist->fetch()) {
        $pdo->prepare('UPDATE members SET rank_key = ? WHERE id = ?')->execute([$key, $memberId]);
        return;
    }

    $pdo->prepare('INSERT INTO member_rank_history (member_id, rank_key, pairs_at) VALUES (?, ?, ?)')
        ->execute([$memberId, $key, $pairs]);
    $pdo->prepare('UPDATE members SET rank_key = ? WHERE id = ?')->execute([$key, $memberId]);

    $bonus = round((float) ($rank['bonus_amount'] ?? 0), 2);
    if ($bonus > 0) {
        $title = (string) ($rank['title'] ?? $key);
        $desc = "Rank promotion bonus: {$title}";
        $pdo->prepare('INSERT INTO commissions (member_id, from_member_id, type, amount, description, status) VALUES (?, NULL, ?, ?, ?, ?)')
            ->execute([$memberId, 'rank', $bonus, $desc, 'paid']);
        $cid = (int) $pdo->lastInsertId();
        wallet_credit($pdo, $memberId, 'income', $bonus, 'commission', $cid ?: null, $desc);
    }
}

function plan_rewards_unlock(PDO $pdo, int $memberId, float $pairs): void
{
    $ins = $pdo->prepare("
        INSERT INTO member_rewards (member_id, reward_key, pairs_at, status)
        VALUES (?, ?, ?, 'eligible')
        ON DUPLICATE KEY UPDATE member_id = member_id
    ");
    foreach (plan_rewards_list($pdo) as $r) {
        if ($pairs + 0.00001 < (float) $r['pairs_required']) {
            continue;
        }
        $ins->execute([$memberId, (string) $r['reward_key'], $pairs]);
    }
}

/**
 * Admin fulfills a reward. Credits cash_value to income wallet when requested and amount > 0.
 * @return array{ok:bool,message:string}
 */
function plan_reward_fulfill(PDO $pdo, int $rowId, int $adminId, bool $creditCash, string $note = ''): array
{
    plan_incentives_ensure($pdo);
    $stmt = $pdo->prepare("
        SELECT mr.*, pr.gift_label, pr.cash_value, pr.title
        FROM member_rewards mr
        JOIN plan_rewards pr ON pr.reward_key = mr.reward_key
        WHERE mr.id = ? LIMIT 1
    ");
    $stmt->execute([$rowId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => 'Reward not found.'];
    }
    if (($row['status'] ?? '') !== 'eligible') {
        return ['ok' => false, 'message' => 'This reward is not pending fulfillment.'];
    }

    $cash = round((float) ($row['cash_value'] ?? 0), 2);
    $cid = null;
    if ($creditCash && $cash > 0) {
        $desc = 'Reward: ' . (string) ($row['gift_label'] ?: $row['title']);
        $pdo->prepare('INSERT INTO commissions (member_id, from_member_id, type, amount, description, status) VALUES (?, NULL, ?, ?, ?, ?)')
            ->execute([(int) $row['member_id'], 'reward', $cash, $desc, 'paid']);
        $cid = (int) $pdo->lastInsertId();
        wallet_credit($pdo, (int) $row['member_id'], 'income', $cash, 'commission', $cid ?: null, $desc);
    }

    $pdo->prepare("
        UPDATE member_rewards
        SET status = 'fulfilled', commission_id = ?, admin_note = ?, fulfilled_at = NOW(), fulfilled_by = ?
        WHERE id = ? AND status = 'eligible'
    ")->execute([$cid, $note !== '' ? $note : null, $adminId ?: null, $rowId]);

    return ['ok' => true, 'message' => 'Reward marked fulfilled.' . ($cid ? ' Cash benefit credited to Income Wallet.' : '')];
}

function plan_freeze_set(PDO $pdo, bool $frozen, string $note = ''): void
{
    feature_save($pdo, 'commission_rates_frozen', $frozen ? '1' : '0');
    feature_save($pdo, 'commission_rates_frozen_at', $frozen ? date('Y-m-d H:i:s') : '');
    if ($note !== '') {
        feature_save($pdo, 'commission_rates_frozen_note', $note);
    }
    clear_setting_cache();
}
