<?php
/**
 * Dedicated income tables for DSI and Matching (separate from commissions).
 */

function income_tables_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS income_dsi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            from_member_id INT NULL,
            amount DECIMAL(12,2) NOT NULL,
            description VARCHAR(255) NULL,
            status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_dsi_member (member_id),
            KEY idx_dsi_status (status),
            KEY idx_dsi_from (from_member_id),
            CONSTRAINT fk_income_dsi_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            CONSTRAINT fk_income_dsi_from FOREIGN KEY (from_member_id) REFERENCES members(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS income_matching (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            from_member_id INT NULL,
            amount DECIMAL(12,2) NOT NULL,
            description VARCHAR(255) NULL,
            status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_match_member (member_id),
            KEY idx_match_status (status),
            KEY idx_match_from (from_member_id),
            CONSTRAINT fk_income_match_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            CONSTRAINT fk_income_match_from FOREIGN KEY (from_member_id) REFERENCES members(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Migrate any remaining legacy rows out of commissions (transactional to avoid duplicates).
    try {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        $pdo->exec("
            INSERT INTO income_dsi (member_id, from_member_id, amount, description, status, created_at)
            SELECT member_id, from_member_id, amount, description, status, created_at
            FROM commissions WHERE type = 'dsi'
        ");
        $pdo->exec("
            INSERT INTO income_matching (member_id, from_member_id, amount, description, status, created_at)
            SELECT member_id, from_member_id, amount, description, status, created_at
            FROM commissions WHERE type = 'matching'
        ");
        $pdo->exec("DELETE FROM commissions WHERE type IN ('dsi', 'matching')");
        if ($ownTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!empty($ownTx) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // ignore migration issues on fresh installs
    }

    $done = true;
}

function income_split_table(string $type): ?string
{
    return match ($type) {
        'dsi' => 'income_dsi',
        'matching' => 'income_matching',
        default => null,
    };
}

/**
 * @return int insert id
 */
function income_split_insert(
    PDO $pdo,
    string $type,
    int $memberId,
    ?int $fromMemberId,
    float $amount,
    string $description,
    string $status = 'pending'
): int {
    income_tables_ensure($pdo);
    $table = income_split_table($type);
    if ($table === null || $memberId <= 0 || $amount <= 0) {
        return 0;
    }
    if (!in_array($status, ['pending', 'paid', 'cancelled'], true)) {
        $status = 'pending';
    }
    $pdo->prepare("
        INSERT INTO {$table} (member_id, from_member_id, amount, description, status)
        VALUES (?,?,?,?,?)
    ")->execute([
        $memberId,
        $fromMemberId && $fromMemberId > 0 ? $fromMemberId : null,
        round($amount, 2),
        $description !== '' ? $description : null,
        $status,
    ]);
    return (int) $pdo->lastInsertId();
}

function income_split_sum(PDO $pdo, string $type, ?int $memberId = null, ?string $status = null): float
{
    income_tables_ensure($pdo);
    $table = income_split_table($type);
    if ($table === null) {
        return 0.0;
    }
    $where = ['1=1'];
    $params = [];
    if ($memberId !== null && $memberId > 0) {
        $where[] = 'member_id = ?';
        $params[] = $memberId;
    }
    if ($status !== null && $status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM ' . $table . ' WHERE ' . implode(' AND ', $where));
    $st->execute($params);
    return (float) $st->fetchColumn();
}

function income_split_count(PDO $pdo, string $type, ?int $memberId = null, ?string $status = null): int
{
    income_tables_ensure($pdo);
    $table = income_split_table($type);
    if ($table === null) {
        return 0;
    }
    $where = ['1=1'];
    $params = [];
    if ($memberId !== null && $memberId > 0) {
        $where[] = 'member_id = ?';
        $params[] = $memberId;
    }
    if ($status !== null && $status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode(' AND ', $where));
    $st->execute($params);
    return (int) $st->fetchColumn();
}

/**
 * @return array{rows:list<array>,total:int,total_pages:int,page:int}
 */
function income_split_fetch_rows(
    PDO $pdo,
    string $type,
    int $memberId,
    string $statusFilter = '',
    int $page = 1,
    int $perPage = 15
): array {
    income_tables_ensure($pdo);
    $table = income_split_table($type);
    $page = max(1, $page);
    $perPage = max(5, min(50, $perPage));
    if ($table === null) {
        return ['rows' => [], 'total' => 0, 'total_pages' => 1, 'page' => 1];
    }

    if ($type === 'dsi' && $statusFilter === 'pending') {
        return ['rows' => [], 'total' => 0, 'total_pages' => 1, 'page' => 1];
    }

    $where = ['c.member_id = ?'];
    $params = [$memberId];
    if (in_array($statusFilter, ['pending', 'paid', 'cancelled'], true)) {
        $where[] = 'c.status = ?';
        $params[] = $statusFilter;
    } elseif ($type === 'dsi') {
        $where[] = "c.status <> 'pending'";
    }
    $whereSql = implode(' AND ', $where);
    $offset = ($page - 1) * $perPage;

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} c WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));

    $stmt = $pdo->prepare("
        SELECT c.*,
               fm.member_id AS from_mid,
               fm.full_name AS from_name,
               fm.username AS from_username
        FROM {$table} c
        LEFT JOIN members fm ON fm.id = c.from_member_id
        WHERE {$whereSql}
        ORDER BY c.id DESC
        LIMIT {$perPage} OFFSET {$offset}
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
 * Admin report rows for a split income table.
 * @return array{rows:list<array>,total:float}
 */
function income_split_admin_report(
    PDO $pdo,
    string $type,
    string $from,
    string $to,
    string $q = '',
    string $status = ''
): array {
    income_tables_ensure($pdo);
    $table = income_split_table($type);
    if ($table === null) {
        return ['rows' => [], 'total' => 0.0];
    }

    $where = ['DATE(c.created_at) BETWEEN ? AND ?'];
    $params = [$from, $to];
    if ($status !== '' && in_array($status, ['pending', 'paid', 'cancelled'], true)) {
        $where[] = 'c.status = ?';
        $params[] = $status;
    } else {
        $where[] = "c.status != 'cancelled'";
    }
    if ($q !== '') {
        $where[] = '(m.member_id LIKE ? OR m.full_name LIKE ? OR m.username LIKE ? OR c.description LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT c.*, m.member_id, m.full_name, m.username,
               fm.member_id AS from_code, fm.full_name AS from_name
        FROM {$table} c
        JOIN members m ON m.id = c.member_id
        LEFT JOIN members fm ON fm.id = c.from_member_id
        WHERE {$whereSql}
        ORDER BY c.id DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $sumStmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.amount),0)
        FROM {$table} c
        JOIN members m ON m.id = c.member_id
        WHERE {$whereSql}
    ");
    $sumStmt->execute($params);

    return [
        'rows' => $rows,
        'total' => (float) $sumStmt->fetchColumn(),
    ];
}

/**
 * @return array{paid:float,pending:float,pending_count:int}
 */
function income_admin_wallet_totals(PDO $pdo): array
{
    income_tables_ensure($pdo);
    $paid = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status = 'paid'")->fetchColumn();
    $paid += (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM income_matching WHERE status = 'paid'")->fetchColumn();
    $paid += (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM income_dsi WHERE status = 'paid'")->fetchColumn();

    $pending = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status = 'pending'")->fetchColumn();
    $pending += (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM income_matching WHERE status = 'pending'")->fetchColumn();
    $pending += (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM income_dsi WHERE status = 'pending'")->fetchColumn();

    $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM commissions WHERE status = 'pending'")->fetchColumn();
    $pendingCount += (int) $pdo->query("SELECT COUNT(*) FROM income_matching WHERE status = 'pending'")->fetchColumn();
    $pendingCount += (int) $pdo->query("SELECT COUNT(*) FROM income_dsi WHERE status = 'pending'")->fetchColumn();

    $active = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE status != 'cancelled'")->fetchColumn();
    $active += (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM income_matching WHERE status != 'cancelled'")->fetchColumn();
    $active += (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM income_dsi WHERE status != 'cancelled'")->fetchColumn();

    return [
        'paid' => $paid,
        'pending' => $pending,
        'pending_count' => $pendingCount,
        'active' => $active,
    ];
}

/**
 * Recent income across commissions + split tables (admin dashboard / member view).
 * @return list<array<string,mixed>>
 */
function income_admin_recent_rows(PDO $pdo, ?int $memberId = null, int $limit = 8): array
{
    income_tables_ensure($pdo);
    $limit = max(1, min(50, $limit));
    $memberSql = $memberId !== null && $memberId > 0 ? ' AND c.member_id = ' . (int) $memberId : '';

    $sql = "
        SELECT * FROM (
            SELECT c.id, c.member_id, c.from_member_id, c.type, c.amount, c.description, c.status, c.created_at,
                   'commission' AS src
            FROM commissions c
            WHERE 1=1 {$memberSql}
            UNION ALL
            SELECT c.id, c.member_id, c.from_member_id, 'matching' AS type, c.amount, c.description, c.status, c.created_at,
                   'matching' AS src
            FROM income_matching c
            WHERE 1=1 {$memberSql}
            UNION ALL
            SELECT c.id, c.member_id, c.from_member_id, 'dsi' AS type, c.amount, c.description, c.status, c.created_at,
                   'dsi' AS src
            FROM income_dsi c
            WHERE 1=1 {$memberSql}
        ) AS u
        ORDER BY u.created_at DESC, u.id DESC
        LIMIT {$limit}
    ";
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    if (!$rows) {
        return [];
    }

    $ids = array_unique(array_map(static fn ($r) => (int) $r['member_id'], $rows));
    $members = [];
    if ($ids) {
        $in = implode(',', $ids);
        foreach ($pdo->query("SELECT id, full_name, member_id AS mid FROM members WHERE id IN ({$in})") as $m) {
            $members[(int) $m['id']] = $m;
        }
    }
    foreach ($rows as &$r) {
        $m = $members[(int) $r['member_id']] ?? null;
        $r['full_name'] = $m['full_name'] ?? '';
        $r['mid'] = $m['mid'] ?? '';
    }
    unset($r);
    return $rows;
}

function income_member_earned_total(PDO $pdo, int $memberId): float
{
    income_tables_ensure($pdo);
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE member_id = ? AND status != 'cancelled'");
    $st->execute([$memberId]);
    $total = (float) $st->fetchColumn();
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM income_matching WHERE member_id = ? AND status != 'cancelled'");
    $st->execute([$memberId]);
    $total += (float) $st->fetchColumn();
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM income_dsi WHERE member_id = ? AND status != 'cancelled'");
    $st->execute([$memberId]);
    $total += (float) $st->fetchColumn();
    return $total;
}

