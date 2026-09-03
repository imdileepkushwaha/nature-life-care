<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/registration.php';
$pageTitle = 'Edit Member';

ensure_member_registration_columns($pdo);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$member = $stmt->fetch();

if (!$member) {
    flash('error', 'Member not found.');
    header('Location: members.php');
    exit;
}

$pageTitle = 'Edit · ' . $member['full_name'];
$errors = [];
$pwdErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'profile');

    if ($action === 'password') {
        $pass = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if (strlen($pass) < 6) {
            $pwdErrors[] = 'Password must be at least 6 characters.';
        } elseif ($pass !== $confirm) {
            $pwdErrors[] = 'Passwords do not match.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE members SET password = ? WHERE id = ?')->execute([$hash, $id]);
            log_activity('member_password_reset', "Reset password for member #{$id} ({$member['member_id']})");
            flash('success', 'Password updated successfully.');
            header('Location: member-edit.php?id=' . $id);
            exit;
        }
    } else {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'active');
        $nameTitle = trim((string) ($_POST['name_title'] ?? ''));
        $gender = trim((string) ($_POST['gender'] ?? ''));
        $dob = trim((string) ($_POST['date_of_birth'] ?? ''));

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }
        if ($username === '') {
            $errors[] = 'Username is required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required.';
        }
        if (!in_array($status, ['active', 'inactive', 'blocked'], true)) {
            $errors[] = 'Invalid status.';
        }
        if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            $errors[] = 'Invalid date of birth.';
        }
        if ($dob === '') {
            $dob = null;
        }

        if (!$errors) {
            $dup = $pdo->prepare('SELECT id FROM members WHERE (username = ? OR email = ?) AND id != ? LIMIT 1');
            $dup->execute([$username, $email, $id]);
            if ($dup->fetch()) {
                $errors[] = 'Username or email already used by another member.';
            }
        }

        if (!$errors) {
            $pdo->prepare('
                UPDATE members SET
                    full_name = ?, username = ?, email = ?, phone = ?, status = ?,
                    name_title = ?, gender = ?, date_of_birth = ?
                WHERE id = ?
            ')->execute([
                $fullName,
                $username,
                $email,
                $phone !== '' ? $phone : null,
                $status,
                $nameTitle !== '' ? $nameTitle : null,
                $gender !== '' ? $gender : null,
                $dob,
                $id,
            ]);
            log_activity('member_edit', "Updated member #{$id} ({$member['member_id']})");
            flash('success', 'Member profile updated.');
            header('Location: member-view.php?id=' . $id);
            exit;
        }

        // Keep form values on error
        $member = array_merge($member, [
            'full_name' => $fullName,
            'username' => $username,
            'email' => $email,
            'phone' => $phone,
            'status' => $status,
            'name_title' => $nameTitle,
            'gender' => $gender,
            'date_of_birth' => $dob,
        ]);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2>Edit Member</h2>
            <p class="members-sub"><?= e($member['member_id']) ?> · @<?= e($member['username']) ?></p>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
            <a href="member-view.php?id=<?= $id ?>" class="btn btn-outline btn-sm">View profile</a>
            <a href="members.php" class="btn btn-outline btn-sm">← List</a>
        </div>
    </div>
    <div class="panel-body">
        <?php if ($errors): ?>
            <div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <form method="post" class="form-grid" style="grid-template-columns:1fr 1fr;gap:1rem">
            <input type="hidden" name="action" value="profile">
            <input type="hidden" name="id" value="<?= $id ?>">

            <div class="form-group">
                <label>Member ID</label>
                <input type="text" value="<?= e($member['member_id']) ?>" disabled>
            </div>
            <div class="form-group">
                <label>Status *</label>
                <select name="status" required>
                    <?php foreach (['active', 'inactive', 'blocked'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($member['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Title</label>
                <select name="name_title">
                    <?php foreach (['', 'Mr', 'Mrs', 'Ms', 'Dr'] as $t): ?>
                        <option value="<?= e($t) ?>" <?= ($member['name_title'] ?? '') === $t ? 'selected' : '' ?>><?= $t === '' ? '—' : e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" value="<?= e($member['full_name'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label>Username *</label>
                <input type="text" name="username" value="<?= e($member['username'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="email" value="<?= e($member['email'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone" value="<?= e($member['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Gender</label>
                <select name="gender">
                    <?php foreach (['', 'Male', 'Female', 'Other'] as $g): ?>
                        <option value="<?= e($g) ?>" <?= ($member['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g === '' ? '—' : e($g) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Date of Birth</label>
                <input type="date" name="date_of_birth" value="<?= e($member['date_of_birth'] ?? '') ?>">
            </div>

            <div class="form-group" style="grid-column:1 / -1">
                <p class="muted" style="margin:0;font-size:0.85rem">Sponsor, placement, and package are not edited here. Use Activate Package / Tree tools for structure changes.</p>
            </div>

            <div class="form-actions" style="grid-column:1 / -1;margin-top:0.5rem">
                <button type="submit" class="btn btn-primary">Save profile</button>
                <a href="member-view.php?id=<?= $id ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2>Reset Password</h2>
            <p class="members-sub">Set a new password for this member</p>
        </div>
    </div>
    <div class="panel-body">
        <?php if ($pwdErrors): ?>
            <div class="alert alert-error"><?= e(implode(' ', $pwdErrors)) ?></div>
        <?php endif; ?>
        <form method="post" class="form-grid" style="grid-template-columns:1fr 1fr;gap:1rem;max-width:640px">
            <input type="hidden" name="action" value="password">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="form-group">
                <label>New Password *</label>
                <input type="password" name="new_password" required minlength="6" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label>Confirm Password *</label>
                <input type="password" name="confirm_password" required minlength="6" autocomplete="new-password">
            </div>
            <div class="form-actions" style="grid-column:1 / -1">
                <button type="submit" class="btn btn-primary" data-confirm="Reset this member’s password?">Update password</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
