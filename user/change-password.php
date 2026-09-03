<?php
$pageTitle = 'Change Password';
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
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($current === '' || $new === '' || $confirm === '') {
        $errors[] = 'All password fields are required.';
    } elseif (!password_verify($current, $user['password'])) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($new) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if (!$errors) {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE members SET password = ? WHERE id = ?')->execute([$hash, (int) $user['id']]);
        flash('success', 'Password changed successfully.');
        header('Location: profile.php');
        exit;
    }
}

$initials = user_initials((string) ($user['full_name'] ?? 'User'));
$status = member_effective_status($user);

require_once __DIR__ . '/includes/header.php';
?>
<div class="up-page-head">
    <div>
        <h1>Change Password</h1>
        <p>Update your login password. Use a strong, unique password to protect your account.</p>
    </div>
    <a href="profile.php" class="up-btn up-btn-outline">View Profile</a>
</div>

<div class="cpw">
    <aside class="cpw-side">
        <div class="cpw-tips-card">
            <div class="cpw-side-banner">
                <span class="cpw-orb a" aria-hidden="true"></span>
                <span class="cpw-orb b" aria-hidden="true"></span>
                <span class="cpw-kicker">Security</span>
                <div class="cpw-shield" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
            </div>
            <div class="cpw-side-body">
                <div class="cpw-side-intro">
                    <h2>Keep your account safe</h2>
                    <p>Follow these tips when choosing a new password.</p>
                </div>
                <ul class="cpw-tips">
                    <li>
                        <span class="cpw-tip-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </span>
                        <div>
                            <strong>Never share your password</strong>
                            <small><?= e($company) ?> staff will never ask for it.</small>
                        </div>
                    </li>
                    <li>
                        <span class="cpw-tip-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        </span>
                        <div>
                            <strong>Use at least 6 characters</strong>
                            <small>Mix letters, numbers and symbols for extra strength.</small>
                        </div>
                    </li>
                    <li>
                        <span class="cpw-tip-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </span>
                        <div>
                            <strong>Don't reuse old passwords</strong>
                            <small>Pick something you haven't used elsewhere.</small>
                        </div>
                    </li>
                </ul>
                <div class="cpw-side-links">
                    <a href="profile.php" class="cpw-side-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        View Profile
                    </a>
                    <a href="edit-profile.php" class="cpw-side-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
                        Edit Profile
                    </a>
                    <a href="forgot-password.php" class="cpw-side-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        Forgot Password
                    </a>
                </div>
            </div>
        </div>

        <div class="cpw-account-card">
            <div class="cpw-account-avatar"><?= e($initials) ?></div>
            <div>
                <strong><?= e($user['full_name']) ?></strong>
                <span><?= e($user['member_id']) ?> · <?= e(ucfirst($status)) ?></span>
            </div>
        </div>
    </aside>

    <section class="cpw-main">
        <div class="cpw-form-card">
            <div class="cpw-form-head">
                <div class="up-panel-head-main">
                    <span class="up-panel-head-ico is-teal" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </span>
                    <div>
                        <span class="up-panel-kicker">Account security</span>
                        <h2>Update Password</h2>
                        <p>Enter your current password, then choose a new one.</p>
                    </div>
                </div>
            </div>

            <div class="cpw-form-body">
                <?php foreach ($errors as $err): ?>
                    <div class="up-alert up-alert-err"><?= e($err) ?></div>
                <?php endforeach; ?>

                <form method="post" autocomplete="off" class="cpw-form" id="cpwForm">
                    <div class="cpw-field">
                        <label for="current_password">Current Password</label>
                        <div class="cpw-input-wrap">
                            <span class="cpw-input-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            </span>
                            <div class="up-password-wrap">
                                <input type="password" id="current_password" name="current_password" placeholder="Enter current password" required>
                                <button type="button" class="up-eye" data-password-toggle aria-label="Show password">
                                    <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="cpw-divider" aria-hidden="true"><span>New password</span></div>

                    <div class="cpw-field">
                        <label for="new_password">New Password</label>
                        <div class="cpw-input-wrap">
                            <span class="cpw-input-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                            </span>
                            <div class="up-password-wrap">
                                <input type="password" id="new_password" name="new_password" minlength="6" placeholder="At least 6 characters" required>
                                <button type="button" class="up-eye" data-password-toggle aria-label="Show password">
                                    <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="cpw-strength" id="cpwStrength" aria-live="polite">
                            <span class="cpw-strength-bar"><i id="cpwStrengthFill"></i></span>
                            <small id="cpwStrengthLabel">Password strength</small>
                        </div>
                    </div>

                    <div class="cpw-field">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="cpw-input-wrap">
                            <span class="cpw-input-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="20 6 9 17 4 12"/></svg>
                            </span>
                            <div class="up-password-wrap">
                                <input type="password" id="confirm_password" name="confirm_password" minlength="6" placeholder="Re-enter new password" required>
                                <button type="button" class="up-eye" data-password-toggle aria-label="Show password">
                                    <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                        </div>
                        <p class="cpw-match" id="cpwMatch" hidden>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            Passwords match
                        </p>
                    </div>

                    <ul class="cpw-checklist" id="cpwChecklist">
                        <li id="cpwCheckLen"><span class="cpw-check-dot"></span> At least 6 characters</li>
                        <li id="cpwCheckMatch"><span class="cpw-check-dot"></span> Passwords match</li>
                    </ul>

                    <div class="cpw-actions">
                        <button type="submit" class="up-btn up-btn-primary cpw-save">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            Update Password
                        </button>
                        <a href="profile.php" class="up-btn up-btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>
<script>
(function () {
    const newPw = document.getElementById('new_password');
    const confirmPw = document.getElementById('confirm_password');
    const strengthFill = document.getElementById('cpwStrengthFill');
    const strengthLabel = document.getElementById('cpwStrengthLabel');
    const matchEl = document.getElementById('cpwMatch');
    const checkLen = document.getElementById('cpwCheckLen');
    const checkMatch = document.getElementById('cpwCheckMatch');
    if (!newPw || !confirmPw) return;

    const score = (val) => {
        let s = 0;
        if (val.length >= 6) s++;
        if (val.length >= 10) s++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) s++;
        if (/\d/.test(val)) s++;
        if (/[^A-Za-z0-9]/.test(val)) s++;
        return Math.min(s, 4);
    };

    const update = () => {
        const val = newPw.value;
        const confirm = confirmPw.value;
        const sc = score(val);
        const levels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
        const colors = ['#e2e8f0', '#f87171', '#fb923c', '#34d399', '#059669'];
        if (strengthFill) {
            strengthFill.style.width = val ? (sc * 25) + '%' : '0';
            strengthFill.style.background = colors[sc] || colors[0];
        }
        if (strengthLabel) strengthLabel.textContent = val ? levels[sc] : 'Password strength';

        const lenOk = val.length >= 6;
        const matchOk = val !== '' && val === confirm;
        checkLen?.classList.toggle('is-ok', lenOk);
        checkMatch?.classList.toggle('is-ok', matchOk);
        if (matchEl) matchEl.hidden = !matchOk;
    };

    newPw.addEventListener('input', update);
    confirmPw.addEventListener('input', update);
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
