<?php
/**
 * User income / commission report helpers
 */

/** Full catalog of income types (for historical filters / meta lookup). */
function income_types_catalog(): array
{
    return [
        'binary' => [
            'key' => 'binary',
            'label' => 'Binary Income',
            'short' => 'Binary',
            'file' => 'income-binary.php',
            'kicker' => 'Pair matching',
            'desc' => 'Earnings from left/right binary pairs in your team.',
            'tone' => 'blue',
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
            'kicker' => 'Team match',
            'desc' => 'Matching bonus on binary income earned by your downline.',
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
    if (feature_enabled('feature_binary_income') && plan_uses_binary()) {
        $out['binary'] = $all['binary'];
    }
    if (feature_enabled('feature_referral_income')) {
        $out['referral'] = $all['referral'];
    }
    if (feature_enabled('feature_matching_income') && plan_uses_binary()) {
        $out['matching'] = $all['matching'];
    }
    if (feature_enabled('feature_level_income') && plan_uses_level()) {
        $out['level'] = $all['level'];
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

function income_sum(PDO $pdo, int $memberId, ?string $type = null, ?string $status = null): float
{
    $where = ['member_id = ?'];
    $params = [$memberId];
    if ($type !== null && $type !== '') {
        $where[] = 'type = ?';
        $params[] = $type;
    }
    if ($status !== null && $status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    $sql = 'SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function income_count(PDO $pdo, int $memberId, ?string $type = null, ?string $status = null): int
{
    $where = ['member_id = ?'];
    $params = [$memberId];
    if ($type !== null && $type !== '') {
        $where[] = 'type = ?';
        $params[] = $type;
    }
    if ($status !== null && $status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    $sql = 'SELECT COUNT(*) FROM commissions WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Fetch paginated commission rows for a member.
 * @return array{rows:array,total:int,total_pages:int,page:int}
 */
function income_fetch_rows(PDO $pdo, int $memberId, string $type, string $statusFilter = '', int $page = 1, int $perPage = 15): array
{
    $page = max(1, $page);
    $perPage = max(5, min(50, $perPage));
    $offset = ($page - 1) * $perPage;

    $where = ['c.member_id = ?', 'c.type = ?'];
    $params = [$memberId, $type];
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
