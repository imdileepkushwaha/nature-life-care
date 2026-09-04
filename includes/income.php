<?php
/**
 * User income / commission report helpers
 */
if (!function_exists('feature_enabled')) {
    require_once __DIR__ . '/../config/database.php';
}

/** Full catalog of income types (for historical filters / meta lookup). */
function income_types_catalog(): array
{
    return [
        'binary' => [
            'key' => 'binary',
            'label' => 'Matching Income',
            'short' => 'Matching',
            'file' => 'income-matching.php',
            'kicker' => '1:2 / 2:1 pair',
            'desc' => 'Earnings from completed pairs on eligible PV.',
            'tone' => 'orange',
        ],
        'referral' => [
            'key' => 'referral',
            'label' => 'Referral Income',
            'short' => 'Referral',
            'file' => 'income-referral.php',
            'kicker' => 'Direct sponsor',
            'desc' => 'Bonus credited when your direct members activate a package.',
            'tone' => 'green',
        ],
        'matching' => [
            'key' => 'matching',
            'label' => 'Matching Income',
            'short' => 'Matching',
            'file' => 'income-matching.php',
            'kicker' => '1:2 / 2:1 pair',
            'desc' => 'Income from completed 1:2 / 2:1 pairs on eligible PV'
                . (feature_enabled('feature_matching_income') ? ', plus matching bonus on your team\'s binary gross.' : '.'),
            'tone' => 'orange',
        ],
        'level' => [
            'key' => 'level',
            'label' => 'Level Income',
            'short' => 'Level',
            'file' => 'income-level.php',
            'kicker' => 'Generation',
            'desc' => 'Level-wise income from deeper generations in your network.',
            'tone' => 'purple',
        ],
        'dsi' => [
            'key' => 'dsi',
            'label' => 'DSI Income',
            'short' => 'DSI',
            'file' => 'income-dsi.php',
            'kicker' => 'Direct sponsor',
            'desc' => 'Share of the distributable incentive from product and kit activity in your sponsor line. Credited to your wallet only after binary closing.',
            'tone' => 'green',
        ],
        'rank' => [
            'key' => 'rank',
            'label' => 'Rank Incentive',
            'short' => 'Rank',
            'file' => 'income-rank.php',
            'kicker' => 'Promotion',
            'desc' => 'Cash bonus credited when you achieve a new rank.',
            'tone' => 'gold',
        ],
        'reward' => [
            'key' => 'reward',
            'label' => 'Reward Benefit',
            'short' => 'Reward',
            'file' => 'income-reward.php',
            'kicker' => 'Milestone',
            'desc' => 'Cash value of fulfilled pair-milestone rewards.',
            'tone' => 'gold',
        ],
        'other' => [
            'key' => 'other',
            'label' => 'Other Income',
            'short' => 'Other',
            'file' => 'income-other.php',
            'kicker' => 'Adjustments',
            'desc' => 'Manual credits, rewards, and other special income entries.',
            'tone' => 'gold',
        ],
    ];
}

/** Enabled income types for the current plan / feature flags (+ always other). */
function income_types(): array
{
    $all = income_types_catalog();
    $out = [];
    if (feature_enabled('feature_referral_income')) {
        $out['referral'] = $all['referral'];
    }
    if (plan_uses_binary()) {
        $out['matching'] = $all['matching'];
    }
    if (feature_enabled('feature_level_income') && plan_uses_level()) {
        $out['level'] = $all['level'];
    }
    if (feature_enabled('feature_dsi_income')) {
        $out['dsi'] = $all['dsi'];
    }
    if (feature_enabled('feature_ranks_enabled') && plan_uses_binary()) {
        $out['rank'] = $all['rank'];
    }
    if (feature_enabled('feature_rewards_enabled') && plan_uses_binary()) {
        $out['reward'] = $all['reward'];
    }
    $out['other'] = $all['other'];
    return $out;
}

