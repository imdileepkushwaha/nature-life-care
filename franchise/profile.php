<?php
/**
 * Franchise Profile & Password Management
 */
$pageTitle = 'My Profile';
require_once __DIR__ . '/includes/header.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPass = (string) ($_POST['current_password'] ?? '');
    $newPass = (string) ($_POST['new_password'] ?? '');
    $confirmPass = (string) ($_POST['confirm_password'] ?? '');

    if ($currentPass === '' || $newPass === '') {
        $errors[] = 'Please fill in all password fields.';
    } elseif (strlen($newPass) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    } elseif ($newPass !== $confirmPass) {
        $errors[] = 'New password and confirm password do not match.';
    } else {
        // Verify current password
        $checkStmt = $pdo->prepare('SELECT password FROM franchisees WHERE id = ? LIMIT 1');
        $checkStmt->execute([(int) $currentFranchise['id']]);
        $currentHash = $checkStmt->fetchColumn();

        if (!$currentHash || !password_verify($currentPass, $currentHash)) {
            $errors[] = 'Current password is incorrect.';
        } else {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE franchisees SET password = ? WHERE id = ?')->execute([$newHash, (int) $currentFranchise['id']]);
            log_activity('franchise_password_change', "Franchisee {$currentFranchise['franchisee_code']} changed their password");
            $success = 'Password has been updated successfully.';
        }
    }
}
?>

