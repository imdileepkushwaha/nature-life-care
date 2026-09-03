<?php
require_once __DIR__ . '/../config/database.php';
$pageTitle = 'Commission Rates';

$saveSetting = static function (PDO $pdo, string $key, string $val): void {
    feature_save($pdo, $key, $val);
};

$sub = $_GET['sub'] ?? 'binary';
if (!in_array($sub, ['binary', 'level'], true)) {
    $sub = 'binary';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postSub = $_POST['sub'] ?? 'binary';
    $mode = plan_mode();
    $before = feature_audit_snapshot($pdo);

    if ($postSub === 'binary') {
        foreach (['binary_commission_percent', 'referral_commission_percent', 'matching_commission_percent', 'binary_flush_pairs', 'binary_pair_bv', 'daily_closing_admin_charge'] as $key) {
            if (isset($_POST[$key])) {
                $saveSetting($pdo, $key, trim((string) $_POST[$key]));
            }
        }
        $wantBinary = isset($_POST['binary_income_enabled']);
        // Align with plan_mode: never enable binary income on level/unilevel/matrix
        if (in_array($mode, ['level', 'unilevel', 'matrix'], true)) {
            $wantBinary = false;
        } elseif ($mode === 'binary') {
            $wantBinary = true;
        }
        $saveSetting($pdo, 'binary_income_enabled', $wantBinary ? '1' : '0');
        $saveSetting($pdo, 'feature_binary_income', $wantBinary ? '1' : '0');
        if (!$wantBinary && in_array($mode, ['level', 'unilevel', 'matrix'], true)) {
            $saveSetting($pdo, 'feature_matching_income', '0');
        }
    } else {
        $levelCount = max(1, min(20, (int) ($_POST['level_income_levels'] ?? 10)));
        $saveSetting($pdo, 'level_income_levels', (string) $levelCount);
        $wantLevel = isset($_POST['level_income_enabled']);
        // Level/unilevel/matrix require level income; binary-only may leave it off
        if (in_array($mode, ['level', 'unilevel', 'matrix'], true)) {
            $wantLevel = true;
        }
        $saveSetting($pdo, 'level_income_enabled', $wantLevel ? '1' : '0');
        $saveSetting($pdo, 'feature_level_income', $wantLevel ? '1' : '0');
        for ($i = 1; $i <= $levelCount; $i++) {
            $key = 'level_' . $i . '_percent';
            $val = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '0';
            if ($val === '' || !is_numeric($val)) {
                $val = '0';
            }
            $saveSetting($pdo, $key, $val);
        }
    }
    clear_setting_cache();
    $auditKeys = null;
    if ($postSub === 'binary') {
        $auditKeys = [
            'binary_commission_percent', 'referral_commission_percent', 'matching_commission_percent',
            'binary_flush_pairs', 'binary_pair_bv', 'daily_closing_admin_charge',
            'binary_income_enabled', 'feature_binary_income', 'feature_matching_income',
        ];
    } else {
        $levelCount = max(1, min(20, (int) ($_POST['level_income_levels'] ?? 10)));
        $auditKeys = ['level_income_levels', 'level_income_enabled', 'feature_level_income'];
        for ($i = 1; $i <= $levelCount; $i++) {
            $auditKeys[] = 'level_' . $i . '_percent';
        }
    }
    feature_audit_log($pdo, 'commission_save', 'Updated ' . $postSub . ' rates', $before, $auditKeys);
    flash('success', 'Commission rates saved.');
    header('Location: commission.php?sub=' . urlencode($postSub));
    exit;
}

require __DIR__ . '/includes/header.php';

$settings = [];
try {
    foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Throwable $e) {
}
$levelCount = max(1, min(20, (int) ($settings['level_income_levels'] ?? 10)));
?>
<section class="sa-hero">
    <div>
        <span class="sa-hero-kicker">Payout engine</span>
        <h1>Commission Rates</h1>
        <p>Only Super Admin can edit these. Client Admin sees locked plan notice in their settings.</p>
    </div>
