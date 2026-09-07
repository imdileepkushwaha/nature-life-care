<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/messaging.php';
require_once __DIR__ . '/../includes/db_tools.php';
require_superadmin();

$pageTitle = 'Settings';
messaging_ensure_defaults($pdo);

$tab = $_GET['tab'] ?? 'smtp';
$allowedTabs = ['smtp', 'whatsapp', 'sms', 'security', 'activity', 'database', 'backup'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'smtp';
}

// Download backup (GET) before any HTML
if ($tab === 'backup' && isset($_GET['download'])) {
    db_tools_download_backup((string) $_GET['download']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postTab = (string) ($_POST['tab'] ?? 'smtp');
    if (!in_array($postTab, $allowedTabs, true)) {
        $postTab = 'smtp';
    }
    $postAction = (string) ($_POST['action'] ?? 'save');

    if ($postAction === 'test' && in_array($postTab, ['smtp', 'whatsapp', 'sms'], true)) {
        $testType = (string) ($_POST['test_type'] ?? $postTab);
        if ($testType === 'smtp') {
            $result = messaging_test_smtp();
        } elseif ($testType === 'whatsapp') {
            $result = messaging_test_whatsapp();
        } else {
            $result = messaging_test_sms();
        }
        log_superadmin_activity('settings_test_' . $testType, ($result['ok'] ? 'OK: ' : 'FAIL: ') . ($result['message'] ?? ''));
        flash($result['ok'] ? 'success' : 'error', $result['message'] ?? 'Test failed.');
        header('Location: settings.php?tab=' . urlencode($postTab));
        exit;
    }

    if ($postTab === 'smtp') {
        messaging_save_smtp($pdo, $_POST);
        clear_setting_cache();
        log_superadmin_activity('settings_smtp', 'Updated Email SMTP settings');
        flash('success', 'Email SMTP settings saved.');
    } elseif ($postTab === 'whatsapp') {
        messaging_save_whatsapp($pdo, $_POST);
        clear_setting_cache();
        log_superadmin_activity('settings_whatsapp', 'Updated WhatsApp API settings');
        flash('success', 'WhatsApp API settings saved.');
    } elseif ($postTab === 'sms') {
        messaging_save_sms($pdo, $_POST);
        clear_setting_cache();
        log_superadmin_activity('settings_sms', 'Updated SMS API settings');
        flash('success', 'SMS API settings saved.');
    } elseif ($postTab === 'security') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($new === '' && $confirm === '') {
            flash('error', 'Enter a new password to update.');
        } elseif (strlen($new) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'New password and confirmation do not match.');
        } else {
            $stmt = $pdo->prepare('SELECT * FROM super_admins WHERE id = ? LIMIT 1');
            $stmt->execute([(int) $_SESSION['superadmin_id']]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($current, $row['password'])) {
                flash('error', 'Current password is incorrect.');
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE super_admins SET password = ? WHERE id = ?')->execute([$hash, $row['id']]);
                log_superadmin_activity('password_change', 'Super Admin password changed');
                flash('success', 'Password updated successfully.');
            }
        }
        header('Location: settings.php?tab=security');
        exit;
    } elseif ($postTab === 'database') {
        if ($postAction === 'run_setup') {
            $result = db_tools_run_setup($pdo);
            log_superadmin_activity('settings_db_setup', ($result['ok'] ? 'OK: ' : 'FAIL: ') . ($result['message'] ?? ''));
            flash($result['ok'] ? 'success' : 'error', $result['message'] ?? 'Setup failed.');
        } elseif ($postAction === 'test_online' || $postAction === 'test_offline') {
            $all = app_db_credentials();
            $prefix = $postAction === 'test_online' ? 'online' : 'offline';
            $current = $prefix === 'online' ? $all['live'] : $all['local'];
            $parsed = db_tools_parse_slot_post($_POST, $prefix, $current);
            if (!$parsed['ok']) {
                flash('error', $parsed['message'] ?? 'Invalid credentials.');
            } else {
                $cfg = $parsed['cfg'];
                $result = db_tools_test_connection($cfg['host'], $cfg['name'], $cfg['user'], $cfg['pass'], $cfg['port']);
                log_superadmin_activity('settings_db_test_' . $prefix, ($result['ok'] ? 'OK: ' : 'FAIL: ') . ($result['message'] ?? ''));
                flash($result['ok'] ? 'success' : 'error', ucfirst($prefix) . ': ' . ($result['message'] ?? 'Test failed.'));
            }
        } else {
            $result = db_tools_save_dual_credentials($_POST);
            log_superadmin_activity('settings_database', ($result['ok'] ? 'OK: ' : 'FAIL: ') . ($result['message'] ?? ''));
            flash($result['ok'] ? 'success' : 'error', $result['message'] ?? 'Save failed.');
        }
        header('Location: settings.php?tab=database');
        exit;
    } elseif ($postTab === 'backup') {
        if ($postAction === 'download_backup') {
            log_superadmin_activity('settings_backup_download', 'SQL backup download started');
            db_tools_download_now($pdo);
        } elseif ($postAction === 'restore_upload') {
            if (empty($_POST['confirm_restore'])) {
                flash('error', 'Confirm that you understand this overwrites the current database.');
            } else {
                $result = db_tools_restore_upload($pdo, $_FILES['backup_file'] ?? []);
                log_superadmin_activity('settings_backup_restore', ($result['ok'] ? 'OK: ' : 'FAIL: ') . ($result['message'] ?? ''));
                flash($result['ok'] ? 'success' : 'error', $result['message'] ?? 'Restore failed.');
            }
        }
        header('Location: settings.php?tab=backup');
        exit;
    }

    header('Location: settings.php?tab=' . urlencode($postTab));
    exit;
}