<div class="franchise-dash-split" style="margin-top:0">
    <!-- Franchise Profile Details -->
    <div class="panel" style="margin-bottom:0">
        <div class="panel-header">
            <div>
                <h2>Franchise Account Details</h2>
                <div style="font-size:0.78rem;color:#64748b;margin-top:0.2rem">Registered credentials and franchise information</div>
            </div>
            <span class="badge badge-success"><?= e(strtoupper($currentFranchise['status'])) ?></span>
        </div>
        <div class="panel-body" style="padding:1.5rem">
            <div style="display:flex;align-items:center;gap:1.25rem;padding-bottom:1.5rem;margin-bottom:1.25rem;border-bottom:1px solid #f1f5f9">
                <div class="user-avatar fr-avatar" style="width:58px;height:58px;font-size:1.4rem;font-weight:700">
                    <?= strtoupper(substr($currentFranchise['name'] ?? 'F', 0, 1)) ?>
                </div>
                <div>
                    <h3 style="font-size:1.15rem;font-weight:700;color:#0f172a;margin-bottom:0.2rem"><?= e($currentFranchise['name']) ?></h3>
                    <div style="display:flex;gap:0.5rem;align-items:center">
                        <span class="badge" style="background:#e0f2fe;color:#0284c7;font-weight:600"><?= e($currentFranchise['franchisee_code']) ?></span>
                        <span style="font-size:0.82rem;color:#64748b"><?= e($currentFranchise['type_name'] ?? 'Franchise') ?></span>
                    </div>
                </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:0.85rem;font-size:0.9rem">
                <div style="display:flex;justify-content:space-between;padding-bottom:0.55rem;border-bottom:1px solid #f8fafc">
                    <span style="color:#64748b">Contact Person:</span>
                    <strong style="color:#1e293b"><?= e($currentFranchise['contact_person'] ?? '—') ?></strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding-bottom:0.55rem;border-bottom:1px solid #f8fafc">
                    <span style="color:#64748b">Username:</span>
                    <code><?= e($currentFranchise['username'] ?? '—') ?></code>
                </div>
                <div style="display:flex;justify-content:space-between;padding-bottom:0.55rem;border-bottom:1px solid #f8fafc">
                    <span style="color:#64748b">Mobile Number:</span>
                    <span><?= e($currentFranchise['phone'] ?? '—') ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding-bottom:0.55rem;border-bottom:1px solid #f8fafc">
                    <span style="color:#64748b">Email Address:</span>
                    <span><?= e($currentFranchise['email'] ?? '—') ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding-bottom:0.55rem;border-bottom:1px solid #f8fafc">
                    <span style="color:#64748b">Address:</span>
                    <span style="text-align:right;max-width:60%"><?= e($currentFranchise['address'] ?? '—') ?>, <?= e($currentFranchise['city'] ?? '') ?>, <?= e($currentFranchise['state'] ?? '') ?> <?= e($currentFranchise['pincode'] ?? '') ?></span>
                </div>
                <?php if (!empty($currentFranchise['sponsor_code'])): ?>
                <div style="display:flex;justify-content:space-between;padding-bottom:0.55rem;border-bottom:1px solid #f8fafc">
                    <span style="color:#64748b">Sponsor Franchise:</span>
                    <span><?= e($currentFranchise['sponsor_name']) ?> (<code><?= e($currentFranchise['sponsor_code']) ?></code>)</span>
                </div>
                <?php endif; ?>
            </div>

            <h4 style="font-size:0.92rem;font-weight:700;margin-top:1.5rem;margin-bottom:0.75rem;color:#0f172a;letter-spacing:-0.01em">Identification & KYC Documents</h4>
            <div style="display:flex;flex-direction:column;gap:0.65rem;font-size:0.88rem">
                <div style="display:flex;justify-content:space-between;align-items:center;background:#f8fafc;padding:0.65rem 0.85rem;border-radius:8px;border:1px solid #eef2f6">
                    <span>Aadhaar: <strong style="color:#1e293b"><?= e($currentFranchise['aadhaar_no'] ?: '—') ?></strong></span>
                    <?php if (!empty($currentFranchise['aadhaar_file'])): ?>
                        <a href="../<?= e(ltrim($currentFranchise['aadhaar_file'], '/')) ?>" target="_blank" class="btn btn-outline btn-sm">View File ↗</a>
                    <?php endif; ?>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;background:#f8fafc;padding:0.65rem 0.85rem;border-radius:8px;border:1px solid #eef2f6">
                    <span>PAN: <strong style="color:#1e293b"><?= e($currentFranchise['pan_no'] ?: '—') ?></strong></span>
                    <?php if (!empty($currentFranchise['pan_file'])): ?>
                        <a href="../<?= e(ltrim($currentFranchise['pan_file'], '/')) ?>" target="_blank" class="btn btn-outline btn-sm">View File ↗</a>
                    <?php endif; ?>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;background:#f8fafc;padding:0.65rem 0.85rem;border-radius:8px;border:1px solid #eef2f6">
                    <span>GSTIN: <strong style="color:#1e293b"><?= e($currentFranchise['gst_no'] ?: '—') ?></strong></span>
                    <?php if (!empty($currentFranchise['gst_file'])): ?>
                        <a href="../<?= e(ltrim($currentFranchise['gst_file'], '/')) ?>" target="_blank" class="btn btn-outline btn-sm">View File ↗</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Security / Password Update -->
    <div class="panel" style="margin-bottom:0">
        <div class="panel-header">
            <div>
                <h2>Change Password</h2>
                <div style="font-size:0.78rem;color:#64748b;margin-top:0.2rem">Update your franchise portal account password</div>
            </div>
        </div>
        <div class="panel-body" style="padding:1.5rem">
            <?php if ($success !== ''): ?>
                <div class="alert alert-success" style="margin-bottom:1.25rem">
                    <?= e($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert alert-danger" style="margin-bottom:1.25rem">
                    <ul style="padding-left:1.2rem;margin:0">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="profile.php">
                <input type="hidden" name="change_password" value="1">

                <div class="form-group" style="margin-bottom:1.2rem">
                    <label class="form-label" style="font-weight:600;font-size:0.86rem;margin-bottom:0.4rem;display:block">Current Password *</label>
                    <input type="password" name="current_password" class="form-control" required placeholder="Enter current password">
                </div>

                <div class="form-group" style="margin-bottom:1.2rem">
                    <label class="form-label" style="font-weight:600;font-size:0.86rem;margin-bottom:0.4rem;display:block">New Password *</label>
                    <input type="password" name="new_password" class="form-control" minlength="6" required placeholder="Minimum 6 characters">
                </div>

                <div class="form-group" style="margin-bottom:1.5rem">
                    <label class="form-label" style="font-weight:600;font-size:0.86rem;margin-bottom:0.4rem;display:block">Confirm New Password *</label>
                    <input type="password" name="confirm_password" class="form-control" minlength="6" required placeholder="Re-enter new password">
                </div>

                <button type="submit" class="btn btn-primary" style="padding:0.75rem 1.6rem;font-weight:600">
                    Update Password
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
