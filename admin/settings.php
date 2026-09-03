<?php
require_once __DIR__ . '/../config/database.php';
$pageTitle = 'Settings';

$tab = $_GET['tab'] ?? 'general';
$allowedTabs = ['general', 'withdrawal', 'contact', 'security', 'activity'];
if (!feature_module_allowed('withdrawals')) {
    $allowedTabs = array_values(array_filter($allowedTabs, static fn ($t) => $t !== 'withdrawal'));
}
if (!in_array($tab, $allowedTabs, true)) {
    if ($tab === 'commission') {
        flash('error', 'Commission rates are configured for this install and cannot be edited here.');
        header('Location: settings.php?tab=general');
        exit;
    }
    if ($tab === 'withdrawal') {
        flash('error', 'Withdrawal module is not available for this install.');
        header('Location: settings.php?tab=general');
        exit;
    }
    $tab = 'general';
}

$commissionSub = $_GET['sub'] ?? 'binary';
$allowedCommissionSubs = ['binary', 'level'];
if (!in_array($commissionSub, $allowedCommissionSubs, true)) {
    $commissionSub = 'binary';
}

$saveSetting = static function (PDO $pdo, string $key, string $val): void {
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $val]);
};

// Contact inquiry actions (mark read / reply / archive / delete)
if (isset($_GET['inquiry_action'], $_GET['inquiry_id'])) {
    $inqId = (int) $_GET['inquiry_id'];
    $inqAction = $_GET['inquiry_action'];
    if ($inqId > 0) {
        if ($inqAction === 'delete') {
            $pdo->prepare('DELETE FROM contact_inquiries WHERE id = ?')->execute([$inqId]);
            log_activity('contact_inquiry_delete', "Deleted inquiry #$inqId");
            flash('success', 'Inquiry deleted.');
        } elseif (in_array($inqAction, ['read', 'replied', 'archived', 'new'], true)) {
            $pdo->prepare('UPDATE contact_inquiries SET status = ? WHERE id = ?')->execute([$inqAction, $inqId]);
            log_activity('contact_inquiry_status', "Inquiry #$inqId set to $inqAction");
            flash('success', 'Inquiry status updated.');
        }
    }
    header('Location: settings.php?tab=contact#inquiries');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postTab = $_POST['tab'] ?? 'general';
    $postSub = $_POST['sub'] ?? 'binary';
    $keysByTab = [
        'general' => [
            'company_name',
            'support_email',
            'maintenance_mode',
            'currency',
            'currency_symbol',
        ],
        'withdrawal' => [
            'min_withdrawal',
            'max_withdrawal',
            'processing_fee_percent',
            'tds_deduction_percent',
        ],
        'contact' => [
            'contact_person',
            'contact_phone',
            'contact_whatsapp',
            'contact_email',
            'contact_alt_phone',
            'contact_address',
            'contact_city',
            'contact_state',
            'contact_country',
            'contact_pincode',
            'contact_hours',
            'contact_map_url',
            'contact_facebook',
            'contact_instagram',
            'contact_twitter',
            'contact_youtube',
            'contact_telegram',
            'contact_form_notify_email',
        ],
    ];

    if ($postTab === 'commission') {
        flash('error', 'Commission rates are configured for this install and cannot be edited here.');
        header('Location: settings.php?tab=general');
        exit;
    } elseif ($postTab === 'withdrawal' && !feature_module_allowed('withdrawals')) {
        flash('error', 'Withdrawal module is not available for this install.');
        header('Location: settings.php?tab=general');
        exit;
    } elseif ($postTab === 'contact') {
        foreach ($keysByTab['contact'] as $key) {
            if (isset($_POST[$key])) {
                $saveSetting($pdo, $key, trim((string) $_POST[$key]));
            }
        }
        $saveSetting($pdo, 'contact_form_enabled', isset($_POST['contact_form_enabled']) ? '1' : '0');
    } else {
        $keys = $keysByTab[$postTab] ?? [];
        $prevMaintenance = setting('maintenance_mode', 'off');
        foreach ($keys as $key) {
            if (isset($_POST[$key])) {
                $val = trim((string) $_POST[$key]);
                if ($key === 'maintenance_mode') {
                    $val = ($val === 'on') ? 'on' : 'off';
                }
                $saveSetting($pdo, $key, $val);
            }
        }

        if ($postTab === 'general') {
            $sigUp = branding_store_image($_FILES['company_signature'] ?? [], 'signature');
            if (!$sigUp['ok']) {
                flash('error', $sigUp['error'] ?? 'Signature upload failed.');
                header('Location: settings.php?tab=general');
                exit;
            }
            if (!empty($sigUp['path'])) {
                branding_delete_file(setting('company_signature', ''));
                $saveSetting($pdo, 'company_signature', $sigUp['path']);
            } elseif (!empty($_POST['remove_signature'])) {
                branding_delete_file(setting('company_signature', ''));
                $saveSetting($pdo, 'company_signature', '');
            }
        }

        clear_setting_cache();
        if ($postTab === 'general' && isset($_POST['maintenance_mode'])) {
            $newMode = ($_POST['maintenance_mode'] === 'on') ? 'on' : 'off';
            if ($newMode === 'on' && $prevMaintenance !== 'on') {
                $saveSetting($pdo, 'maintenance_started_at', date('Y-m-d H:i:s'));
                clear_setting_cache('maintenance_started_at');
                log_activity('maintenance_on', 'Maintenance mode enabled — member portal locked');
                flash('success', 'Maintenance mode ON. Logged-in members will be signed out on their next request, and new logins are blocked.');
                header('Location: settings.php?tab=general');
                exit;
            }
            if ($newMode === 'off' && $prevMaintenance === 'on') {
                log_activity('maintenance_off', 'Maintenance mode disabled — member portal open');
                flash('success', 'Maintenance mode OFF. Members can sign in again.');
                header('Location: settings.php?tab=general');
                exit;
            }
        }
    }

    if ($postTab === 'security') {
        $newPass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($newPass !== '') {
            if (strlen($newPass) < 6) {
                flash('error', 'Password must be at least 6 characters.');
                header('Location: settings.php?tab=security');
                exit;
            }
            if ($newPass !== $confirm) {
                flash('error', 'Passwords do not match.');
                header('Location: settings.php?tab=security');
                exit;
            }
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE admins SET password = ? WHERE id = ?')->execute([$hash, $_SESSION['admin_id']]);
            log_activity('password_change', 'Admin changed password');
        }
    }

    log_activity('settings_update', 'Updated ' . $postTab . ($postTab === 'commission' ? ' / ' . $postSub : '') . ' settings');
    flash('success', 'Settings saved successfully.');
    $redirect = 'settings.php?tab=' . urlencode($postTab);
    if ($postTab === 'commission') {
        $redirect .= '&sub=' . urlencode(in_array($postSub, $allowedCommissionSubs, true) ? $postSub : 'binary');
    }
    header('Location: ' . $redirect);
    exit;
}