$msg = messaging_get_all();
$smtpOn = ($msg['smtp_enabled'] ?? '0') === '1';
$waOn = ($msg['wa_api_enabled'] ?? '0') === '1';
$smsOn = ($msg['sms_api_enabled'] ?? '0') === '1';

$logs = [];
if ($tab === 'activity') {
    try {
        $logs = $pdo->query("
            SELECT id, action, details, ip_address, created_at
            FROM activity_logs
            WHERE action LIKE 'superadmin:%'
            ORDER BY id DESC
            LIMIT 50
        ")->fetchAll();
    } catch (Throwable $e) {
        $logs = [];
    }
}

$dbInfo = null;
$dbCreds = null;
if ($tab === 'database' || $tab === 'backup') {
    $dbInfo = db_tools_info($pdo);
    $dbCreds = app_db_credentials();
}

require __DIR__ . '/includes/header.php';
?>

<section class="sa-hero">
    <div>
        <span class="sa-hero-kicker">Platform</span>
        <h1>Settings</h1>
        <p>Email SMTP, WhatsApp &amp; SMS APIs, database, backups, security, and Super Admin activity.</p>
    </div>
</section>

<div class="settings-layout sa-settings">
    <aside class="settings-nav">
        <div class="settings-nav-group">
            <span class="settings-nav-label">Messaging</span>
            <a href="settings.php?tab=smtp" class="settings-nav-item <?= $tab === 'smtp' ? 'active' : '' ?>">
                <span class="sni-ico red">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </span>
                Email SMTP
            </a>
            <a href="settings.php?tab=whatsapp" class="settings-nav-item <?= $tab === 'whatsapp' ? 'active' : '' ?>">
                <span class="sni-ico teal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                </span>
                WhatsApp API
            </a>
            <a href="settings.php?tab=sms" class="settings-nav-item <?= $tab === 'sms' ? 'active' : '' ?>">
                <span class="sni-ico pink">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                </span>
                SMS API
            </a>
        </div>
        <div class="settings-nav-group">
            <span class="settings-nav-label">Data</span>
            <a href="settings.php?tab=database" class="settings-nav-item <?= $tab === 'database' ? 'active' : '' ?>">
                <span class="sni-ico blue">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                </span>
                Database
            </a>
            <a href="settings.php?tab=backup" class="settings-nav-item <?= $tab === 'backup' ? 'active' : '' ?>">
                <span class="sni-ico purple">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </span>
                Backup
            </a>
        </div>
        <div class="settings-nav-group">
            <span class="settings-nav-label">Account</span>
            <a href="settings.php?tab=security" class="settings-nav-item <?= $tab === 'security' ? 'active' : '' ?>">
                <span class="sni-ico green">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                </span>
                Security
            </a>
            <a href="settings.php?tab=activity" class="settings-nav-item <?= $tab === 'activity' ? 'active' : '' ?>">
                <span class="sni-ico orange">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </span>
                Activity Log
            </a>
        </div>
    </aside>

    <section class="settings-main">
        <?php if ($tab === 'smtp'): ?>
        <form method="post" class="settings-card" autocomplete="off">
            <input type="hidden" name="tab" value="smtp">
            <div class="settings-card-head">
                <div class="settings-title-block">
                    <span class="settings-title-ico blue">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </span>
                    <div>
                        <h2>Email SMTP</h2>
                        <p>Outgoing mail server for OTP, reset links, and system emails.</p>
                    </div>
                </div>
                <span class="status-pill <?= $smtpOn ? 'online' : 'offline' ?>"><?= $smtpOn ? 'Enabled' : 'Disabled' ?></span>
            </div>

            <div class="settings-section">
                <div class="settings-section-head">
                    <span class="ssh-ico blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg></span>
                    <h3>Connection</h3>
                </div>
                <div class="settings-fields two">
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="sa-check-label">
                            <input type="checkbox" name="smtp_enabled" value="1" <?= $smtpOn ? 'checked' : '' ?>>
                            Enable SMTP (use instead of PHP mail)
                        </label>
                    </div>
                    <div class="form-group">
                        <label>SMTP Host</label>
                        <input type="text" name="smtp_host" value="<?= e($msg['smtp_host']) ?>" placeholder="smtp.gmail.com">
                    </div>
                    <div class="form-group">
                        <label>Port</label>
                        <input type="number" name="smtp_port" min="1" max="65535" value="<?= e($msg['smtp_port']) ?>" placeholder="587">
                    </div>
                    <div class="form-group">
                        <label>Encryption</label>
                        <select name="smtp_encryption">
                            <?php foreach (['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'] as $k => $lab): ?>
                            <option value="<?= $k ?>" <?= ($msg['smtp_encryption'] ?? '') === $k ? 'selected' : '' ?>><?= $lab ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="smtp_username" value="<?= e($msg['smtp_username']) ?>" placeholder="email@domain.com" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <div class="password-field">
                            <input type="password" name="smtp_password" value="" placeholder="<?= $msg['smtp_password'] !== '' ? 'Leave blank to keep current' : 'App password / SMTP password' ?>" autocomplete="new-password">
                            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                        <?php if ($msg['smtp_password'] !== ''): ?>
                        <span class="sa-field-hint">Saved: <?= e(messaging_mask_secret($msg['smtp_password'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-head">
                    <span class="ssh-ico teal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                    <h3>From identity</h3>
                </div>
                <div class="settings-fields two">
                    <div class="form-group">
                        <label>From Name</label>
                        <input type="text" name="smtp_from_name" value="<?= e($msg['smtp_from_name']) ?>" placeholder="Company Support">
                    </div>
                    <div class="form-group">
                        <label>From Email</label>
                        <input type="email" name="smtp_from_email" value="<?= e($msg['smtp_from_email']) ?>" placeholder="noreply@domain.com">
                    </div>
                </div>
            </div>

            <div class="settings-card-foot">
                <button type="submit" name="action" value="save" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                    Save SMTP settings
                </button>
                <button type="submit" name="action" value="test" class="btn btn-outline" formnovalidate>
                    Test connection
                </button>
                <input type="hidden" name="test_type" value="smtp">
            </div>
            <p class="sa-field-hint" style="margin:0.65rem 0 0">Test uses last saved settings (save first if you changed fields).</p>
        </form>

        <?php elseif ($tab === 'whatsapp'): ?>
        <form method="post" class="settings-card" autocomplete="off">
            <input type="hidden" name="tab" value="whatsapp">
            <div class="settings-card-head">
                <div class="settings-title-block">
                    <span class="settings-title-ico teal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                    </span>
                    <div>
                        <h2>WhatsApp API</h2>
                        <p>Business API credentials for order / OTP WhatsApp messages.</p>
                    </div>
                </div>
                <span class="status-pill <?= $waOn ? 'online' : 'offline' ?>"><?= $waOn ? 'Enabled' : 'Disabled' ?></span>
            </div>

            <div class="settings-section">
                <div class="settings-section-head">
                    <span class="ssh-ico teal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2"/></svg></span>
                    <h3>Provider &amp; credentials</h3>
                </div>
                <div class="settings-fields two">
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="sa-check-label">
                            <input type="checkbox" name="wa_api_enabled" value="1" <?= $waOn ? 'checked' : '' ?>>
                            Enable WhatsApp API messaging
                        </label>
                    </div>
                    <div class="form-group">
                        <label>Provider</label>
                        <select name="wa_api_provider">
                            <?php foreach (['meta' => 'Meta Cloud API', 'twilio' => 'Twilio', 'gupshup' => 'Gupshup', 'custom' => 'Custom HTTP'] as $k => $lab): ?>
                            <option value="<?= $k ?>" <?= ($msg['wa_api_provider'] ?? '') === $k ? 'selected' : '' ?>><?= e($lab) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>WhatsApp number</label>
                        <input type="text" name="wa_api_number" value="<?= e($msg['wa_api_number']) ?>" placeholder="919876543210">
                    </div>
                    <div class="form-group">
                        <label>API Key / App ID</label>
                        <input type="text" name="wa_api_key" value="<?= e($msg['wa_api_key']) ?>" placeholder="API key or App ID" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Access Token</label>
                        <div class="password-field">
                            <input type="password" name="wa_api_token" value="" placeholder="<?= $msg['wa_api_token'] !== '' ? 'Leave blank to keep current' : 'Bearer / access token' ?>" autocomplete="new-password">
                            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                        <?php if ($msg['wa_api_token'] !== ''): ?>
                        <span class="sa-field-hint">Saved: <?= e(messaging_mask_secret($msg['wa_api_token'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Custom endpoint (optional)</label>
                        <input type="url" name="wa_api_endpoint" value="<?= e($msg['wa_api_endpoint']) ?>" placeholder="https://graph.facebook.com/v19.0/.../messages">
                    </div>
                </div>
                <div class="settings-info">
                    <span class="si-ico">i</span>
                    <p>Credentials are stored for this install. Message send hooks can use these later for OTP / order alerts.</p>
                </div>
            </div>

            <div class="settings-card-foot">
                <button type="submit" name="action" value="save" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                    Save WhatsApp settings
                </button>
                <button type="submit" name="action" value="test" class="btn btn-outline" formnovalidate>
                    Test connection
                </button>
                <input type="hidden" name="test_type" value="whatsapp">
            </div>
            <p class="sa-field-hint" style="margin:0.65rem 0 0">Test uses last saved settings (save first if you changed fields).</p>
        </form>

        <?php elseif ($tab === 'sms'): ?>
        <form method="post" class="settings-card" autocomplete="off">
            <input type="hidden" name="tab" value="sms">
            <div class="settings-card-head">
                <div class="settings-title-block">
                    <span class="settings-title-ico pink">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    </span>
                    <div>
                        <h2>SMS API</h2>
                        <p>Transactional SMS via MSG91, Twilio, Textlocal, and more.</p>
                    </div>
                </div>
                <span class="status-pill <?= $smsOn ? 'online' : 'offline' ?>"><?= $smsOn ? 'Enabled' : 'Disabled' ?></span>
            </div>

            <div class="settings-section">
                <div class="settings-section-head">
                    <span class="ssh-ico pink"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg></span>
                    <h3>Provider &amp; credentials</h3>
                </div>
                <div class="settings-fields two">
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="sa-check-label">
                            <input type="checkbox" name="sms_api_enabled" value="1" <?= $smsOn ? 'checked' : '' ?>>
                            Enable SMS API messaging
                        </label>
                    </div>
                    <div class="form-group">
                        <label>Provider</label>
                        <select name="sms_api_provider">
                            <?php foreach (['msg91' => 'MSG91', 'twilio' => 'Twilio', 'textlocal' => 'Textlocal', 'fast2sms' => 'Fast2SMS', 'custom' => 'Custom HTTP'] as $k => $lab): ?>
                            <option value="<?= $k ?>" <?= ($msg['sms_api_provider'] ?? '') === $k ? 'selected' : '' ?>><?= e($lab) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sender ID</label>
                        <input type="text" name="sms_api_sender_id" value="<?= e($msg['sms_api_sender_id']) ?>" placeholder="e.g. MYCOMP" maxlength="20">
                    </div>
                    <div class="form-group">
                        <label>API Key / Account SID</label>
                        <div class="password-field">
                            <input type="password" name="sms_api_key" value="" placeholder="<?= $msg['sms_api_key'] !== '' ? 'Leave blank to keep current' : 'API key or Account SID' ?>" autocomplete="new-password">
                            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                        <?php if ($msg['sms_api_key'] !== ''): ?>
                        <span class="sa-field-hint">Saved: <?= e(messaging_mask_secret($msg['sms_api_key'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Auth Token (Twilio etc.)</label>
                        <div class="password-field">
                            <input type="password" name="sms_api_auth_token" value="" placeholder="<?= $msg['sms_api_auth_token'] !== '' ? 'Leave blank to keep current' : 'Optional auth token' ?>" autocomplete="new-password">
                            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                        <?php if ($msg['sms_api_auth_token'] !== ''): ?>
                        <span class="sa-field-hint">Saved: <?= e(messaging_mask_secret($msg['sms_api_auth_token'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Custom endpoint (optional)</label>
                        <input type="url" name="sms_api_endpoint" value="<?= e($msg['sms_api_endpoint']) ?>" placeholder="https://api.msg91.com/api/v5/flow/">
                    </div>
                </div>
            </div>

            <div class="settings-card-foot">
                <button type="submit" name="action" value="save" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                    Save SMS settings
                </button>
                <button type="submit" name="action" value="test" class="btn btn-outline" formnovalidate>
                    Test connection
                </button>
                <input type="hidden" name="test_type" value="sms">
            </div>
            <p class="sa-field-hint" style="margin:0.65rem 0 0">Test uses last saved settings (save first if you changed fields).</p>
        </form>

        <?php elseif ($tab === 'security'): ?>
        <form method="post" class="settings-card" autocomplete="off">
            <input type="hidden" name="tab" value="security">
            <div class="settings-card-head">
                <div class="settings-title-block">
                    <span class="settings-title-ico teal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </span>
                    <div>
                        <h2>Security</h2>
                        <p>Change Super Admin login password.</p>
                    </div>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-head">
                    <span class="ssh-ico green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
                    <h3>Admin Password</h3>
                </div>
                <div class="settings-fields two">
                    <div class="form-group" style="grid-column:1/-1">
                        <label for="current_password">Current Password</label>
                        <div class="password-field">
                            <input type="password" id="current_password" name="current_password" required autocomplete="current-password" placeholder="Current password">
                            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <div class="password-field">
                            <input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters">
                            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="password-field">
                            <input type="password" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password" placeholder="Repeat new password">
                            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="settings-info">
                    <span class="si-ico">i</span>
                    <p>Minimum 8 characters. Don’t reuse the default <code>superadmin123</code>. Logout on shared devices after changing.</p>
                </div>
            </div>

            <div class="settings-card-foot">
                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                    Update password
                </button>
            </div>
        </form>

        <?php elseif ($tab === 'database'):
            $dbInfo = $dbInfo ?? db_tools_info($pdo);
            $dbCreds = $dbCreds ?? app_db_credentials();
            $online = $dbCreds['live'];
            $offline = $dbCreds['local'];
            $mode = (string) ($dbCreds['mode'] ?? 'auto');
            $company = setting('company_name', 'Bharat Seva');
        ?>
        <form method="post" class="settings-card sa-db-card" autocomplete="off" id="saDbForm">
            <input type="hidden" name="tab" value="database">
            <div class="settings-card-head">
                <div class="settings-title-block">
                    <span class="settings-title-ico orange">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                    </span>
                    <div>
                        <h2>Online &amp; Offline DB</h2>
                        <p>Cloud and local XAMPP connection used by this <?= e($company) ?> install.</p>
                    </div>
                </div>
                <span class="status-pill <?= !empty($dbInfo['ok']) ? 'online' : 'offline' ?>"><?= !empty($dbInfo['ok']) ? 'ACCESS OK' : 'ERROR' ?></span>
            </div>

            <div class="sa-db-body">
            <div class="sa-db-banner">
                Connected to <strong><?= e((string) ($dbInfo['slot_label'] ?? 'Database')) ?> Database</strong>.
                Mode: <?= e(ucfirst((string) ($dbInfo['mode'] ?? 'auto'))) ?> -
                <?= e((string) ($dbInfo['host'] ?? '')) ?> / <?= e((string) ($dbInfo['name'] ?? '')) ?>
            </div>

            <div class="sa-db-setup-row">
                <div>
                    <h3>Database setup</h3>
                    <p>Verify tables and columns.</p>
                </div>
                <button type="submit" name="action" value="run_setup" class="btn sa-btn-violet" data-confirm="Run database setup / verify tables on the active connection?">Run database setup</button>
            </div>

            <div class="sa-db-mode-block">
                <h3>Connection mode</h3>
                <div class="sa-db-mode-grid">
                    <label class="sa-db-mode <?= $mode === 'auto' ? 'is-on' : '' ?>">
                        <input type="radio" name="db_mode" value="auto" <?= $mode === 'auto' ? 'checked' : '' ?>>
                        <strong>Auto</strong>
                        <span>Online when available, then offline.</span>
                    </label>
                    <label class="sa-db-mode <?= $mode === 'online' ? 'is-on' : '' ?>">
                        <input type="radio" name="db_mode" value="online" <?= $mode === 'online' ? 'checked' : '' ?>>
                        <strong>Online only</strong>
                        <span>Always use cloud server.</span>
                    </label>
                    <label class="sa-db-mode <?= $mode === 'offline' ? 'is-on' : '' ?>">
                        <input type="radio" name="db_mode" value="offline" <?= $mode === 'offline' ? 'checked' : '' ?>>
                        <strong>Offline only</strong>
                        <span>Always use local XAMPP.</span>
                    </label>
                </div>
            </div>

            <div class="sa-db-dual">
                <div class="sa-db-panel">
                    <div class="sa-db-panel-head">
                        <h3>Online Database</h3>
                        <p>Hosting / VPS / cloud MySQL</p>
                    </div>
                    <div class="sa-db-fields">
                        <div class="form-group sa-db-host">
                            <label>Host</label>
                            <input type="text" name="online_host" value="<?= e((string) ($online['host'] ?? '')) ?>" required>
                        </div>
                        <div class="form-group sa-db-port">
                            <label>Port</label>
                            <input type="text" name="online_port" value="<?= e((string) ($online['port'] ?? '3306')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Database</label>
                            <input type="text" name="online_name" value="<?= e((string) ($online['name'] ?? '')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" name="online_user" value="<?= e((string) ($online['user'] ?? '')) ?>" required autocomplete="off">
                        </div>
                        <div class="form-group sa-db-pass">
                            <label>Password</label>
                            <div class="password-field">
                                <input type="password" name="online_pass" value="" placeholder="Leave blank to keep current" autocomplete="new-password">
                                <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                                    <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                            <span class="sa-field-hint">Saved: <?= e(db_tools_mask_pass((string) ($online['pass'] ?? ''))) ?></span>
                        </div>
                    </div>
                    <button type="submit" name="action" value="test_online" class="btn btn-outline sa-db-test" formnovalidate>Test online connection</button>
                </div>

                <div class="sa-db-panel">
                    <div class="sa-db-panel-head">
                        <h3>Offline Database</h3>
                        <p>Local XAMPP / WAMP on this computer</p>
                    </div>
                    <div class="sa-db-fields">
                        <div class="form-group sa-db-host">
                            <label>Host</label>
                            <input type="text" name="offline_host" value="<?= e((string) ($offline['host'] ?? '')) ?>" required>
                        </div>
                        <div class="form-group sa-db-port">
                            <label>Port</label>
                            <input type="text" name="offline_port" value="<?= e((string) ($offline['port'] ?? '3306')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Database</label>
                            <input type="text" name="offline_name" value="<?= e((string) ($offline['name'] ?? '')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" name="offline_user" value="<?= e((string) ($offline['user'] ?? '')) ?>" required autocomplete="off">
                        </div>
                        <div class="form-group sa-db-pass">
                            <label>Password</label>
                            <div class="password-field">
                                <input type="password" name="offline_pass" value="" placeholder="Usually empty on XAMPP" autocomplete="new-password">
                                <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                                    <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                            <span class="sa-field-hint">Saved: <?= e(db_tools_mask_pass((string) ($offline['pass'] ?? ''))) ?></span>
                        </div>
                    </div>
                    <button type="submit" name="action" value="test_offline" class="btn btn-outline sa-db-test" formnovalidate>Test offline connection</button>
                </div>
            </div>

            <div class="sa-db-banner soft">
                <span class="si-ico">i</span>
                <p>Saved in <code>config/db-credentials.php</code>. On a local PC Auto prefers offline; on the live server it prefers online.</p>
            </div>
            </div>

            <div class="settings-card-foot">
                <button type="submit" name="action" value="save" class="btn sa-btn-violet">Save database settings</button>
            </div>
        </form>
        <script>
        document.querySelectorAll('.sa-db-mode').forEach(function (lab) {
            lab.addEventListener('click', function () {
                document.querySelectorAll('.sa-db-mode').forEach(function (x) { x.classList.remove('is-on'); });
                lab.classList.add('is-on');
            });
        });
        </script>

        <?php elseif ($tab === 'backup'):
            $dbInfo = $dbInfo ?? db_tools_info($pdo);
            $lastDl = db_tools_format_meta_time(db_tools_meta('db_backup_last_download'));
            $lastRs = db_tools_format_meta_time(db_tools_meta('db_backup_last_restore'));
            $sampleName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) ($dbInfo['name'] ?? 'db')) . '-backup-' . date('Ymd-His') . '.sql';
        ?>
        <div class="settings-card sa-bk-card">
            <div class="settings-card-head">
                <div class="settings-title-block">
                    <span class="settings-title-ico green">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </span>
                    <div>
                        <h2>Backup &amp; Restore</h2>
                        <p>SQL dump for this install. Restore only on a maintenance window.</p>
                    </div>
                </div>
                <span class="status-pill online">ACCESS OK</span>
            </div>

            <div class="sa-bk-body">
            <div class="sa-bk-stats">
                <article>
                    <span>Active database</span>
                    <strong><?= e((string) ($dbInfo['name'] ?? DB_NAME)) ?></strong>
                </article>
                <article>
                    <span>Tables included</span>
                    <strong><?= (int) ($dbInfo['tables'] ?? 0) ?></strong>
                </article>
                <article>
                    <span>Last download</span>
                    <strong><?= e($lastDl) ?></strong>
                </article>
                <article>
                    <span>Last restore</span>
                    <strong><?= e($lastRs) ?></strong>
                </article>
            </div>

            <div class="sa-bk-grid">
                <div class="sa-bk-panel is-safe">
                    <span class="sa-bk-badge ok">SAFE</span>
                    <h3>Download backup</h3>
                    <p>Exports every table in the active database as a .sql file. Store it off this server.</p>
                    <ul>
                        <li>Structure and data for all tables</li>
                        <li>Filename like <code><?= e($sampleName) ?></code></li>
                        <li>Does not change anything on this install</li>
                    </ul>
                    <form method="post">
                        <input type="hidden" name="tab" value="backup">
                        <button type="submit" name="action" value="download_backup" class="btn sa-btn-violet">Download SQL backup</button>
                    </form>
                </div>

                <div class="sa-bk-panel is-danger">
                    <span class="sa-bk-badge bad">DESTRUCTIVE</span>
                    <h3>Restore backup</h3>
                    <p>Replaces current data with the uploaded dump. Members, wallets and settings will match the file.</p>
                    <form method="post" enctype="multipart/form-data" id="saRestoreForm">
                        <input type="hidden" name="tab" value="backup">
                        <label class="sa-bk-drop" id="saBkDrop">
                            <input type="file" name="backup_file" id="saBkFile" accept=".sql,application/sql,text/plain" hidden>
                            <span class="sa-bk-drop-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            </span>
                            <strong>Drop a .sql backup here</strong>
                            <em>or click to browse · max 32 MB · dumps from this page only</em>
                            <span class="btn btn-outline btn-sm sa-bk-browse" id="saBkBrowse">Browse file</span>
                            <small id="saBkFileName" class="sa-bk-fname"></small>
                        </label>
                        <label class="sa-check-label sa-bk-confirm">
                            <input type="checkbox" name="confirm_restore" value="1" id="saBkConfirm">
                            I understand this overwrites the current database.
                        </label>
                        <button type="submit" name="action" value="restore_upload" class="btn sa-bk-restore-btn" id="saBkRestoreBtn" disabled data-confirm="RESTORE will overwrite the live database. Continue?">Restore now</button>
                    </form>
                </div>
            </div>

            <div class="sa-db-banner soft">
                <span class="si-ico">i</span>
                <p>Only restore files created from this page. Super Admin access stays after a restore - you may need to sign in again. Active connection is <code><?= e((string) ($dbInfo['name'] ?? DB_NAME)) ?></code>.</p>
            </div>
            </div>
        </div>
        <script>
        (function () {
            var file = document.getElementById('saBkFile');
            var nameEl = document.getElementById('saBkFileName');
            var confirm = document.getElementById('saBkConfirm');
            var btn = document.getElementById('saBkRestoreBtn');
            var drop = document.getElementById('saBkDrop');
            var browse = document.getElementById('saBkBrowse');
            function sync() {
                var hasFile = file && file.files && file.files.length > 0;
                if (nameEl) nameEl.textContent = hasFile ? file.files[0].name : '';
                if (btn) btn.disabled = !(hasFile && confirm && confirm.checked);
            }
            if (browse && file) browse.addEventListener('click', function (e) { e.preventDefault(); file.click(); });
            if (file) file.addEventListener('change', sync);
            if (confirm) confirm.addEventListener('change', sync);
            if (drop) {
                ['dragenter','dragover'].forEach(function (ev) {
                    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-drag'); });
                });
                ['dragleave','drop'].forEach(function (ev) {
                    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-drag'); });
                });
                drop.addEventListener('drop', function (e) {
                    var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                    if (!f || !file) return;
                    try {
                        var dt = new DataTransfer();
                        dt.items.add(f);
                        file.files = dt.files;
                    } catch (err) {}
                    sync();
                });
            }
            sync();
        })();
        </script>

        <?php elseif ($tab === 'activity'): ?>
        <div class="settings-card">
            <div class="settings-card-head">
                <div class="settings-title-block">
                    <span class="settings-title-ico orange">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </span>
                    <div>
                        <h2>Activity Log</h2>
                        <p>Recent Super Admin actions on this install.</p>
                    </div>
                </div>
            </div>
            <div class="table-wrap" style="border:0;border-radius:0;box-shadow:none">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Details</th>
                            <th>IP</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$logs): ?>
                        <tr><td colspan="4" class="empty-state">No Super Admin activity yet.</td></tr>
                    <?php else: foreach ($logs as $l):
                        $act = (string) ($l['action'] ?? '');
                        $act = str_starts_with($act, 'superadmin:') ? substr($act, 11) : $act;
                    ?>
                        <tr>
                            <td><span class="pkg-chip"><?= e($act) ?></span></td>
                            <td><?= e((string) ($l['details'] ?? '')) ?></td>
                            <td><span class="muted"><?= e((string) ($l['ip_address'] ?? '')) ?></span></td>
                            <td><span class="muted"><?= !empty($l['created_at']) ? e(date('d M Y H:i', strtotime((string) $l['created_at']))) : '—' ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </section>
</div>

<script>
document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var wrap = btn.closest('.password-field');
        var input = wrap && wrap.querySelector('input');
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.classList.toggle('is-visible', show);
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.setAttribute('title', show ? 'Hide password' : 'Show password');
    });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
