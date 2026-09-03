<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/procedures.php';
require_once __DIR__ . '/../includes/wallet.php';
require_admin();
feature_guard_admin_page('tree-view');
$pageTitle = 'Tree View';

$rootId = (int) ($_GET['root'] ?? 0);
$search = trim($_GET['q'] ?? '');
// Always show exactly 4 levels like attachment (LVL 1 → LVL 4)
$maxDepth = 4;

function tv_updateUplineCounts(PDO $pdo, int $placementId, string $position): void
{
    if (sp_call_update_upline_counts($pdo, $placementId, $position)) {
        return;
    }

    $current = $placementId;
    $side = $position;
    $guard = 0;
    while ($current && $guard < 100) {
        $col = $side === 'left' ? 'left_count' : 'right_count';
        $pdo->prepare("UPDATE members SET $col = $col + 1 WHERE id = ?")->execute([$current]);
        $stmt = $pdo->prepare('SELECT placement_id, position FROM members WHERE id = ?');
        $stmt->execute([$current]);
        $row = $stmt->fetch();
        if (!$row || !$row['placement_id']) {
            break;
        }
        $current = (int) $row['placement_id'];
        $side = $row['position'] ?: $side;
        $guard++;
    }
}

$formErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tree_add') {
    if (!feature_registration_uses_binary_placement()) {
        flash('error', 'Binary tree placement is disabled for this Level-only plan.');
        header('Location: tree-view.php');
        exit;
    }
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $packageId = (int) ($_POST['package_id'] ?? 0) ?: null;
    $parentId = (int) ($_POST['parent_id'] ?? 0);
    $position = $_POST['position'] ?? '';
    $sponsorId = (int) ($_POST['sponsor_id'] ?? 0) ?: $parentId;
    $returnRoot = (int) ($_POST['return_root'] ?? $parentId);
    $returnQ = trim($_POST['return_q'] ?? '');
    $returnDepth = 4;

    if ($fullName === '') {
        $formErrors[] = 'Full name is required.';
    }
    if ($username === '') {
        $formErrors[] = 'Username is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $formErrors[] = 'Valid email is required.';
    }
    if (strlen($password) < 6) {
        $formErrors[] = 'Password must be at least 6 characters.';
    }
    if ($parentId < 1 || !in_array($position, ['left', 'right'], true)) {
        $formErrors[] = 'Invalid placement slot.';
    }

    if (!$formErrors) {
        $check = $pdo->prepare('SELECT id FROM members WHERE username = ? OR email = ?');
        $check->execute([$username, $email]);
        if ($check->fetch()) {
            $formErrors[] = 'Username or email already exists.';
        }
    }

    if (!$formErrors) {
        $parentCheck = $pdo->prepare("SELECT id FROM members WHERE id = ? AND status = 'active'");
        $parentCheck->execute([$parentId]);
        if (!$parentCheck->fetch()) {
            $formErrors[] = 'Parent member is invalid or inactive.';
        }
    }

    $placementId = null;
    if (!$formErrors) {
        $slot = $pdo->prepare('SELECT id FROM members WHERE placement_id = ? AND position = ? LIMIT 1');
        $slot->execute([$parentId, $position]);
        if ($slot->fetch()) {
            // Slot already taken — place on next free node down this leg
            $walk = $parentId;
            $found = null;
            for ($i = 0; $i < 50; $i++) {
                $slot->execute([$walk, $position]);
                $child = $slot->fetch();
                if (!$child) {
                    $found = $walk;
                    break;
                }
                $walk = (int) $child['id'];
            }
            if ($found) {
                $placementId = $found;
            } else {
                $formErrors[] = 'No free ' . strtoupper($position) . ' slot on this leg.';
            }
        } else {
            $placementId = $parentId;
        }
    }

    if (!$formErrors && $placementId) {
        $memberCode = generate_member_id($pdo);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('
            INSERT INTO members (member_id, username, email, password, full_name, phone, sponsor_id, placement_id, position, package_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $memberCode, $username, $email, $hash, $fullName, $phone ?: null,
            $sponsorId, $placementId, $position, $packageId,
        ]);
        $newId = (int) $pdo->lastInsertId();
        tv_updateUplineCounts($pdo, $placementId, $position);

        if ($sponsorId && $packageId && feature_enabled('feature_referral_income')) {
            $pkg = $pdo->prepare('SELECT amount FROM packages WHERE id = ?');
            $pkg->execute([$packageId]);
            $amount = (float) ($pkg->fetch()['amount'] ?? 0);
            $pct = (float) setting('referral_commission_percent', '5');
            $comm = round($amount * $pct / 100, 2);
            if ($comm > 0) {
                $desc = "Referral bonus from $memberCode";
                $pdo->prepare('INSERT INTO commissions (member_id, from_member_id, type, amount, description, status) VALUES (?,?,?,?,?,?)')
                    ->execute([$sponsorId, $newId, 'referral', $comm, $desc, 'paid']);
                $cid = (int) $pdo->lastInsertId();
                wallet_credit($pdo, $sponsorId, 'income', $comm, 'commission', $cid ?: null, $desc);
            }
        }

        log_activity('member_add', "Tree add $memberCode under parent #$placementId ($position)");
        flash('success', "Member $memberCode added under " . strtoupper($position) . " slot.");
        $redir = 'tree-view.php?root=' . $returnRoot . '&depth=' . $returnDepth;
        if ($returnQ !== '') {
            $redir .= '&q=' . urlencode($returnQ);
        }
        header('Location: ' . $redir);
        exit;
    }

    $rootId = $returnRoot ?: $rootId;
    $search = $returnQ;
    flash('error', implode(' ', $formErrors));
}

