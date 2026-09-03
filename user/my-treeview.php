<?php
$pageTitle = 'My Treeview';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/team.php';
require_once __DIR__ . '/../includes/registration.php';
require_once __DIR__ . '/../includes/tpin.php';
require_once __DIR__ . '/../includes/activation.php';

require_user();
feature_guard_user_page('my-treeview');
$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

$uid = (int) $user['id'];
$viewRootId = (int) ($_GET['root'] ?? $_POST['return_root'] ?? $uid);
$formErrors = [];
$formValues = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'phone' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tree_add') {
    if (!feature_registration_uses_binary_placement()) {
        flash('error', 'Binary tree placement is disabled for this Level-only plan.');
        header('Location: my-treeview.php');
        exit;
    }
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $parentId = (int) ($_POST['parent_id'] ?? 0);
    $position = strtolower(trim($_POST['position'] ?? ''));
    $pinCode = trim((string) ($_POST['tpin_code'] ?? ''));
    if (!feature_module_allowed('tpin')) {
        $pinCode = '';
    }
    $returnRoot = (int) ($_POST['return_root'] ?? $uid);
    if ($returnRoot <= 0 || !team_is_under($pdo, $uid, $returnRoot)) {
        $returnRoot = $uid;
    }
    $viewRootId = $returnRoot;

    $formValues = [
        'full_name' => $fullName,
        'username' => $username,
        'email' => $email,
        'phone' => $phone,
    ];

    if ($fullName === '') {
        $formErrors[] = 'Full name is required.';
    }
    if ($username === '') {
        $username = reg_unique_username($pdo, $fullName !== '' ? $fullName : 'member');
        $formValues['username'] = $username;
    } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $username)) {
        $formErrors[] = 'Username must be 3–40 characters (letters, numbers, . _ -).';
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
        $emailCheck = $pdo->prepare('SELECT id FROM members WHERE LOWER(email) = ? LIMIT 1');
        $emailCheck->execute([$email]);
        if ($emailCheck->fetch()) {
            $formErrors[] = 'This email is already registered. Please use a different email.';
        }
    }

    if (!$formErrors) {
        $userCheck = $pdo->prepare('SELECT id FROM members WHERE username = ? LIMIT 1');
        $userCheck->execute([$username]);
        if ($userCheck->fetch()) {
            $formErrors[] = 'Username already exists. Please choose another.';
        }
    }

    if (!$formErrors && !team_is_under($pdo, $uid, $parentId)) {
        $formErrors[] = 'You can only place members under your own tree.';
    }

    if (!$formErrors) {
        $parentCheck = $pdo->prepare('SELECT id, status FROM members WHERE id = ? LIMIT 1');
        $parentCheck->execute([$parentId]);
        $parentRow = $parentCheck->fetch();
        if (!$parentRow) {
            $formErrors[] = 'Parent member is invalid.';
        } elseif (($parentRow['status'] ?? '') === 'blocked') {
            $formErrors[] = 'Parent member is blocked.';
        }
    }

    $placementId = null;
    if (!$formErrors) {
        $placementId = reg_find_binary_placement($pdo, $parentId, $position);
        if (!$placementId) {
            $formErrors[] = 'No free ' . strtoupper($position) . ' slot on this leg.';
        } elseif (!team_is_under($pdo, $uid, (int) $placementId)) {
            $formErrors[] = 'Placement is outside your tree.';
        }
    }

    if (!$formErrors && $pinCode !== '') {
        tpin_ensure_tables($pdo);
        $pinPreview = tpin_find_usable($pdo, $pinCode, $uid);
        if (!$pinPreview || (int) ($pinPreview['assigned_to'] ?? 0) !== $uid) {
            $formErrors[] = 'T-Pin is invalid or not in your pin wallet.';
        }
    }

    if (!$formErrors && $placementId) {
        try {
            $memberCode = function_exists('generate_member_id')
                ? generate_member_id($pdo)
                : reg_unique_member_id($pdo);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('
                INSERT INTO members (member_id, username, email, password, full_name, phone, sponsor_id, placement_id, position, package_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
            ')->execute([
                $memberCode,
                $username,
                $email,
                $hash,
                $fullName,
                $phone !== '' ? $phone : null,
                $uid,
                (int) $placementId,
                $position,
            ]);
            $newId = (int) $pdo->lastInsertId();
            reg_update_upline_counts($pdo, (int) $placementId, $position);

            $msg = 'Member ' . $memberCode . ' added under ' . strtoupper($position) . '.';
            if ($pinCode !== '') {
                $newMember = team_get_member($pdo, $newId);
                if ($newMember) {
                    $act = tpin_redeem_for_target($pdo, $user, $newMember, $pinCode);
                    if ($act['ok']) {
                        $pkgName = $act['package']['name'] ?? 'package';
                        $msg .= ' Activated with T-Pin (' . $pkgName . ').';
                    } else {
                        $msg .= ' Registered without activation: ' . ($act['error'] ?? 'T-Pin failed.');
                    }
                }
            }

            log_activity('member_tree_add', "User #{$uid} added {$memberCode} under placement #{$placementId} ({$position})");
            flash('success', $msg);
            header('Location: my-treeview.php?root=' . $returnRoot);
            exit;
        } catch (Throwable $e) {
            $formErrors[] = 'Could not register member. Please try again.';
        }
    }

    if ($formErrors) {
        flash('error', implode(' ', $formErrors));
    }
}