function income_type_meta(string $type): ?array
{
    $types = income_types();
    if (isset($types[$type])) {
        return $types[$type];
    }
    $catalog = income_types_catalog();
    return $catalog[$type] ?? null;
}

/** SVG icon markup for income type / banner (stroke icons). */
function income_type_icon(string $type): string
{
    return match ($type) {
        'binary' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v4M12 11L5 17M12 11l7 6"/></svg>',
        'referral' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11l-3-3 3-3"/></svg>',
        'matching' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>',
        'level' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19h16M7 15V9M12 15V5M17 15v-3"/></svg>',
        'dsi' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6"/></svg>',
        'rank' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l2.4 7.4H22l-6 4.6 2.3 7-6.3-4.6L5.7 21 8 14 2 9.4h7.6z"/></svg>',
        'reward' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 12v8H4v-8"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/></svg>',
        'other' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>',
        'summary' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg>',
        'recent' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
        default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/></svg>',
    };
}

function income_status_pill(string $status): string
{
    $s = strtolower($status);
    $cls = match ($s) {
        'paid' => 'is-ok',
        'pending' => 'is-wait',
        'cancelled' => 'is-bad',
        default => 'is-muted',
    };
    return '<span class="inc-pill ' . $cls . '">' . e(ucfirst($s)) . '</span>';
}

/** User panel hides Binary Income; pair closing credits still show under Matching. */
function income_type_sql_types(?string $type): array
{
    if ($type === null || $type === '') {
        return [];
    }
    if ($type === 'matching') {
        return ['matching', 'binary'];
    }
    return [$type];
}