if ($search !== '') {
    $stmt = $pdo->prepare('SELECT id FROM members WHERE member_id = ? OR username = ? LIMIT 1');
    $stmt->execute([$search, $search]);
    $found = $stmt->fetch();
    if ($found) {
        $rootId = (int) $found['id'];
    }
}

if (!$rootId) {
    $rootId = (int) $pdo->query('SELECT id FROM members ORDER BY id ASC LIMIT 1')->fetchColumn();
}

function tv_getMember(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT m.id, m.member_id, m.full_name, m.username, m.email, m.phone, m.status,
               m.left_count, m.right_count, m.wallet_balance, m.total_earnings, m.join_date,
               m.position, m.package_id, p.name AS package_name,
               s.member_id AS sponsor_mid, s.full_name AS sponsor_name
        FROM members m
        LEFT JOIN packages p ON p.id = m.package_id
        LEFT JOIN members s ON s.id = m.sponsor_id
        WHERE m.id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function tv_getChild(PDO $pdo, int $parentId, string $position): ?array
{
    $stmt = $pdo->prepare('SELECT id FROM members WHERE placement_id = ? AND position = ? LIMIT 1');
    $stmt->execute([$parentId, $position]);
    $row = $stmt->fetch();
    return $row ? tv_getMember($pdo, (int) $row['id']) : null;
}

function tv_renderMemberNode(array $member, int $level, bool $isViewRoot = false): void
{
    $lvl = max(1, $level);
    $isInactive = ($member['status'] ?? '') !== 'active';
    $noPlan = empty($member['package_id']) || empty($member['package_name']);
    $isRed = $isInactive || $noPlan;

    $tooltip = htmlspecialchars(json_encode([
        'id' => $member['member_id'],
        'name' => $member['full_name'],
        'user' => $member['username'],
        'email' => $member['email'] ?? '',
        'phone' => $member['phone'] ?? '',
        'status' => $member['status'],
        'package' => $member['package_name'] ?? 'No plan',
        'left' => (int) $member['left_count'],
        'right' => (int) $member['right_count'],
        'wallet' => number_format((float) $member['wallet_balance'], 2),
        'sponsor' => $member['sponsor_mid'] ? ($member['sponsor_mid'] . ' — ' . $member['sponsor_name']) : '—',
        'joined' => $member['join_date'] ? date('d M Y', strtotime($member['join_date'])) : '—',
        'profile' => 'member-view.php?id=' . (int) $member['id'],
        'tree' => 'tree-view.php?root=' . (int) $member['id'],
        'alert' => $isRed ? ($isInactive && $noPlan ? 'Inactive + no plan' : ($isInactive ? 'Inactive' : 'No plan')) : '',
    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

    $cls = 'tv-node filled';
    if ($isViewRoot) {
        $cls .= ' is-root';
    }
    if ($isRed) {
        $cls .= ' is-red';
    }

    $titleBits = ['Click to open this member\'s 4-level tree'];
    if ($isInactive) {
        $titleBits[] = 'Inactive';
    }
    if ($noPlan) {
        $titleBits[] = 'No plan';
    }

    echo '<a href="tree-view.php?root=' . (int) $member['id'] . '" class="' . $cls . '" data-tooltip="' . $tooltip . '" title="' . htmlspecialchars(implode(' · ', $titleBits)) . '">';
    echo '<span class="tv-avatar filled-ico">';
    echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    echo '<span class="tv-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></span>';
    echo '</span>';
    echo '<div class="tv-name">' . htmlspecialchars($member['username']) . '</div>';
    echo '<div class="tv-level">LVL ' . $lvl . '</div>';
    echo '</a>';
}

function tv_renderVacantNode(int $parentId, string $position, string $parentName, string $parentCode, int $level): void
{
    $lvl = max(1, $level);
    echo '<button type="button" class="tv-node vacant"';
    echo ' data-parent-id="' . (int) $parentId . '"';
    echo ' data-position="' . htmlspecialchars($position) . '"';
    echo ' data-parent-name="' . htmlspecialchars($parentName) . '"';
    echo ' data-parent-code="' . htmlspecialchars($parentCode) . '"';
    echo ' data-level="' . $lvl . '"';
    echo ' title="Add member under ' . htmlspecialchars($parentCode ?: $parentName) . ' (' . htmlspecialchars(strtoupper($position)) . ')">';
    echo '<span class="tv-avatar vacant-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span>';
    echo '<div class="tv-add">+ Add Direct</div>';
    echo '<div class="tv-level">LVL ' . $lvl . '</div>';
    echo '</button>';
}

/**
 * Complete binary tree to $maxLevels (LVL 1..4).
 * Every vacant slot (LVL 2–4) is clickable Add Direct under nearest filled parent.
 */
function tv_renderBinary(
    PDO $pdo,
    ?array $member,
    int $depth,
    int $maxLevels,
    ?int $vacantParentId = null,
    ?string $vacantPos = null,
    ?array $vacantParent = null
): void {
    $level = $depth + 1;
    echo '<li>';

    if ($member) {
        echo '<div class="tv-item-node">';
        tv_renderMemberNode($member, $level, $depth === 0);
        echo '</div>';
        if ($level < $maxLevels) {
            $left = tv_getChild($pdo, (int) $member['id'], 'left');
            $right = tv_getChild($pdo, (int) $member['id'], 'right');
            echo '<ul>';
            tv_renderBinary($pdo, $left, $depth + 1, $maxLevels, (int) $member['id'], 'left', $member);
            tv_renderBinary($pdo, $right, $depth + 1, $maxLevels, (int) $member['id'], 'right', $member);
            echo '</ul>';
        }
    } else {
        // Vacant at any level — place under nearest filled parent/slot
        echo '<div class="tv-item-node">';
        if ($vacantParentId && $vacantPos && $vacantParent) {
            tv_renderVacantNode(
                $vacantParentId,
                $vacantPos,
                (string) $vacantParent['full_name'],
                (string) $vacantParent['member_id'],
                $level
            );
        } else {
            echo '<div class="tv-node vacant is-ghost"><span class="tv-avatar vacant-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span><div class="tv-add">+ Add Direct</div><div class="tv-level">LVL ' . $level . '</div></div>';
        }
        echo '</div>';

        if ($level < $maxLevels && $vacantParentId && $vacantPos && $vacantParent) {
            echo '<ul>';
            // Deeper vacant slots (LVL 3/4) also add under same filled parent + side
            tv_renderBinary($pdo, null, $depth + 1, $maxLevels, $vacantParentId, $vacantPos, $vacantParent);
            tv_renderBinary($pdo, null, $depth + 1, $maxLevels, $vacantParentId, $vacantPos, $vacantParent);
            echo '</ul>';
        }
    }

    echo '</li>';
}

$root = $rootId ? tv_getMember($pdo, $rootId) : null;
$packages = $pdo->query("SELECT id, name, amount FROM packages WHERE status = 'active' ORDER BY amount")->fetchAll();

$uplineParent = null;
if ($root && !empty($root['id'])) {
    $upStmt = $pdo->prepare('SELECT placement_id FROM members WHERE id = ?');
    $upStmt->execute([(int) $root['id']]);
    $upPid = (int) ($upStmt->fetchColumn() ?: 0);
    if ($upPid > 0) {
        $uplineParent = tv_getMember($pdo, $upPid);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel tv-panel">
    <div class="panel-header">
        <div>
            <h2>Tree View</h2>
            <p class="members-sub">
                <?php if ($root): ?>
                    Showing <strong><?= e($root['username']) ?></strong> (<?= e($root['member_id']) ?>) — 4 levels. Click any member to open their tree.
                <?php else: ?>
                    Binary genealogy — 4 levels (LVL 1 to LVL 4)
                <?php endif; ?>
            </p>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
            <?php if ($uplineParent): ?>
            <a href="tree-view.php?root=<?= (int) $uplineParent['id'] ?>" class="btn btn-outline btn-sm">↑ Parent (<?= e($uplineParent['username']) ?>)</a>
            <?php endif; ?>
            <a href="tree-view.php" class="btn btn-outline btn-sm">Full Root Tree</a>
            <a href="downline.php<?= $root ? '?q=' . urlencode($root['member_id']) : '' ?>" class="btn btn-outline btn-sm">View Downline</a>
            <?php if ($root): ?>
            <a href="member-view.php?id=<?= (int) $root['id'] ?>" class="btn btn-outline btn-sm">Profile</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="panel-body">
        <form class="filters" method="get">
            <div class="form-group">
                <label>Member ID / Username</label>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="<?= e(member_id_prefix() . str_pad('1', member_id_pad(), '0', STR_PAD_LEFT)) ?>">
            </div>
            <button type="submit" class="btn btn-primary">Show Tree</button>
            <a href="tree-view.php" class="btn btn-outline">Reset</a>
        </form>
    </div>

    <div class="tv-board">
        <?php if (!$root): ?>
            <div class="empty-state" style="padding:2rem">
                <strong>No members yet</strong>
                <span><a href="member-add.php">Add first member</a> to start the tree.</span>
            </div>
        <?php else: ?>
            <div class="tv-scroll">
                <div class="tv-tree">
                    <ul>
                        <?php tv_renderBinary($pdo, $root, 0, $maxDepth); ?>
                    </ul>
                </div>
            </div>
            <div class="tv-tip">
                <span class="tv-tip-ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </span>
                <p>Tip: <strong>Click a member</strong> to open their 4-level tree. Click <strong>+ Add Direct</strong> to register under that sponsor slot.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="tv-tooltip" id="tvTooltip" hidden></div>

<div class="tv-modal" id="tvAddModal" hidden>
    <div class="tv-modal-backdrop" data-tv-close></div>
    <div class="tv-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="tvModalTitle">
        <div class="tv-modal-head">
            <div>
                <h3 id="tvModalTitle">Add Direct Member</h3>
                <p class="tv-modal-sub" id="tvModalSub">Place under selected slot</p>
            </div>
            <button type="button" class="tv-modal-x" data-tv-close aria-label="Close">&times;</button>
        </div>
        <form method="post" class="tv-modal-body" id="tvAddForm">
            <input type="hidden" name="action" value="tree_add">
            <input type="hidden" name="parent_id" id="tvParentId" value="">
            <input type="hidden" name="position" id="tvPosition" value="">
            <input type="hidden" name="sponsor_id" id="tvSponsorId" value="">
            <input type="hidden" name="return_root" value="<?= (int) ($root['id'] ?? 0) ?>">
            <input type="hidden" name="return_depth" value="4">
            <input type="hidden" name="return_q" value="<?= e($search) ?>">

            <div class="tv-slot-chip" id="tvSlotChip">—</div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" required>
                </div>
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone">
                </div>
                <div class="form-group">
                    <label>Password *</label>
                    <div class="password-field">
                        <input type="password" name="password" required minlength="6" autocomplete="new-password">
                        <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                            <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Package</label>
                    <select name="package_id">
                        <option value="">— Select —</option>
                        <?php foreach ($packages as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= currency((float) $p['amount']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="tv-modal-foot">
                <button type="button" class="btn btn-outline" data-tv-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Register Member</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