</section>

<nav class="sa-tabs" aria-label="Commission sections">
    <a href="commission.php?sub=binary" class="sa-tab <?= $sub === 'binary' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="3"/><circle cx="6" cy="19" r="3"/><circle cx="18" cy="19" r="3"/></svg>
        Binary / Referral
    </a>
    <a href="commission.php?sub=level" class="sa-tab <?= $sub === 'level' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/></svg>
        Level income
    </a>
</nav>

<?php if ($sub === 'binary'): ?>
<form method="post" class="sa-panel">
    <input type="hidden" name="sub" value="binary">
    <div class="sa-panel-head">
        <div>
            <h2>Binary, referral &amp; matching</h2>
            <p>Pair matching, direct bonus and sponsor matching %</p>
        </div>
    </div>
    <div class="sa-panel-body">
        <label class="sa-toggle-row">
            <input type="checkbox" name="binary_income_enabled" value="1" <?= ($settings['binary_income_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
            Binary income enabled
        </label>
        <div class="sa-form-grid">
            <div class="form-group">
                <label>Binary commission %</label>
                <input type="number" step="0.01" min="0" name="binary_commission_percent" value="<?= e($settings['binary_commission_percent'] ?? '10') ?>">
            </div>
            <div class="form-group">
                <label>Referral commission %</label>
                <input type="number" step="0.01" min="0" name="referral_commission_percent" value="<?= e($settings['referral_commission_percent'] ?? '5') ?>">
            </div>
            <div class="form-group">
                <label>Matching commission %</label>
                <input type="number" step="0.01" min="0" name="matching_commission_percent" value="<?= e($settings['matching_commission_percent'] ?? '0') ?>">
            </div>
            <div class="form-group">
                <label>Pair BV</label>
                <input type="number" step="0.01" min="0" name="binary_pair_bv" value="<?= e($settings['binary_pair_bv'] ?? '1000') ?>">
            </div>
            <div class="form-group">
                <label>Flush pairs (0 = no)</label>
                <input type="number" step="1" min="0" name="binary_flush_pairs" value="<?= e($settings['binary_flush_pairs'] ?? '0') ?>">
            </div>
            <div class="form-group">
                <label>Daily closing admin charge %</label>
                <input type="number" step="0.01" min="0" name="daily_closing_admin_charge" value="<?= e($settings['daily_closing_admin_charge'] ?? '0') ?>">
            </div>
        </div>
        <div class="sa-form-actions">
            <button type="submit" class="btn btn-primary">Save binary rates</button>
        </div>
    </div>
</form>
<?php else: ?>
<form method="post" class="sa-panel">
    <input type="hidden" name="sub" value="level">
    <div class="sa-panel-head">
        <div>
            <h2>Level income ladder</h2>
            <p>Percent of package amount paid up the sponsor chain</p>
        </div>
    </div>
    <div class="sa-panel-body">
        <label class="sa-toggle-row">
            <input type="checkbox" name="level_income_enabled" value="1" <?= ($settings['level_income_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
            Level income enabled
        </label>
        <div class="sa-form-grid" style="margin-bottom:1rem">
            <div class="form-group">
                <label>Number of levels (1–20)</label>
                <input type="number" min="1" max="20" name="level_income_levels" value="<?= (int) $levelCount ?>">
                <span class="sa-field-hint">Save after changing count to load more/fewer level fields</span>
            </div>
        </div>
        <div class="sa-form-grid">
            <?php for ($i = 1; $i <= $levelCount; $i++): ?>
            <div class="form-group">
                <label>Level <?= $i ?> %</label>
                <input type="number" step="0.01" min="0" name="level_<?= $i ?>_percent" value="<?= e($settings['level_' . $i . '_percent'] ?? '0') ?>">
            </div>
            <?php endfor; ?>
        </div>
        <div class="sa-form-actions">
            <button type="submit" class="btn btn-primary">Save level rates</button>
        </div>
    </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