if ($viewRootId <= 0 || !team_is_under($pdo, $uid, $viewRootId)) {
    $viewRootId = $uid;
}

$root = team_get_member($pdo, $viewRootId);
$maxLevels = 4;
$isSelf = $viewRootId === $uid;
$myPins = tpin_member_unused($pdo, $uid);

$parent = null;
if ($root && !empty($root['placement_id'])) {
    $pid = (int) $root['placement_id'];
    if ($pid > 0 && team_is_under($pdo, $uid, $pid)) {
        $parent = team_get_member($pdo, $pid);
    } elseif ($pid === $uid) {
        $parent = team_get_member($pdo, $uid);
    }
}

require_once __DIR__ . '/includes/header.php';
$flash = get_flash();
$reopenModal = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tree_add' && $formErrors;
$featTpin = feature_module_allowed('tpin');
?>
<div class="up-page-head">
    <div>
        <h1>My Treeview</h1>
        <p>Binary placement tree — 4 levels. Click Vacant to add a member<?= $featTpin ? ' (optional T-Pin activate)' : '' ?>.</p>
    </div>
    <div class="team-head-actions">
        <?php if (!$isSelf): ?>
            <a href="my-treeview.php" class="up-btn up-btn-outline">Back to Me</a>
        <?php endif; ?>
        <?php if ($parent): ?>
            <a href="my-treeview.php?root=<?= (int) $parent['id'] ?>" class="up-btn up-btn-outline">Upline</a>
        <?php endif; ?>
        <a href="my-downline.php" class="up-btn up-btn-primary">Downline List</a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="up-alert up-alert-<?= $flash['type'] === 'error' ? 'err' : 'ok' ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if ($root): ?>
<div class="team-stats">
    <article class="team-stat g-gold">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
        <div>
            <span class="team-stat-label">Root</span>
            <strong class="is-sm"><?= e($root['username']) ?></strong>
            <small><?= e($root['member_id']) ?></small>
        </div>
    </article>
    <article class="team-stat g-green">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg></span>
        <div>
            <span class="team-stat-label">Left Count</span>
            <strong><?= (int) $root['left_count'] ?></strong>
        </div>
    </article>
    <article class="team-stat g-orange">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg></span>
        <div>
            <span class="team-stat-label">Right Count</span>
            <strong><?= (int) $root['right_count'] ?></strong>
        </div>
    </article>
    <?php if ($featTpin): ?>
    <article class="team-stat g-blue">
        <span class="team-stat-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg></span>
        <div>
            <span class="team-stat-label">Your T-Pins</span>
            <strong><?= count($myPins) ?></strong>
            <small>Unused in wallet</small>
        </div>
    </article>
    <?php endif; ?>
</div>
<?php endif; ?>

<section class="team-card ut-panel">
    <div class="team-banner is-gold">
        <div class="team-banner-main">
            <span class="team-banner-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
            </span>
            <div>
                <span class="team-banner-kicker">Visual binary</span>
                <h2>Tree Board</h2>
            </div>
        </div>
        <div class="team-banner-legend">
            <span><i class="dot root"></i> Root</span>
            <span><i class="dot filled"></i> Member</span>
            <span><i class="dot noplan"></i> No Plan</span>
            <span><i class="dot vacant"></i> Vacant / Add</span>
        </div>
    </div>
    <div class="ut-board">
        <div class="ut-scroll">
            <?php if ($root): ?>
            <div class="ut-tree">
                <ul>
                    <?php team_render_tree($pdo, $root, 0, $maxLevels, $uid, true); ?>
                </ul>
            </div>
            <?php else: ?>
                <div class="team-empty-state"><strong>Unable to load tree</strong></div>
            <?php endif; ?>
        </div>
    </div>
    <p class="ut-tree-tip">Tip: click <strong>+ Add</strong> on a vacant slot to register a member<?= $featTpin ? '. You can optionally activate with a T-Pin' : '' ?>.</p>
</section>

