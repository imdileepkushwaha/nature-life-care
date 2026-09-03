<?php
require_once __DIR__ . '/../config/database.php';
$pageTitle = 'Client License';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = strtolower(trim((string) ($_POST['client_license_status'] ?? 'active')));
    if ($status !== 'suspended') {
        $status = 'active';
    }
    feature_save($pdo, 'client_license_status', $status);

    $expires = trim((string) ($_POST['client_license_expires'] ?? ''));
    if ($expires === '') {
        feature_save($pdo, 'client_license_expires', '');
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
        feature_save($pdo, 'client_license_expires', $expires);
    } else {
        flash('error', 'Expiry date must be YYYY-MM-DD or empty.');
        header('Location: license.php');
        exit;
    }

    clear_setting_cache();
    log_superadmin_activity('license_save', 'License ' . $status . ($expires !== '' ? ' until ' . $expires : ' (no expiry)'));
    flash('success', 'Client license updated. Admin & User access follows this status.');
    header('Location: license.php');
    exit;
}

require __DIR__ . '/includes/header.php';

$status = client_license_status();
$expires = setting('client_license_expires', '');
$ok = client_license_ok();
?>
<section class="sa-hero">
    <div>
        <span class="sa-hero-kicker">Access control</span>
        <h1>Client License</h1>
        <p>Suspend this install or set an expiry date. Super Admin always stays available.</p>
    </div>
</section>

<form method="post" class="sa-panel">
    <div class="sa-panel-head">
        <div>
            <h2>License status</h2>
            <p>Controls Client Admin and User panel access</p>
        </div>
        <?= $ok ? '<span class="sa-chip on">ACCESS OK</span>' : '<span class="sa-chip off">BLOCKED</span>' ?>
    </div>
    <div class="sa-panel-body">
        <div class="sa-form-grid">
            <div class="form-group span-2">
                <label>Status</label>
                <div class="sa-mode-grid">
                    <label class="sa-mode-opt <?= $status === 'active' ? 'is-on' : '' ?>">
                        <input type="radio" name="client_license_status" value="active" <?= $status === 'active' ? 'checked' : '' ?>>
                        <strong>Active</strong>
                        <small>Admin &amp; User can sign in</small>
                    </label>
                    <label class="sa-mode-opt <?= $status === 'suspended' ? 'is-on' : '' ?>">
                        <input type="radio" name="client_license_status" value="suspended" <?= $status === 'suspended' ? 'checked' : '' ?>>
                        <strong>Suspended</strong>
                        <small>Block Admin &amp; User immediately</small>
                    </label>
                </div>
            </div>
            <div class="form-group span-2">
                <label>Expires on</label>
                <input type="date" name="client_license_expires" value="<?= e($expires) ?>">
                <span class="sa-field-hint">Leave empty for no expiry. After this date, Admin &amp; User are blocked even if status is Active.</span>
            </div>
        </div>
        <?php if (!$ok): ?>
        <div class="sa-note" style="margin-top:1rem"><?= e(client_license_message()) ?></div>
        <?php endif; ?>
        <div class="sa-form-actions">
            <button type="submit" class="btn btn-primary">Save license</button>
        </div>
    </div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