function income_commissions_sum(PDO $pdo, int $memberId, array $types, ?string $status = null): float
{
    if (!$types) {
        return 0.0;
    }
    $where = ['member_id = ?', 'type IN (' . implode(',', array_fill(0, count($types), '?')) . ')'];
    $params = array_merge([$memberId], $types);
    if ($status !== null && $status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function income_commissions_count(PDO $pdo, int $memberId, array $types, ?string $status = null): int
{
    if (!$types) {
        return 0;
    }
    $where = ['member_id = ?', 'type IN (' . implode(',', array_fill(0, count($types), '?')) . ')'];
    $params = array_merge([$memberId], $types);
    if ($status !== null && $status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    } else {
        $where[] = "status != 'cancelled'";
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM commissions WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function income_sum(PDO $pdo, int $memberId, ?string $type = null, ?string $status = null): float
{
    require_once __DIR__ . '/income_tables.php';
    income_tables_ensure($pdo);

    // DSI is held until binary closing — never surface as visible pending.
    if ($status === 'pending' && $type === 'dsi') {
        return 0.0;
    }

    if ($type === 'dsi') {
        $st = ($status === null || $status === '') ? null : $status;
        $sum = income_split_sum($pdo, 'dsi', $memberId, $st);
        if ($status === null || $status === '') {
            // Unfiltered = exclude held pending.
            $sum -= income_split_sum($pdo, 'dsi', $memberId, 'pending');
        }
        return max(0.0, $sum);
    }

    if ($type === 'matching') {
        $matchStatus = $status;
        $sum = income_split_sum($pdo, 'matching', $memberId, $matchStatus);
        // Pair binary still listed under Matching Income in the user panel.
        $sum += income_commissions_sum($pdo, $memberId, ['binary'], $status);
        return $sum;
    }

    if ($type !== null && $type !== '') {
        return income_commissions_sum($pdo, $memberId, income_type_sql_types($type), $status);
    }

    // All types: commissions + matching table + (paid) DSI.
    $where = ['member_id = ?'];
    $params = [$memberId];
    if ($status !== null && $status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    $sum = (float) $stmt->fetchColumn();
    $sum += income_split_sum($pdo, 'matching', $memberId, $status);
    if ($status === 'pending') {
        // Held DSI excluded from dashboard pending.
    } elseif ($status === 'paid') {
        $sum += income_split_sum($pdo, 'dsi', $memberId, 'paid');
    } elseif ($status === 'cancelled') {
        $sum += income_split_sum($pdo, 'dsi', $memberId, 'cancelled');
    } else {
        $sum += income_split_sum($pdo, 'dsi', $memberId, 'paid');
        $sum += income_split_sum($pdo, 'dsi', $memberId, 'cancelled');
    }
    return $sum;
}

function income_count(PDO $pdo, int $memberId, ?string $type = null, ?string $status = null): int
{
    require_once __DIR__ . '/income_tables.php';
    income_tables_ensure($pdo);

    if ($status === 'pending' && $type === 'dsi') {
        return 0;
    }

    if ($type === 'dsi') {
        if ($status === null || $status === '') {
            return income_split_count($pdo, 'dsi', $memberId, 'paid');
        }
        return income_split_count($pdo, 'dsi', $memberId, $status);
    }

    if ($type === 'matching') {
        if ($status === null || $status === '') {
            return income_split_count($pdo, 'matching', $memberId, 'paid')
                + income_split_count($pdo, 'matching', $memberId, 'pending')
                + income_commissions_count($pdo, $memberId, ['binary'], null);
        }
        return income_split_count($pdo, 'matching', $memberId, $status)
            + income_commissions_count($pdo, $memberId, ['binary'], $status);
    }

    if ($type !== null && $type !== '') {
        return income_commissions_count($pdo, $memberId, income_type_sql_types($type), $status);
    }

    $where = ['member_id = ?'];
    $params = [$memberId];
    if ($status !== null && $status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    } else {
        $where[] = "status != 'cancelled'";
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM commissions WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    $n = (int) $stmt->fetchColumn();
    if ($status === 'pending') {
        $n += income_split_count($pdo, 'matching', $memberId, 'pending');
    } elseif ($status === 'paid') {
        $n += income_split_count($pdo, 'matching', $memberId, 'paid');
        $n += income_split_count($pdo, 'dsi', $memberId, 'paid');
    } elseif ($status === 'cancelled') {
        $n += income_split_count($pdo, 'matching', $memberId, 'cancelled');
        $n += income_split_count($pdo, 'dsi', $memberId, 'cancelled');
    } else {
        $n += income_split_count($pdo, 'matching', $memberId, 'paid');
        $n += income_split_count($pdo, 'matching', $memberId, 'pending');
        $n += income_split_count($pdo, 'dsi', $memberId, 'paid');
    }
    return $n;
}

/**
 * Fetch paginated commission rows for a member.
 * @return array{rows:array,total:int,total_pages:int,page:int}
 */
function income_fetch_rows(PDO $pdo, int $memberId, string $type, string $statusFilter = '', int $page = 1, int $perPage = 15): array
{
    require_once __DIR__ . '/income_tables.php';
    income_tables_ensure($pdo);

    $page = max(1, $page);
    $perPage = max(5, min(50, $perPage));

    if ($type === 'dsi') {
        return income_split_fetch_rows($pdo, 'dsi', $memberId, $statusFilter, $page, $perPage);
    }

    if ($type === 'matching') {
        return income_matching_fetch_rows($pdo, $memberId, $statusFilter, $page, $perPage);
    }

    $offset = ($page - 1) * $perPage;
    $types = income_type_sql_types($type);
    if (!$types) {
        $types = [$type];
    }

    $where = ['c.member_id = ?', 'c.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')'];
    $params = array_merge([$memberId], $types);
    if (in_array($statusFilter, ['pending', 'paid', 'cancelled'], true)) {
        $where[] = 'c.status = ?';
        $params[] = $statusFilter;
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM commissions c WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));

    $stmt = $pdo->prepare("
        SELECT c.*,
               fm.member_id AS from_mid,
               fm.full_name AS from_name,
               fm.username AS from_username
        FROM commissions c
        LEFT JOIN members fm ON fm.id = c.from_member_id
        WHERE $whereSql
        ORDER BY c.id DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);

    return [
        'rows' => $stmt->fetchAll(),
        'total' => $total,
        'total_pages' => $totalPages,
        'page' => $page,
    ];
}

/**
 * Matching Income report = matching bonus table + binary pair commissions.
 * @return array{rows:array,total:int,total_pages:int,page:int}
 */
function income_matching_fetch_rows(
    PDO $pdo,
    int $memberId,
    string $statusFilter = '',
    int $page = 1,
    int $perPage = 15
): array {
    $page = max(1, $page);
    $perPage = max(5, min(50, $perPage));
    $offset = ($page - 1) * $perPage;

    $mWhere = ['c.member_id = ?'];
    $bWhere = ["c.member_id = ?", "c.type = 'binary'"];
    $params = [$memberId];
    $bParams = [$memberId];
    if (in_array($statusFilter, ['pending', 'paid', 'cancelled'], true)) {
        $mWhere[] = 'c.status = ?';
        $bWhere[] = 'c.status = ?';
        $params[] = $statusFilter;
        $bParams[] = $statusFilter;
    }
    $mSql = implode(' AND ', $mWhere);
    $bSql = implode(' AND ', $bWhere);
    $allParams = array_merge($params, $bParams);

    $countStmt = $pdo->prepare("
        SELECT (
            (SELECT COUNT(*) FROM income_matching c WHERE {$mSql})
            + (SELECT COUNT(*) FROM commissions c WHERE {$bSql})
        )
    ");
    $countStmt->execute($allParams);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));

    $stmt = $pdo->prepare("
        SELECT * FROM (
            SELECT c.id, c.member_id, c.from_member_id, 'matching' AS type, c.amount, c.description, c.status, c.created_at,
                   fm.member_id AS from_mid, fm.full_name AS from_name, fm.username AS from_username
            FROM income_matching c
            LEFT JOIN members fm ON fm.id = c.from_member_id
            WHERE {$mSql}
            UNION ALL
            SELECT c.id, c.member_id, c.from_member_id, c.type, c.amount, c.description, c.status, c.created_at,
                   fm.member_id AS from_mid, fm.full_name AS from_name, fm.username AS from_username
            FROM commissions c
            LEFT JOIN members fm ON fm.id = c.from_member_id
            WHERE {$bSql}
        ) AS u
        ORDER BY u.created_at DESC, u.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($allParams);

    return [
        'rows' => $stmt->fetchAll(),
        'total' => $total,
        'total_pages' => $totalPages,
        'page' => $page,
    ];
}

/**
 * Recent income rows across commissions + split tables.
 * @return list<array>
 */
function income_recent_rows(PDO $pdo, int $memberId, int $limit = 10): array
{
    require_once __DIR__ . '/income_tables.php';
    income_tables_ensure($pdo);
    $limit = max(1, min(50, $limit));
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM (
                SELECT c.id, c.member_id, c.from_member_id, c.type, c.amount, c.description, c.status, c.created_at,
                       fm.member_id AS from_mid, fm.full_name AS from_name
                FROM commissions c
                LEFT JOIN members fm ON fm.id = c.from_member_id
                WHERE c.member_id = ?
                UNION ALL
                SELECT c.id, c.member_id, c.from_member_id, 'matching' AS type, c.amount, c.description, c.status, c.created_at,
                       fm.member_id AS from_mid, fm.full_name AS from_name
                FROM income_matching c
                LEFT JOIN members fm ON fm.id = c.from_member_id
                WHERE c.member_id = ?
                UNION ALL
                SELECT c.id, c.member_id, c.from_member_id, 'dsi' AS type, c.amount, c.description, c.status, c.created_at,
                       fm.member_id AS from_mid, fm.full_name AS from_name
                FROM income_dsi c
                LEFT JOIN members fm ON fm.id = c.from_member_id
                WHERE c.member_id = ? AND c.status <> 'pending'
            ) AS u
            ORDER BY u.created_at DESC, u.id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$memberId, $memberId, $memberId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