$settings = [];
$rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
foreach ($rows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

$logs = $pdo->query("
    SELECT l.*, a.username
    FROM activity_logs l
    LEFT JOIN admins a ON a.id = l.admin_id
    WHERE l.action NOT LIKE 'superadmin:%'
    ORDER BY l.id DESC
    LIMIT 30
")->fetchAll();

$maintenance = $settings['maintenance_mode'] ?? 'off';
$isOnline = $maintenance !== 'on';
$signatureUrl = company_signature_url();

$contactInquiries = [];
$contactNewCount = 0;
if ($tab === 'contact') {
    try {
        $contactInquiries = $pdo->query('SELECT * FROM contact_inquiries ORDER BY FIELD(status, "new","read","replied","archived"), id DESC LIMIT 50')->fetchAll();
        $contactNewCount = (int) $pdo->query("SELECT COUNT(*) FROM contact_inquiries WHERE status = 'new'")->fetchColumn();
    } catch (Throwable $e) {
        $contactInquiries = [];
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="settings-layout">
    <aside class="settings-nav">
        <div class="settings-nav-group">
            <span class="settings-nav-label">Workspace</span>
            <a href="settings.php?tab=general" class="settings-nav-item <?= $tab === 'general' ? 'active' : '' ?>">
                <span class="sni-ico red">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                </span>
                General
            </a>
            <?php if (feature_module_allowed('withdrawals')): ?>
            <a href="settings.php?tab=withdrawal" class="settings-nav-item <?= $tab === 'withdrawal' ? 'active' : '' ?>">
                <span class="sni-ico pink">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                </span>
                Withdrawal Rules
            </a>
            <?php endif; ?>
            <a href="settings.php?tab=contact" class="settings-nav-item <?= $tab === 'contact' ? 'active' : '' ?>">
                <span class="sni-ico teal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                </span>
                Contact Setting
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
        <?php if ($tab === 'general'): ?>
        <form method="post" class="settings-card" enctype="multipart/form-data">
            <input type="hidden" name="tab" value="general">
            <div class="settings-card-head">
                <div class="settings-title-block">
                    <span class="settings-title-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
                    </span>
                    <div>
                        <h2>General settings</h2>
                        <p>Site identity, authorized signature, and maintenance mode.</p>
                    </div>
                </div>
                <?php if ($isOnline): ?>
                <span class="status-pill online">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Online
                </span>
                <?php else: ?>
                <span class="status-pill offline">Maintenance</span>
                <?php endif; ?>
            </div>

            <div class="settings-section">
                <div class="settings-section-head">
                    <span class="ssh-ico blue">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg>
                    </span>
                    <h3>Site Identity</h3>
                </div>
                <div class="settings-fields two">
                    <div class="form-group">
                        <label>Site Name</label>
                        <input type="text" name="company_name" value="<?= e($settings['company_name'] ?? 'Binary MLM') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Support Email</label>
                        <input type="email" name="support_email" value="<?= e($settings['support_email'] ?? 'support@binarymlm.com') ?>">
                    </div>
                    <div class="form-group">
                        <label>Currency</label>
                        <input type="text" name="currency" value="<?= e($settings['currency'] ?? 'INR') ?>">
                    </div>
                    <div class="form-group">
                        <label>Currency Symbol</label>
                        <input type="text" name="currency_symbol" value="<?= e($settings['currency_symbol'] ?? '₹') ?>">
                    </div>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-head">
                    <span class="ssh-ico teal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.85 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                    </span>
                    <h3>Authorized Signature</h3>
                </div>
                <div class="settings-sign-upload" data-sign-upload>
                    <input type="file" name="company_signature" id="companySignatureFile" class="settings-sign-input" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                    <div class="settings-sign-preview<?= $signatureUrl ? ' has-file' : '' ?>" id="companySignaturePreview">
                        <?php if ($signatureUrl): ?>
                            <img src="<?= e($signatureUrl) ?>" alt="Authorized signature">
                        <?php else: ?>
                            <span class="settings-sign-empty" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.85 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                                <em>No signature</em>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="settings-sign-meta">
                        <p>Upload a clear signature image (PNG with transparent background recommended). Used on purchase invoices, welcome letter, and other authorized documents.</p>
                        <div class="settings-sign-actions">
                            <label for="companySignatureFile" class="btn btn-outline btn-sm">Choose signature</label>
                            <?php if ($signatureUrl): ?>
                            <label class="settings-sign-remove">
                                <input type="checkbox" name="remove_signature" value="1">
                                <span>Remove current signature</span>
                            </label>
                            <?php endif; ?>
                        </div>
                        <span class="settings-sign-name" id="companySignatureName"><?= $signatureUrl ? 'Current signature set' : 'JPG, PNG or WebP · max 2MB' ?></span>
                    </div>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-head">
                    <span class="ssh-ico green">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </span>
                    <h3>Portal Availability</h3>
                </div>
                <div class="form-group">
                    <label>Maintenance Mode</label>
                    <select name="maintenance_mode">
                        <option value="off" <?= $maintenance !== 'on' ? 'selected' : '' ?>>Website Online — members can access the portal</option>
                        <option value="on" <?= $maintenance === 'on' ? 'selected' : '' ?>>Maintenance On — members cannot sign in</option>
                    </select>
                </div>
                <div class="settings-info">
                    <span class="si-ico">i</span>
                    <p>When ON: members are signed out on their next click, login/register stay blocked, and the admin panel remains available. Turn OFF to reopen the portal.</p>
                </div>
            </div>

            <div class="settings-card-foot">
                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                    Save general settings
                </button>
            </div>
        </form>

        <?php // Commission tab removed — rates are not editable in Client Admin settings.
        elseif ($tab === 'withdrawal' && feature_module_allowed('withdrawals')):
            $minPayout = number_format((float) ($settings['min_withdrawal'] ?? 500), 2, '.', '');
            $maxPayout = number_format((float) ($settings['max_withdrawal'] ?? 0), 2, '.', '');
            $adminCharges = number_format((float) ($settings['processing_fee_percent'] ?? 1), 2, '.', '');
            $tdsDeduction = number_format((float) ($settings['tds_deduction_percent'] ?? 5), 2, '.', '');
        ?>
        <form method="post" class="settings-card">
            <input type="hidden" name="tab" value="withdrawal">
            <div class="settings-card-head">
                <div class="settings-title-block">
                    <span class="settings-title-ico pink">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </span>
                    <div>
                        <h2>Withdrawal rules</h2>
                        <p>Payout limits, fees, TDS and admin charges.</p>
                    </div>
                </div>
            </div>
            <div class="wr-rules-grid">
                <div class="wr-rule-card">
                    <span class="wr-rule-ico green">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 10h.01M18 14h.01"/></svg>
                    </span>
                    <label for="min_withdrawal">Minimum Payout (<?= currency_symbol_html() ?>)</label>
                    <input type="number" step="0.01" min="0" id="min_withdrawal" name="min_withdrawal" value="<?= e($minPayout) ?>">
                </div>
                <div class="wr-rule-card">
                    <span class="wr-rule-ico teal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M12 8v8M8 12h8"/></svg>
                    </span>
                    <label for="max_withdrawal">Maximum Payout (<?= currency_symbol_html() ?>)</label>
                    <input type="number" step="0.01" min="0" id="max_withdrawal" name="max_withdrawal" value="<?= e($maxPayout) ?>">
                    <small class="field-hint">0 = no limit (only wallet balance applies)</small>
                </div>
                <div class="wr-rule-card">
                    <span class="wr-rule-ico blue">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M9 9.5a2.5 2.5 0 014.5 1.5c0 1.5-2.5 2-2.5 3.5M12 16.5v.5"/></svg>
                    </span>
                    <label for="processing_fee_percent">Admin Charges (%)</label>
                    <input type="number" step="0.01" min="0" id="processing_fee_percent" name="processing_fee_percent" value="<?= e($adminCharges) ?>">
                </div>
                <div class="wr-rule-card">
                    <span class="wr-rule-ico orange">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg>
                    </span>
                    <label for="tds_deduction_percent">TDS Deduction (%)</label>
                    <input type="number" step="0.01" min="0" id="tds_deduction_percent" name="tds_deduction_percent" value="<?= e($tdsDeduction) ?>">
                </div>
            </div>
            <div class="settings-card-foot">
                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                    Save withdrawal settings
                </button>
            </div>
        </form>

        <?php elseif ($tab === 'contact'):
            $c = static function (array $settings, string $key, string $default = '') {
                return $settings[$key] ?? $default;
            };
            $formEnabled = ($settings['contact_form_enabled'] ?? '1') === '1';
            $wa = preg_replace('/\D+/', '', $c($settings, 'contact_whatsapp'));
            $fullAddress = trim(implode(', ', array_filter([
                $c($settings, 'contact_address'),
                $c($settings, 'contact_city'),
                $c($settings, 'contact_state'),
                $c($settings, 'contact_pincode'),
                $c($settings, 'contact_country'),
            ])));
        ?>
        <form method="post" class="settings-card">
            <input type="hidden" name="tab" value="contact">
            <div class="settings-card-head">
                <div class="settings-title-block">
                    <span class="settings-title-ico teal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                    </span>
                    <div>
                        <h2>Contact setting</h2>
                        <p>Phone, email, address, social links and inquiry form.</p>
                    </div>
                </div>
                <a href="../contact.php" target="_blank" rel="noopener" class="btn btn-outline btn-sm">Preview page</a>
            </div>

            <div class="settings-section">
                <div class="settings-section-head">
                    <span class="ssh-ico blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg></span>
                    <h3>Primary Contact</h3>
                </div>
                <div class="settings-fields two">
                    <div class="form-group">
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" value="<?= e($c($settings, 'contact_person', 'Support Team')) ?>">
                    </div>
                    <div class="form-group">
                        <label>Support Email</label>
                        <input type="email" name="contact_email" value="<?= e($c($settings, 'contact_email', $c($settings, 'support_email', 'support@binarymlm.com'))) ?>">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="contact_phone" value="<?= e($c($settings, 'contact_phone')) ?>" placeholder="+91 98765 43210">
                    </div>
                    <div class="form-group">
                        <label>WhatsApp Number</label>
                        <input type="text" name="contact_whatsapp" value="<?= e($c($settings, 'contact_whatsapp')) ?>" placeholder="919876543210">
                        <small class="field-hint">Country code + number, no spaces (for wa.me link)</small>
                    </div>
                    <div class="form-group">
                        <label>Alternate Phone</label>
                        <input type="text" name="contact_alt_phone" value="<?= e($c($settings, 'contact_alt_phone')) ?>">
                    </div>
                    <div class="form-group">
                        <label>Business Hours</label>
                        <input type="text" name="contact_hours" value="<?= e($c($settings, 'contact_hours', 'Mon–Sat, 10:00 AM – 6:00 PM')) ?>">
                    </div>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-head">
                    <span class="ssh-ico green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
                    <h3>Office Address</h3>
                </div>
                <div class="settings-fields two">
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Street / Office Address</label>
                        <input type="text" name="contact_address" value="<?= e($c($settings, 'contact_address')) ?>">
                    </div>
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" name="contact_city" value="<?= e($c($settings, 'contact_city')) ?>">
                    </div>
                    <div class="form-group">
                        <label>State</label>
                        <input type="text" name="contact_state" value="<?= e($c($settings, 'contact_state')) ?>">
                    </div>
                    <div class="form-group">
                        <label>Country</label>
                        <input type="text" name="contact_country" value="<?= e($c($settings, 'contact_country', 'India')) ?>">
                    </div>
                    <div class="form-group">
                        <label>Pincode</label>
                        <input type="text" name="contact_pincode" value="<?= e($c($settings, 'contact_pincode')) ?>">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Google Maps URL</label>
                        <input type="url" name="contact_map_url" value="<?= e($c($settings, 'contact_map_url')) ?>" placeholder="https://maps.google.com/...">
                    </div>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-head">
                    <span class="ssh-ico pink"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg></span>
                    <h3>Social Links</h3>
                </div>
                <div class="settings-fields two">
                    <div class="form-group">
                        <label>Facebook</label>
                        <input type="url" name="contact_facebook" value="<?= e($c($settings, 'contact_facebook')) ?>" placeholder="https://facebook.com/...">
                    </div>
                    <div class="form-group">
                        <label>Instagram</label>
                        <input type="url" name="contact_instagram" value="<?= e($c($settings, 'contact_instagram')) ?>">
                    </div>
                    <div class="form-group">
                        <label>Twitter / X</label>
                        <input type="url" name="contact_twitter" value="<?= e($c($settings, 'contact_twitter')) ?>">
                    </div>
                    <div class="form-group">
                        <label>YouTube</label>
                        <input type="url" name="contact_youtube" value="<?= e($c($settings, 'contact_youtube')) ?>">
                    </div>
                    <div class="form-group">
                        <label>Telegram</label>
                        <input type="url" name="contact_telegram" value="<?= e($c($settings, 'contact_telegram')) ?>">
                    </div>
                </div>
            </div>

            <div class="settings-section">
                <div class="settings-section-head">
                    <span class="ssh-ico blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>
                    <h3>Contact Form</h3>
                </div>
                <label class="settings-toggle">
                    <input type="checkbox" name="contact_form_enabled" value="1" <?= $formEnabled ? 'checked' : '' ?>>
                    <span class="toggle-ui"></span>
                    <span class="toggle-text">Enable public contact form</span>
                </label>
                <div class="settings-fields two">
                    <div class="form-group">
                        <label>Notify Email (inquiries)</label>
                        <input type="email" name="contact_form_notify_email" value="<?= e($c($settings, 'contact_form_notify_email', $c($settings, 'contact_email'))) ?>">
                        <small class="field-hint">Shown as support mail; inquiries are also stored in admin</small>
                    </div>
                </div>
                <div class="settings-info">
                    <span class="si-ico">i</span>
                    <p>Public page: <strong>contact.php</strong> — shows these details and accepts inquiries when the form is enabled.</p>
                </div>
            </div>

            <?php if ($fullAddress || $c($settings, 'contact_phone') || $c($settings, 'contact_email')): ?>
            <div class="contact-preview">
                <div class="contact-preview-head">Live preview</div>
                <div class="contact-preview-grid">
                    <?php if ($c($settings, 'contact_phone')): ?>
                    <div class="cp-item">
                        <strong>Phone</strong>
                        <span><?= e($c($settings, 'contact_phone')) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($c($settings, 'contact_email')): ?>
                    <div class="cp-item">
                        <strong>Email</strong>
                        <span><?= e($c($settings, 'contact_email')) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($wa): ?>
                    <div class="cp-item">
                        <strong>WhatsApp</strong>
                        <span><a href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener">wa.me/<?= e($wa) ?></a></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($fullAddress): ?>
                    <div class="cp-item">
                        <strong>Address</strong>
                        <span><?= e($fullAddress) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($c($settings, 'contact_hours')): ?>
                    <div class="cp-item">
                        <strong>Hours</strong>
                        <span><?= e($c($settings, 'contact_hours')) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="settings-card-foot">
                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                    Save contact settings
                </button>
            </div>
        </form>

        <div class="settings-card" id="inquiries" style="margin-top:1.15rem">
            <div class="settings-card-head">
                <div class="settings-title-block">
                    <span class="settings-title-ico orange">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    </span>
                    <div>
                        <h2>Contact inquiries</h2>
                        <p><?= $contactNewCount ?> new · latest <?= count($contactInquiries) ?> messages</p>
                    </div>
                </div>
            </div>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>Subject</th>
                            <th>Message</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$contactInquiries): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <strong>No inquiries yet</strong>
                                    <span>Messages from contact.php will appear here.</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: foreach ($contactInquiries as $inq): ?>
                        <tr class="<?= $inq['status'] === 'new' ? 'inq-new' : '' ?>">
                            <td>
                                <div class="member-cell">
                                    <strong><?= e($inq['name']) ?></strong>
                                    <span><?= e($inq['email']) ?></span>
                                    <?php if ($inq['phone']): ?><span><?= e($inq['phone']) ?></span><?php endif; ?>
                                </div>
                            </td>
                            <td><?= e($inq['subject'] ?: '—') ?></td>
                            <td><div class="inq-msg"><?= e($inq['message']) ?></div></td>
                            <td><?= status_badge($inq['status']) ?></td>
                            <td><span class="muted"><?= date('d M Y H:i', strtotime($inq['created_at'])) ?></span></td>
                            <td>
                                <div class="action-icons" style="flex-wrap:wrap;gap:0.35rem">
                                    <?php if ($inq['status'] === 'new'): ?>
                                    <a href="?tab=contact&inquiry_action=read&inquiry_id=<?= (int) $inq['id'] ?>" class="btn btn-outline btn-sm">Mark read</a>
                                    <?php endif; ?>
                                    <a href="?tab=contact&inquiry_action=replied&inquiry_id=<?= (int) $inq['id'] ?>" class="btn btn-outline btn-sm">Replied</a>
                                    <a href="mailto:<?= e($inq['email']) ?>?subject=<?= e(rawurlencode('Re: ' . ($inq['subject'] ?: 'Your inquiry'))) ?>" class="btn btn-primary btn-sm">Reply</a>
                                    <a href="?tab=contact&inquiry_action=delete&inquiry_id=<?= (int) $inq['id'] ?>" class="btn btn-outline btn-sm" data-confirm="Delete this inquiry?">Delete</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($tab === 'security'): ?>
        <form method="post" class="settings-card">
            <input type="hidden" name="tab" value="security">
            <div class="settings-card-head">
                <div class="settings-title-block">
                    <span class="settings-title-ico green">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </span>
                    <div>
                        <h2>Security</h2>
                        <p>Change admin login password.</p>
                    </div>
                </div>
            </div>
            <div class="settings-section">
                <div class="settings-section-head">
                    <span class="ssh-ico green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
                    <h3>Admin Password</h3>
                </div>
                <div class="settings-fields two">
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <div class="password-field">
                            <input type="password" id="new_password" name="new_password" placeholder="Leave blank to keep current" autocomplete="new-password">
                            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="password-field">
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat new password" autocomplete="new-password">
                            <button type="button" class="password-toggle" data-password-toggle aria-label="Show password" title="Show password">
                                <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="settings-card-foot">
                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                    Update password
                </button>
            </div>
        </form>

        <?php else: ?>
        <div class="settings-card">
            <div class="settings-card-head">
                <div class="settings-title-block">
                    <span class="settings-title-ico orange">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </span>
                    <div>
                        <h2>Activity log</h2>
                        <p>Recent admin actions across the panel.</p>
                    </div>
                </div>
            </div>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Admin</th><th>Action</th><th>Details</th><th>IP</th><th>Time</th></tr></thead>
                    <tbody>
                    <?php if (!$logs): ?>
                        <tr><td colspan="5">No activity yet.</td></tr>
                    <?php else: foreach ($logs as $l): ?>
                        <tr>
                            <td><?= e($l['username'] ?? 'System') ?></td>
                            <td><span class="pkg-chip"><?= e($l['action']) ?></span></td>
                            <td><?= e($l['details'] ?? '') ?></td>
                            <td><span class="muted"><?= e($l['ip_address'] ?? '') ?></span></td>
                            <td><span class="muted"><?= date('d M Y H:i', strtotime($l['created_at'])) ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php if ($tab === 'general'): ?>
<script>
(function () {
    var input = document.getElementById('companySignatureFile');
    var preview = document.getElementById('companySignaturePreview');
    var nameEl = document.getElementById('companySignatureName');
    if (!input || !preview) return;
    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) return;
        if (nameEl) nameEl.textContent = file.name;
        var url = URL.createObjectURL(file);
        preview.classList.add('has-file');
        preview.innerHTML = '<img src="' + url + '" alt="Signature preview">';
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
