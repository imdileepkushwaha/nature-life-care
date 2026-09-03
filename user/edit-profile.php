<?php
$pageTitle = 'Edit Profile';
require_once __DIR__ . '/includes/auth.php';
require_user();

$user = current_user($pdo);
if (!$user || ($user['status'] ?? '') === 'blocked') {
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_code']);
    header('Location: login.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }

    if (!$errors) {
        $dup = $pdo->prepare('SELECT id FROM members WHERE email = ? AND id != ? LIMIT 1');
        $dup->execute([$email, (int) $user['id']]);
        if ($dup->fetch()) {
            $errors[] = 'This email is already used by another member.';
        }
    }

    if (!$errors) {
        $upd = $pdo->prepare('UPDATE members SET full_name = ?, phone = ?, email = ? WHERE id = ?');
        $upd->execute([$fullName, $phone !== '' ? $phone : null, $email, (int) $user['id']]);
        $_SESSION['user_name'] = $fullName;
        flash('success', 'Profile updated successfully.');
        header('Location: profile.php');
        exit;
    }
}

$form = [
    'full_name' => $_POST['full_name'] ?? $user['full_name'],
    'phone' => $_POST['phone'] ?? ($user['phone'] ?? ''),
    'email' => $_POST['email'] ?? $user['email'],
];

$initials = user_initials((string) $form['full_name']);
$status = member_effective_status($user);
$packageName = $user['package_name'] ?? '—';

require_once __DIR__ . '/includes/header.php';
?>
<div class="up-page-head">
    <div>
        <h1>Edit Profile</h1>
        <p>Update your personal details. Username and Member ID stay locked.</p>
    </div>
    <a href="profile.php" class="up-btn up-btn-outline">View Profile</a>
</div>

<div class="ep">
    <aside class="ep-side">
        <div class="ep-profile-card">
            <div class="ep-side-banner">
                <span class="ep-side-orb a" aria-hidden="true"></span>
                <span class="ep-side-orb b" aria-hidden="true"></span>
                <span class="ep-side-kicker">Your account</span>
            </div>
            <div class="ep-side-body">
                <?php
                $epPhoto = user_photo_url($user['photo'] ?? null);
                if ($epPhoto): ?>
                    <div class="ep-avatar has-photo"><img src="<?= e($epPhoto) ?>" alt="<?= e($form['full_name']) ?>"></div>
                <?php else: ?>
                    <div class="ep-avatar"><?= e($initials) ?></div>
                <?php endif; ?>
                <h2><?= e($form['full_name']) ?></h2>
                <p>@<?= e($user['username']) ?></p>
                <div class="ep-side-chips">
                    <span class="ep-chip"><?= e($user['member_id']) ?></span>
                    <span class="ep-chip is-<?= e($status) ?>"><?= e(ucfirst($status)) ?></span>
                </div>
                <ul class="ep-side-meta">
                    <li>
                        <span>Package</span>
                        <strong><?= e($packageName) ?></strong>
                    </li>
                    <li>
                        <span>Email</span>
                        <strong><?= e($form['email']) ?></strong>
                    </li>
                    <li>
                        <span>Phone</span>
                        <strong><?= e($form['phone'] !== '' ? $form['phone'] : '—') ?></strong>
                    </li>
                </ul>
                <div class="ep-side-links">
                    <a href="profile.php" class="ep-side-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        View Profile
                    </a>
                    <a href="upload-photo.php" class="ep-side-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        Upload Photo
                    </a>
                    <a href="change-password.php" class="ep-side-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        Change Password
                    </a>
                </div>
            </div>
        </div>
    </aside>

    <section class="ep-main">
        <div class="ep-form-card">
            <div class="ep-form-head">
                <div class="up-panel-head-main">
                    <span class="up-panel-head-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                    </span>
                    <div>
                        <span class="up-panel-kicker">Account settings</span>
                        <h2>Personal Information</h2>
                        <p>Keep your contact details up to date for withdrawals and support.</p>
                    </div>
                </div>
            </div>

            <div class="ep-form-body">
                <?php foreach ($errors as $err): ?>
                    <div class="up-alert up-alert-err"><?= e($err) ?></div>
                <?php endforeach; ?>

                <form method="post" autocomplete="off" class="ep-form">
                    <div class="ep-section">
                        <div class="ep-section-title">
                            <span class="ep-section-ico is-lock" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            </span>
                            <div>
                                <h3>Locked details</h3>
                                <p>These values cannot be changed.</p>
                            </div>
                        </div>
                        <div class="up-form-grid">
                            <div class="up-field">
                                <label for="member_id">Member ID</label>
                                <div class="ep-input-wrap">
                                    <span class="ep-input-ico" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M4 12h10M4 17h7"/></svg>
                                    </span>
                                    <input type="text" id="member_id" value="<?= e($user['member_id']) ?>" readonly disabled>
                                </div>
                            </div>
                            <div class="up-field">
                                <label for="username">Username</label>
                                <div class="ep-input-wrap">
                                    <span class="ep-input-ico" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    </span>
                                    <input type="text" id="username" value="<?= e($user['username']) ?>" readonly disabled>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ep-section">
                        <div class="ep-section-title">
                            <span class="ep-section-ico is-edit" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                            </span>
                            <div>
                                <h3>Editable details</h3>
                                <p>Name, email and phone can be updated anytime.</p>
                            </div>
                        </div>
                        <div class="up-form-grid">
                            <div class="up-field full">
                                <label for="full_name">Full Name</label>
                                <div class="ep-input-wrap">
                                    <span class="ep-input-ico" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    </span>
                                    <input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" required>
                                </div>
                            </div>
                            <div class="up-field">
                                <label for="email">Email</label>
                                <div class="ep-input-wrap">
                                    <span class="ep-input-ico" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v16H4z"/><path d="M22 6l-10 7L2 6"/></svg>
                                    </span>
                                    <input type="email" id="email" name="email" value="<?= e($form['email']) ?>" required>
                                </div>
                            </div>
                            <div class="up-field">
                                <label for="phone">Phone</label>
                                <div class="ep-input-wrap">
                                    <span class="ep-input-ico" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.12.9.33 1.77.62 2.6a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.83.29 1.7.5 2.6.62A2 2 0 0122 16.92z"/></svg>
                                    </span>
                                    <input type="text" id="phone" name="phone" value="<?= e($form['phone']) ?>" placeholder="Optional">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ep-actions">
                        <button type="submit" class="up-btn up-btn-primary ep-save">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
                            Save Changes
                        </button>
                        <a href="profile.php" class="up-btn up-btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
