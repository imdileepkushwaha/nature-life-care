<?php
/**
 * Shared team / binary helpers for user + admin reuse.
 */

function team_get_member(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT m.id, m.member_id, m.full_name, m.username, m.email, m.phone, m.status,
               m.left_count, m.right_count, m.wallet_balance, m.total_earnings, m.join_date,
               m.position, m.package_id, m.placement_id, m.sponsor_id, m.photo,
               p.name AS package_name,
               s.member_id AS sponsor_mid, s.full_name AS sponsor_name
        FROM members m
        LEFT JOIN packages p ON p.id = m.package_id
        LEFT JOIN members s ON s.id = m.sponsor_id
        WHERE m.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function team_get_child(PDO $pdo, int $parentId, string $position): ?array
{
    $stmt = $pdo->prepare('SELECT id FROM members WHERE placement_id = ? AND position = ? LIMIT 1');
    $stmt->execute([$parentId, $position]);
    $cid = $stmt->fetchColumn();
    return $cid ? team_get_member($pdo, (int) $cid) : null;
}

/**
 * Placement downline BFS under $rootId (binary left/right legs).
 * @return array<int, array>
 */
function team_collect_downline(PDO $pdo, int $rootId, string $leg = 'all'): array
{
    $list = [];
    $queue = [];

    if ($leg === 'all' || $leg === 'left') {
        $queue[] = ['parent' => $rootId, 'position' => 'left', 'level' => 1, 'leg' => 'left'];
    }
    if ($leg === 'all' || $leg === 'right') {
        $queue[] = ['parent' => $rootId, 'position' => 'right', 'level' => 1, 'leg' => 'right'];
    }

    $stmtChild = $pdo->prepare('SELECT id FROM members WHERE placement_id = ? AND position = ? LIMIT 1');
    $stmtMember = $pdo->prepare("
        SELECT m.id, m.member_id, m.full_name, m.username, m.phone, m.email, m.status, m.position,
               m.left_count, m.right_count, m.join_date, m.wallet_balance, m.photo, m.package_id,
               p.name AS package_name,
               s.member_id AS sponsor_mid, s.full_name AS sponsor_name
        FROM members m
        LEFT JOIN packages p ON p.id = m.package_id
        LEFT JOIN members s ON s.id = m.sponsor_id
        WHERE m.id = ?
    ");

    while ($queue) {
        $item = array_shift($queue);
        $stmtChild->execute([$item['parent'], $item['position']]);
        $childId = $stmtChild->fetchColumn();
        if (!$childId) {
            continue;
        }
        $stmtMember->execute([(int) $childId]);
        $member = $stmtMember->fetch();
        if (!$member) {
            continue;
        }
        $member['level'] = $item['level'];
        $member['leg'] = $item['leg'];
        $list[] = $member;

        $queue[] = ['parent' => (int) $member['id'], 'position' => 'left', 'level' => $item['level'] + 1, 'leg' => $item['leg']];
        $queue[] = ['parent' => (int) $member['id'], 'position' => 'right', 'level' => $item['level'] + 1, 'leg' => $item['leg']];
    }

    return $list;
}

/**
 * Sponsor-chain generations (level / unilevel income tree).
 * @return array<int, array>
 */
function team_collect_sponsor_downline(PDO $pdo, int $rootId, int $maxLevel = 50): array
{
    $list = [];
    $maxLevel = max(1, min(100, $maxLevel));
    $stmtKids = $pdo->prepare("
        SELECT m.id, m.member_id, m.full_name, m.username, m.phone, m.email, m.status, m.position,
               m.left_count, m.right_count, m.join_date, m.wallet_balance, m.photo, m.package_id,
               p.name AS package_name,
               s.member_id AS sponsor_mid, s.full_name AS sponsor_name
        FROM members m
        LEFT JOIN packages p ON p.id = m.package_id
        LEFT JOIN members s ON s.id = m.sponsor_id
        WHERE m.sponsor_id = ?
        ORDER BY m.id ASC
    ");

    $frontier = [$rootId];
    $seen = [$rootId => true];
    $level = 1;

    while ($frontier && $level <= $maxLevel) {
        $next = [];
        foreach ($frontier as $sid) {
            $stmtKids->execute([(int) $sid]);
            foreach ($stmtKids->fetchAll() as $member) {
                $id = (int) $member['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $member['level'] = $level;
                $member['leg'] = 'sponsor';
                $list[] = $member;
                $next[] = $id;
            }
        }
        $frontier = $next;
        $level++;
    }

    return $list;
}

/**
 * Matrix / flexible placement downline (all children under placement_id).
 * @return array<int, array>
 */
function team_collect_matrix_downline(PDO $pdo, int $rootId, int $maxLevel = 50): array
{
    $list = [];
    $maxLevel = max(1, min(100, $maxLevel));
    $stmtKids = $pdo->prepare("
        SELECT m.id, m.member_id, m.full_name, m.username, m.phone, m.email, m.status, m.position,
               m.left_count, m.right_count, m.join_date, m.wallet_balance, m.photo, m.package_id,
               p.name AS package_name,
               s.member_id AS sponsor_mid, s.full_name AS sponsor_name
        FROM members m
        LEFT JOIN packages p ON p.id = m.package_id
        LEFT JOIN members s ON s.id = m.sponsor_id
        WHERE m.placement_id = ?
        ORDER BY CAST(m.position AS UNSIGNED) ASC, m.id ASC
    ");

    $frontier = [$rootId];
    $seen = [$rootId => true];
    $level = 1;

    while ($frontier && $level <= $maxLevel) {
        $next = [];
        foreach ($frontier as $pid) {
            $stmtKids->execute([(int) $pid]);
            foreach ($stmtKids->fetchAll() as $member) {
                $id = (int) $member['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $member['level'] = $level;
                $member['leg'] = 'matrix';
                $list[] = $member;
                $next[] = $id;
            }
        }
        $frontier = $next;
        $level++;
    }

    return $list;
}

/**
 * Pick downline walker for current plan mode.
 * @return array{mode:string,rows:array<int,array>}
 */
function team_collect_plan_downline(PDO $pdo, int $rootId, string $leg = 'all', int $maxLevel = 50): array
{
    if (function_exists('plan_uses_binary') && plan_uses_binary()) {
        return ['mode' => 'binary', 'rows' => team_collect_downline($pdo, $rootId, $leg)];
    }
    if (function_exists('plan_uses_matrix') && plan_uses_matrix()) {
        return ['mode' => 'matrix', 'rows' => team_collect_matrix_downline($pdo, $rootId, $maxLevel)];
    }
    return ['mode' => 'sponsor', 'rows' => team_collect_sponsor_downline($pdo, $rootId, $maxLevel)];
}

/** Direct referrals by sponsor_id. */
function team_get_directs(PDO $pdo, int $sponsorId): array
{
    $stmt = $pdo->prepare("
        SELECT m.id, m.member_id, m.full_name, m.username, m.phone, m.email, m.status,
               m.position, m.join_date, m.left_count, m.right_count, m.wallet_balance, m.photo, m.package_id,
               p.name AS package_name
        FROM members m
        LEFT JOIN packages p ON p.id = m.package_id
        WHERE m.sponsor_id = ?
        ORDER BY m.join_date DESC, m.id DESC
    ");
    $stmt->execute([$sponsorId]);
    return $stmt->fetchAll();
}

function team_direct_count(PDO $pdo, int $sponsorId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM members WHERE sponsor_id = ?');
    $stmt->execute([$sponsorId]);
    return (int) $stmt->fetchColumn();
}

/** True if $candidateId is self or in plan-appropriate downline of $rootId. */
function team_is_under(PDO $pdo, int $rootId, int $candidateId): bool
{
    if ($rootId === $candidateId) {
        return true;
    }
    $pack = team_collect_plan_downline($pdo, $rootId, 'all');
    foreach ($pack['rows'] as $m) {
        if ((int) $m['id'] === $candidateId) {
            return true;
        }
    }
    return false;
}

/**
 * Group downline rows by level.
 * @return array<int, array>
 */
function team_group_by_level(array $downline): array
{
    $grouped = [];
    foreach ($downline as $m) {
        $lvl = (int) ($m['level'] ?? 0);
        if ($lvl < 1) {
            continue;
        }
        $grouped[$lvl][] = $m;
    }
    ksort($grouped);
    return $grouped;
}

/** Binary tree renderer for user panel. Vacant slots can open Add Member when $allowAdd. */
function team_render_tree(
    PDO $pdo,
    ?array $member,
    int $depth,
    int $maxLevels,
    int $viewRootId,
    bool $allowAdd = false,
    ?int $vacantParentId = null,
    ?string $vacantPos = null,
    ?array $vacantParent = null
): void {
    $level = $depth + 1;
    echo '<li>';

    if ($member) {
        echo '<div class="ut-item-node">';
        team_render_node($member, $level, $depth === 0, $viewRootId);
        echo '</div>';
        if ($level < $maxLevels) {
            $left = team_get_child($pdo, (int) $member['id'], 'left');
            $right = team_get_child($pdo, (int) $member['id'], 'right');
            echo '<ul>';
            team_render_tree($pdo, $left, $depth + 1, $maxLevels, $viewRootId, $allowAdd, (int) $member['id'], 'left', $member);
            team_render_tree($pdo, $right, $depth + 1, $maxLevels, $viewRootId, $allowAdd, (int) $member['id'], 'right', $member);
            echo '</ul>';
        }
    } else {
        echo '<div class="ut-item-node">';
        if ($allowAdd && $vacantParentId && $vacantPos && $vacantParent) {
            team_render_vacant_node(
                $vacantParentId,
                $vacantPos,
                (string) $vacantParent['full_name'],
                (string) $vacantParent['member_id'],
                $level
            );
        } else {
            echo '<div class="ut-node vacant">';
            echo '<span class="ut-avatar vacant-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg></span>';
            echo '<div class="ut-name">Vacant</div>';
            echo '<div class="ut-level">LVL ' . $level . '</div>';
            echo '</div>';
        }
        echo '</div>';
        if ($level < $maxLevels && $vacantParentId && $vacantPos && $vacantParent) {
            echo '<ul>';
            team_render_tree($pdo, null, $depth + 1, $maxLevels, $viewRootId, $allowAdd, $vacantParentId, $vacantPos, $vacantParent);
            team_render_tree($pdo, null, $depth + 1, $maxLevels, $viewRootId, $allowAdd, $vacantParentId, $vacantPos, $vacantParent);
            echo '</ul>';
        } elseif ($level < $maxLevels) {
            echo '<ul>';
            team_render_tree($pdo, null, $depth + 1, $maxLevels, $viewRootId, $allowAdd);
            team_render_tree($pdo, null, $depth + 1, $maxLevels, $viewRootId, $allowAdd);
            echo '</ul>';
        }
    }

    echo '</li>';
}

function team_render_vacant_node(int $parentId, string $position, string $parentName, string $parentCode, int $level): void
{
    $lvl = max(1, $level);
    echo '<button type="button" class="ut-node vacant is-add"';
    echo ' data-parent-id="' . (int) $parentId . '"';
    echo ' data-position="' . e($position) . '"';
    echo ' data-parent-name="' . e($parentName) . '"';
    echo ' data-parent-code="' . e($parentCode) . '"';
    echo ' data-level="' . $lvl . '"';
    echo ' title="Add member under ' . e($parentCode ?: $parentName) . ' (' . e(strtoupper($position)) . ')">';
    echo '<span class="ut-avatar vacant-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span>';
    echo '<div class="ut-name">+ Add</div>';
    echo '<div class="ut-level">LVL ' . $lvl . '</div>';
    echo '</button>';
}

function team_render_node(array $member, int $level, bool $isRoot, int $viewRootId): void
{
    $isInactive = ($member['status'] ?? '') !== 'active';
    $noPlan = empty($member['package_id']);
    $cls = 'ut-node filled';
    if ($isRoot) {
        $cls .= ' is-root';
    }
    if ($noPlan) {
        $cls .= ' is-no-plan';
    } elseif ($isInactive) {
        $cls .= ' is-inactive';
    }

    $titleBits = [$member['full_name'], $member['member_id']];
    if ($noPlan) {
        $titleBits[] = 'No plan';
    }
    if ($isInactive) {
        $titleBits[] = ucfirst((string) ($member['status'] ?? 'inactive'));
    }

    $href = 'my-treeview.php?root=' . (int) $member['id'];
    echo '<a href="' . $href . '" class="' . $cls . '" title="' . e(implode(' · ', $titleBits)) . '">';
    echo '<span class="ut-avatar">';
    echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    echo '</span>';
    echo '<div class="ut-name">' . e($member['username']) . '</div>';
    echo '<div class="ut-code">' . e($member['member_id']) . '</div>';
    if ($noPlan) {
        echo '<div class="ut-plan-tag">No Plan</div>';
    } else {
        echo '<div class="ut-level">LVL ' . $level . '</div>';
    }
    echo '</a>';
}

function team_status_pill($memberOrStatus, $packageId = null): string
{
    if (is_array($memberOrStatus)) {
        if (function_exists('member_effective_status')) {
            $s = member_effective_status($memberOrStatus);
        } else {
            $s = strtolower((string) ($memberOrStatus['status'] ?? 'inactive'));
            if ($s !== 'blocked' && empty($memberOrStatus['package_id'])) {
                $s = 'inactive';
            }
        }
    } else {
        $s = strtolower((string) $memberOrStatus);
        if ($packageId !== null && empty($packageId) && $s === 'active') {
            $s = 'inactive';
        }
    }
    $cls = match ($s) {
        'active' => 'is-ok',
        'blocked' => 'is-bad',
        'inactive' => 'is-wait',
        default => 'is-muted',
    };
    return '<span class="team-pill ' . $cls . '">' . e(ucfirst($s)) . '</span>';
}
