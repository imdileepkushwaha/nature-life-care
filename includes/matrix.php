<?php
/**
 * Matrix plan helpers (N-wide spillover placement).
 */

/** Ensure members.position can store matrix slots (1..N) as well as left/right. */
function members_ensure_flexible_position(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM members LIKE 'position'")->fetch(PDO::FETCH_ASSOC);
        if ($col) {
            $type = strtolower((string) ($col['Type'] ?? ''));
            if (str_starts_with($type, 'enum(')) {
                $pdo->exec("ALTER TABLE members MODIFY COLUMN position VARCHAR(20) NULL");
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    $done = true;
}

function matrix_width(): int
{
    return max(2, min(10, (int) setting('matrix_width', '3')));
}

/**
 * Find first free matrix slot under sponsor via BFS spillover.
 * @return array{placement_id:int,position:string}|null
 */
function reg_find_matrix_placement(PDO $pdo, int $sponsorId, ?int $width = null): ?array
{
    members_ensure_flexible_position($pdo);
    $width = $width ?? matrix_width();
    if ($sponsorId < 1 || $width < 2) {
        return null;
    }

    $queue = [$sponsorId];
    $seen = [$sponsorId => true];
    $guard = 0;

    while ($queue && $guard < 5000) {
        $guard++;
        $nodeId = (int) array_shift($queue);

        $stmt = $pdo->prepare('SELECT id, position FROM members WHERE placement_id = ? ORDER BY CAST(position AS UNSIGNED), id');
        $stmt->execute([$nodeId]);
        $children = $stmt->fetchAll();
        $used = [];
        foreach ($children as $ch) {
            $slot = (string) ($ch['position'] ?? '');
            if ($slot !== '') {
                $used[$slot] = true;
            }
            $cid = (int) $ch['id'];
            if (!isset($seen[$cid])) {
                $seen[$cid] = true;
                $queue[] = $cid;
            }
        }

        for ($s = 1; $s <= $width; $s++) {
            $key = (string) $s;
            if (!isset($used[$key])) {
                return ['placement_id' => $nodeId, 'position' => $key];
            }
        }
    }

    return null;
}

/** Children of a member in matrix order. */
function matrix_children(PDO $pdo, int $memberId): array
{
    members_ensure_flexible_position($pdo);
    $stmt = $pdo->prepare('
        SELECT id, member_id, full_name, status, package_id, position, placement_id
        FROM members
        WHERE placement_id = ?
        ORDER BY CAST(position AS UNSIGNED), id
    ');
    $stmt->execute([$memberId]);
    return $stmt->fetchAll();
}