<div class="ut-modal" id="utAddModal" hidden>
    <div class="ut-modal-backdrop" data-ut-close></div>
    <div class="ut-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="utModalTitle">
        <div class="ut-modal-head">
            <div>
                <h3 id="utModalTitle">Add Member</h3>
                <p class="ut-modal-sub" id="utModalSub">Place under selected vacant slot</p>
            </div>
            <button type="button" class="ut-modal-x" data-ut-close aria-label="Close">&times;</button>
        </div>
        <form method="post" class="ut-modal-body" id="utAddForm" autocomplete="off">
            <input type="hidden" name="action" value="tree_add">
            <input type="hidden" name="parent_id" id="utParentId" value="<?= $reopenModal ? (int) ($_POST['parent_id'] ?? 0) : '' ?>">
            <input type="hidden" name="position" id="utPosition" value="<?= $reopenModal ? e((string) ($_POST['position'] ?? '')) : '' ?>">
            <input type="hidden" name="parent_name" id="utParentName" value="<?= $reopenModal ? e((string) ($_POST['parent_name'] ?? '')) : '' ?>">
            <input type="hidden" name="parent_code" id="utParentCode" value="<?= $reopenModal ? e((string) ($_POST['parent_code'] ?? '')) : '' ?>">
            <input type="hidden" name="return_root" value="<?= (int) $viewRootId ?>">

            <div class="ut-slot-chip" id="utSlotChip">—</div>

            <div class="ut-form-grid">
                <div class="form-group">
                    <label for="ut_full_name">Full Name *</label>
                    <input type="text" name="full_name" id="ut_full_name" required value="<?= e($formValues['full_name']) ?>">
                </div>
                <div class="form-group">
                    <label for="ut_username">Username</label>
                    <input type="text" name="username" id="ut_username" value="<?= e($formValues['username']) ?>" placeholder="Auto from name if blank" maxlength="40">
                </div>
                <div class="form-group">
                    <label for="ut_email">Email *</label>
                    <input type="email" name="email" id="ut_email" required value="<?= e($formValues['email']) ?>">
                </div>
                <div class="form-group">
                    <label for="ut_phone">Phone</label>
                    <input type="text" name="phone" id="ut_phone" value="<?= e($formValues['phone']) ?>">
                </div>
                <div class="form-group">
                    <label for="ut_password">Password *</label>
                    <input type="password" name="password" id="ut_password" required minlength="6" autocomplete="new-password">
                </div>
                <?php if ($featTpin): ?>
                <div class="form-group">
                    <label for="ut_tpin">T-Pin (optional activate)</label>
                    <?php if ($myPins): ?>
                        <select name="tpin_code" id="ut_tpin">
                            <option value="">— Register only —</option>
                            <?php foreach ($myPins as $p): ?>
                                <option value="<?= e(tpin_format_code((string) $p['pin_code'])) ?>" <?= ($reopenModal && trim((string) ($_POST['tpin_code'] ?? '')) === tpin_format_code((string) $p['pin_code'])) ? 'selected' : '' ?>>
                                    <?= e(tpin_format_code((string) $p['pin_code'])) ?> · <?= e($p['package_name']) ?> (<?= currency((float) $p['package_amount']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" name="tpin_code" id="ut_tpin" value="<?= $reopenModal ? e((string) ($_POST['tpin_code'] ?? '')) : '' ?>" placeholder="No unused pins in wallet" maxlength="20">
                        <small class="ut-field-hint">You have no unused T-Pins. Member can be registered without activation.</small>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="ut-modal-foot">
                <button type="button" class="up-btn up-btn-outline" data-ut-close>Cancel</button>
                <button type="submit" class="up-btn up-btn-primary">Save Member</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('utAddModal');
    if (!modal) return;

    var reopen = <?= $reopenModal ? 'true' : 'false' ?>;
    var reopenParentName = <?= json_encode((string) ($_POST['parent_name'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
    var reopenParentCode = <?= json_encode((string) ($_POST['parent_code'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;

    function openModal(btn) {
        var parentId = btn.getAttribute('data-parent-id') || '';
        var position = btn.getAttribute('data-position') || '';
        var parentName = btn.getAttribute('data-parent-name') || '';
        var parentCode = btn.getAttribute('data-parent-code') || '';
        var level = btn.getAttribute('data-level') || '';
        document.getElementById('utParentId').value = parentId;
        document.getElementById('utPosition').value = position;
        document.getElementById('utParentName').value = parentName;
        document.getElementById('utParentCode').value = parentCode;
        document.getElementById('utSlotChip').textContent =
            'Under ' + (parentCode || parentName) + ' · ' + String(position).toUpperCase() + ' · LVL ' + level;
        document.getElementById('utModalSub').textContent =
            'Register under ' + parentName + ' (' + String(position).toUpperCase() + ' side). You will be the sponsor.';
        if (!reopen) {
            var form = document.getElementById('utAddForm');
            if (form) form.reset();
            document.getElementById('utParentId').value = parentId;
            document.getElementById('utPosition').value = position;
            document.getElementById('utParentName').value = parentName;
            document.getElementById('utParentCode').value = parentCode;
        }
        modal.hidden = false;
        document.body.classList.add('ut-modal-open');
    }

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('ut-modal-open');
    }

    document.querySelectorAll('.ut-node.vacant.is-add').forEach(function (btn) {
        btn.addEventListener('click', function () { openModal(btn); });
    });
    modal.querySelectorAll('[data-ut-close]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });

    if (reopen) {
        var pos = document.getElementById('utPosition').value || '';
        var code = reopenParentCode || '';
        var name = reopenParentName || '';
        document.getElementById('utSlotChip').textContent =
            'Under ' + (code || name || 'selected slot') + (pos ? (' · ' + String(pos).toUpperCase()) : '');
        modal.hidden = false;
        document.body.classList.add('ut-modal-open');
    }
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
