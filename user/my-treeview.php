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
        <header class="ut-modal-hero">
            <span class="ut-modal-hero-glow" aria-hidden="true"></span>
            <div class="ut-modal-hero-main">
                <span class="ut-modal-hero-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6"/></svg>
                </span>
                <div>
                    <p class="ut-modal-kicker">Binary placement</p>
                    <h3 id="utModalTitle">Add Member</h3>
                    <p class="ut-modal-sub" id="utModalSub">Register a new member on a vacant slot. You will be the sponsor.</p>
                </div>
            </div>
            <button type="button" class="ut-modal-x" data-ut-close aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </header>

        <form method="post" class="ut-modal-body" id="utAddForm" autocomplete="off">
            <input type="hidden" name="action" value="tree_add">
            <input type="hidden" name="parent_id" id="utParentId" value="<?= $reopenModal ? (int) ($_POST['parent_id'] ?? 0) : '' ?>">
            <input type="hidden" name="position" id="utPosition" value="<?= $reopenModal ? e((string) ($_POST['position'] ?? '')) : '' ?>">
            <input type="hidden" name="parent_name" id="utParentName" value="<?= $reopenModal ? e((string) ($_POST['parent_name'] ?? '')) : '' ?>">
            <input type="hidden" name="parent_code" id="utParentCode" value="<?= $reopenModal ? e((string) ($_POST['parent_code'] ?? '')) : '' ?>">
            <input type="hidden" name="return_root" value="<?= (int) $viewRootId ?>">

            <div class="ut-place" id="utPlaceCard">
                <div class="ut-place-parent">
                    <span class="ut-place-avatar" aria-hidden="true" id="utPlaceAvatar">+</span>
                    <div>
                        <small>Place under</small>
                        <strong id="utPlaceParent">—</strong>
                        <span id="utPlaceCode">Vacant slot</span>
                    </div>
                </div>
                <div class="ut-place-meta">
                    <span class="ut-place-side is-left" id="utPlaceSide">LEFT</span>
                    <span class="ut-place-lvl" id="utPlaceLevel">LVL —</span>
                </div>
            </div>
            <p class="ut-place-sponsor">Sponsor: <strong><?= e((string) ($user['full_name'] ?? '')) ?></strong> · <?= e((string) ($user['member_id'] ?? '')) ?></p>

            <div class="ut-sec">
                <div class="ut-sec-head">
                    <span class="ut-sec-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </span>
                    <div>
                        <h4>Personal details</h4>
                        <p>Name and contact for the new ID</p>
                    </div>
                </div>
                <div class="ut-form-grid">
                    <label class="ut-field ut-span-2">
                        <span>Full name <em>*</em></span>
                        <span class="ut-input">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <input type="text" name="full_name" id="ut_full_name" required value="<?= e($formValues['full_name']) ?>" placeholder="Member full name">
                        </span>
                    </label>
                    <label class="ut-field">
                        <span>Email <em>*</em></span>
                        <span class="ut-input">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
                            <input type="email" name="email" id="ut_email" required value="<?= e($formValues['email']) ?>" placeholder="name@email.com">
                        </span>
                    </label>
                    <label class="ut-field">
                        <span>Phone</span>
                        <span class="ut-input">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.13.96.36 1.9.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0122 16.92z"/></svg>
                            <input type="text" name="phone" id="ut_phone" value="<?= e($formValues['phone']) ?>" placeholder="Optional">
                        </span>
                    </label>
                </div>
            </div>

            <div class="ut-sec">
                <div class="ut-sec-head">
                    <span class="ut-sec-ico is-lock" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </span>
                    <div>
                        <h4>Login credentials</h4>
                        <p>Username auto-fills from name if left blank</p>
                    </div>
                </div>
                <div class="ut-form-grid">
                    <label class="ut-field">
                        <span>Username</span>
                        <span class="ut-input">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M4 12h10M4 17h7"/></svg>
                            <input type="text" name="username" id="ut_username" value="<?= e($formValues['username']) ?>" placeholder="Auto from name" maxlength="40">
                        </span>
                    </label>
                    <label class="ut-field">
                        <span>Password <em>*</em></span>
                        <span class="ut-input has-eye up-password-wrap">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            <input type="password" name="password" id="ut_password" required minlength="6" autocomplete="new-password" placeholder="Min. 6 characters">
                            <button type="button" class="up-eye" data-password-toggle aria-label="Show password">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </span>
                    </label>
                </div>
            </div>

            <?php if ($featTpin): ?>
            <div class="ut-sec ut-sec-pin">
                <div class="ut-sec-head">
                    <span class="ut-sec-ico is-gold" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
                    </span>
                    <div>
                        <h4>T-Pin activate</h4>
                        <p>Optional — register now, activate later if you skip</p>
                    </div>
                </div>
                <label class="ut-field">
                    <span>Unused pin from your wallet</span>
                    <span class="ut-input">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 9h4M7 13h10"/></svg>
                        <?php if ($myPins): ?>
                            <select name="tpin_code" id="ut_tpin">
                                <option value="">Register only — no activation</option>
                                <?php foreach ($myPins as $p): ?>
                                    <option value="<?= e(tpin_format_code((string) $p['pin_code'])) ?>" <?= ($reopenModal && trim((string) ($_POST['tpin_code'] ?? '')) === tpin_format_code((string) $p['pin_code'])) ? 'selected' : '' ?>>
                                        <?= e(tpin_format_code((string) $p['pin_code'])) ?> · <?= e($p['package_name']) ?> (<?= strip_tags(currency((float) $p['package_amount'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" name="tpin_code" id="ut_tpin" value="<?= $reopenModal ? e((string) ($_POST['tpin_code'] ?? '')) : '' ?>" placeholder="No unused pins in wallet" maxlength="20">
                        <?php endif; ?>
                    </span>
                    <?php if (!$myPins): ?>
                        <small class="ut-field-hint">You have no unused T-Pins. The member can still be registered without a package.</small>
                    <?php endif; ?>
                </label>
            </div>
            <?php endif; ?>

            <div class="ut-modal-foot">
                <button type="button" class="up-btn up-btn-outline" data-ut-close>Cancel</button>
                <button type="submit" class="up-btn up-btn-primary ut-save-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    Save Member
                </button>
            </div>
        </form>
    </div>
</div>

<div class="ut-tooltip" id="utTooltip" hidden></div>

<script>
(function () {
    var modal = document.getElementById('utAddModal');
    if (!modal) return;

    var reopen = <?= $reopenModal ? 'true' : 'false' ?>;
    var reopenParentName = <?= json_encode((string) ($_POST['parent_name'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
    var reopenParentCode = <?= json_encode((string) ($_POST['parent_code'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;

    function fillPlacement(parentName, parentCode, position, level) {
        var side = String(position || '').toUpperCase();
        var isRight = side === 'RIGHT';
        var parentEl = document.getElementById('utPlaceParent');
        var codeEl = document.getElementById('utPlaceCode');
        var sideEl = document.getElementById('utPlaceSide');
        var lvlEl = document.getElementById('utPlaceLevel');
        var av = document.getElementById('utPlaceAvatar');
        var sub = document.getElementById('utModalSub');
        if (parentEl) parentEl.textContent = parentName || 'Selected parent';
        if (codeEl) codeEl.textContent = parentCode || 'Vacant slot';
        if (sideEl) {
            sideEl.textContent = side || '—';
            sideEl.classList.toggle('is-right', isRight);
            sideEl.classList.toggle('is-left', !isRight);
        }
        if (lvlEl) lvlEl.textContent = level ? ('LVL ' + level) : 'LVL —';
        if (av) {
            var initial = (parentName || parentCode || '+').replace(/^\s+/, '').charAt(0);
            av.textContent = initial ? initial.toUpperCase() : '+';
        }
        if (sub) {
            sub.textContent = 'Register under ' + (parentName || 'this member')
                + (side ? (' · ' + side + ' side') : '')
                + '. You remain the sponsor.';
        }
    }

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
        fillPlacement(parentName, parentCode, position, level);
        if (!reopen) {
            var form = document.getElementById('utAddForm');
            if (form) form.reset();
            document.getElementById('utParentId').value = parentId;
            document.getElementById('utPosition').value = position;
            document.getElementById('utParentName').value = parentName;
            document.getElementById('utParentCode').value = parentCode;
            fillPlacement(parentName, parentCode, position, level);
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
        fillPlacement(reopenParentName || '', reopenParentCode || '', pos, '');
        modal.hidden = false;
        document.body.classList.add('ut-modal-open');
    }

    // Member hover tooltip (admin-like details)
    var tooltip = document.getElementById('utTooltip');
    if (tooltip) {
        var tipTimer = null;
        var hideTip = function () {
            tipTimer = setTimeout(function () {
                tooltip.hidden = true;
                tooltip.textContent = '';
            }, 120);
        };

        var positionTip = function (node) {
            var rect = node.getBoundingClientRect();
            var tipW = tooltip.offsetWidth;
            var tipH = tooltip.offsetHeight;

            var left = rect.left + rect.width / 2 - tipW / 2;
            var top = rect.top - tipH - 10;

            if (top < 8) top = rect.bottom + 10;
            if (left < 8) left = 8;
            if (left + tipW > window.innerWidth - 8) left = window.innerWidth - tipW - 8;

            tooltip.style.left = left + 'px';
            tooltip.style.top = top + 'px';
        };

        var showTip = function (node) {
            clearTimeout(tipTimer);
            renderTooltip(node);
            positionTip(node);
            tooltip.hidden = false;
        };

        var renderTooltip = function (el) {
            var raw = el.getAttribute('data-ut-tooltip') || '';
            if (!raw) return;

            var data = null;
            try {
                data = JSON.parse(raw);
            } catch (err) {
                data = null;
            }

            tooltip.hidden = false;
            tooltip.textContent = '';

            if (!data || typeof data !== 'object') {
                tooltip.textContent = String(raw);
                return;
            }

            // Build tooltip UI (similar to admin tree-view)
            var name = data.name || '';
            var memberId = data.member_id || '';
            if (name) {
                var nm = document.createElement('div');
                nm.className = 'tt-name';
                nm.textContent = name;
                tooltip.appendChild(nm);
            }
            if (memberId) {
                var mid = document.createElement('div');
                mid.className = 'tt-id';
                mid.textContent = memberId;
                tooltip.appendChild(mid);
            }

            function addRow(label, value) {
                if (value === null || value === undefined) return;
                var v = String(value).trim();
                if (v === '') return;
                var row = document.createElement('div');
                row.className = 'tt-row';
                var s1 = document.createElement('span');
                s1.textContent = label;
                var s2 = document.createElement('span');
                s2.textContent = v;
                row.appendChild(s1);
                row.appendChild(s2);
                tooltip.appendChild(row);
            }

            addRow('Username', data.username);
            addRow('Status', data.status);
            addRow('Package', data.package);
            addRow('Sponsor', data.sponsor);
            addRow('Email', data.email);
            addRow('Phone', data.phone);

            if (data.team_left !== null || data.team_right !== null) {
                var L = data.team_left !== null && data.team_left !== undefined ? data.team_left : '0';
                var R = data.team_right !== null && data.team_right !== undefined ? data.team_right : '0';
                addRow('Team', 'L ' + L + ' · R ' + R);
            }
            addRow('Left PV', data.left_pv != null && data.left_pv !== '' ? data.left_pv : '0');
            addRow('Right PV', data.right_pv != null && data.right_pv !== '' ? data.right_pv : '0');
            addRow('Wallet', data.wallet);
            addRow('Joined', data.joined);
        };

        document.querySelectorAll('.ut-node.filled').forEach(function (a) {
            a.addEventListener('mouseenter', function () {
                showTip(a);
            });
            a.addEventListener('mouseleave', function () {
                hideTip();
            });
            a.addEventListener('focus', function () {
                showTip(a);
            });
            a.addEventListener('blur', function () {
                hideTip();
            });
        });

        tooltip.addEventListener('mouseenter', function () {
            clearTimeout(tipTimer);
        });
        tooltip.addEventListener('mouseleave', hideTip);
    }
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
