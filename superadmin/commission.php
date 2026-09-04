<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/plan_incentives.php';
require_superadmin();
$pageTitle = 'Commission Rates';

plan_incentives_ensure($pdo);

$saveSetting = static function (PDO $pdo, string $key, string $val): void {
    feature_save($pdo, $key, $val);
};

$sub = $_GET['sub'] ?? 'binary';
if (!in_array($sub, ['binary', 'level', 'dsi', 'ranks'], true)) {
    $sub = 'binary';
}

$frozen = commission_rates_frozen();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postSub = $_POST['sub'] ?? 'binary';
    $action = (string) ($_POST['action'] ?? 'save');
    $before = feature_audit_snapshot($pdo);

    if ($action === 'freeze') {
        $note = trim((string) ($_POST['freeze_note'] ?? ''));
        plan_freeze_set($pdo, true, $note);
        feature_audit_log($pdo, 'commission_freeze', 'Froze payout rates' . ($note !== '' ? ': ' . $note : ''), $before, ['commission_rates_frozen']);
        flash('success', 'Payout rates are frozen. Super Admin can still unlock them if the written plan changes.');
        header('Location: commission.php?sub=' . urlencode($postSub));
        exit;
    }

    if ($action === 'unfreeze') {
        plan_freeze_set($pdo, false);
        feature_audit_log($pdo, 'commission_unfreeze', 'Unlocked payout rates', $before, ['commission_rates_frozen']);
        flash('success', 'Payout rates unlocked. You can edit them again.');
        header('Location: commission.php?sub=' . urlencode($postSub));
        exit;
    }

    if ($frozen) {
        flash('error', 'Payout rates are frozen after the written plan / compliance review. Unlock them first to edit.');
        header('Location: commission.php?sub=' . urlencode($postSub));
        exit;
    }

    $mode = plan_mode();

    if ($postSub === 'binary') {
        foreach (['binary_commission_percent', 'referral_commission_percent', 'matching_commission_percent', 'binary_flush_pairs', 'binary_pair_bv', 'binary_match_ratio', 'daily_closing_admin_charge'] as $key) {
            if (isset($_POST[$key])) {
                $val = trim((string) $_POST[$key]);
                if ($key === 'binary_match_ratio') {
                    if ($val === '1:1') {
                        $val = '1:1';
                    } elseif ($val === 'consume') {
                        $val = 'consume';
                    } else {
                        $val = '1:2';
                    }
                }
                $saveSetting($pdo, $key, $val);
            }
        }
        $wantBinary = isset($_POST['binary_income_enabled']);
        if (in_array($mode, ['level', 'unilevel', 'matrix'], true)) {
            $wantBinary = false;
        } elseif ($mode === 'binary') {
            $wantBinary = true;
        }
        $saveSetting($pdo, 'binary_income_enabled', $wantBinary ? '1' : '0');
        $saveSetting($pdo, 'feature_binary_income', $wantBinary ? '1' : '0');
        $wantMatching = $wantBinary && isset($_POST['feature_matching_income']);
        if (!$wantBinary) {
            $wantMatching = false;
            $saveSetting($pdo, 'feature_dsi_income', '0');
            $saveSetting($pdo, 'feature_ranks_enabled', '0');
            $saveSetting($pdo, 'feature_rewards_enabled', '0');
        }
        $saveSetting($pdo, 'feature_matching_income', $wantMatching ? '1' : '0');
        $auditKeys = [
            'binary_commission_percent', 'referral_commission_percent', 'matching_commission_percent',
            'binary_flush_pairs', 'binary_pair_bv', 'binary_match_ratio', 'daily_closing_admin_charge',
            'binary_income_enabled', 'feature_binary_income', 'feature_matching_income', 'feature_dsi_income',
        ];
    } elseif ($postSub === 'level') {
        $levelCount = max(1, min(20, (int) ($_POST['level_income_levels'] ?? 10)));
        $saveSetting($pdo, 'level_income_levels', (string) $levelCount);
        $wantLevel = isset($_POST['level_income_enabled']);
        if (in_array($mode, ['level', 'unilevel', 'matrix'], true)) {
            $wantLevel = true;
        } elseif ($mode === 'binary') {
            $wantLevel = false;
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
        $auditKeys = ['level_income_levels', 'level_income_enabled', 'feature_level_income'];
        for ($i = 1; $i <= $levelCount; $i++) {
            $auditKeys[] = 'level_' . $i . '_percent';
        }
    } elseif ($postSub === 'dsi') {
        $wantDsi = isset($_POST['feature_dsi_income']);
        if (!plan_uses_binary() || !feature_enabled('feature_binary_income')) {
            $wantDsi = false;
        }
        $splitTotal = 0.0;
        foreach (['dsi_level_1_percent', 'dsi_level_2_percent', 'dsi_level_3_percent', 'dsi_level_4_percent', 'dsi_level_5_percent'] as $key) {
            $val = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '0';
            if ($val === '' || !is_numeric($val)) {
                $val = '0';
            }
            $splitTotal += max(0.0, (float) $val);
        }
        if ($splitTotal > 100.0001) {
            flash('error', 'DSI level split cannot exceed 100% of the pool (currently ' . round($splitTotal, 2) . '%).');
            header('Location: commission.php?sub=dsi');
            exit;
        }
        $saveSetting($pdo, 'feature_dsi_income', $wantDsi ? '1' : '0');
        foreach (['dsi_pool_percent', 'dsi_level_1_percent', 'dsi_level_2_percent', 'dsi_level_3_percent', 'dsi_level_4_percent', 'dsi_level_5_percent'] as $key) {
            $val = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '0';
            if ($val === '' || !is_numeric($val)) {
                $val = '0';
            }
            $saveSetting($pdo, $key, $val);
        }
        $auditKeys = ['feature_dsi_income', 'dsi_pool_percent', 'dsi_level_1_percent', 'dsi_level_2_percent', 'dsi_level_3_percent', 'dsi_level_4_percent', 'dsi_level_5_percent'];
    } else {
        $saveSetting($pdo, 'feature_ranks_enabled', isset($_POST['feature_ranks_enabled']) ? '1' : '0');
        $saveSetting($pdo, 'feature_rewards_enabled', isset($_POST['feature_rewards_enabled']) ? '1' : '0');
        $updRank = $pdo->prepare('UPDATE plan_ranks SET pairs_required = ?, bonus_amount = ? WHERE rank_key = ?');
        foreach (plan_ranks_list($pdo, false) as $r) {
            $key = (string) $r['rank_key'];
            $pairs = max(0, (int) ($_POST['rank_pairs'][$key] ?? $r['pairs_required']));
            $bonus = max(0, round((float) ($_POST['rank_bonus'][$key] ?? $r['bonus_amount']), 2));
            $updRank->execute([$pairs, $bonus, $key]);
        }
        $updRew = $pdo->prepare('UPDATE plan_rewards SET pairs_required = ?, gift_label = ?, cash_value = ? WHERE reward_key = ?');
        foreach (plan_rewards_list($pdo, false) as $g) {
            $key = (string) $g['reward_key'];
            $pairs = max(0, (int) ($_POST['reward_pairs'][$key] ?? $g['pairs_required']));
            $label = trim((string) ($_POST['reward_gift'][$key] ?? $g['gift_label']));
            if ($label === '') {
                $label = (string) $g['gift_label'];
            }
            $cash = max(0, round((float) ($_POST['reward_cash'][$key] ?? $g['cash_value']), 2));
            $updRew->execute([$pairs, $label, $cash, $key]);
        }
        $auditKeys = ['feature_ranks_enabled', 'feature_rewards_enabled'];
    }

    clear_setting_cache();
    feature_audit_log($pdo, 'commission_save', 'Updated ' . $postSub . ' rates', $before, $auditKeys ?? null);
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
$lockAttr = $frozen ? ' disabled' : '';
$frozenAt = trim((string) ($settings['commission_rates_frozen_at'] ?? ''));
$frozenNote = trim((string) ($settings['commission_rates_frozen_note'] ?? ''));
$ranks = plan_ranks_list($pdo, false);
$rewards = plan_rewards_list($pdo, false);
$dsiSplit = (float) ($settings['dsi_level_1_percent'] ?? 50)
    + (float) ($settings['dsi_level_2_percent'] ?? 20)
    + (float) ($settings['dsi_level_3_percent'] ?? 15)
    + (float) ($settings['dsi_level_4_percent'] ?? 10)
    + (float) ($settings['dsi_level_5_percent'] ?? 5);
?>
<section class="sa-hero">
    <div>
        <span class="sa-hero-kicker">Payout engine</span>
        <h1>Commission Rates</h1>
        <p>Only Super Admin can edit these. After the written plan and compliance review, freeze the rates so they cannot change by accident.</p>
    </div>
</section>

<?php if ($frozen): ?>
<div class="sa-panel" style="border-color:#f59e0b;margin-bottom:1rem">
    <div class="sa-panel-head">
        <div>
            <h2>Rates frozen</h2>
            <p>
                Payout percentages, DSI split, rank pair thresholds and reward cash values are locked.
                <?php if ($frozenAt !== ''): ?>Since <?= e($frozenAt) ?>.<?php endif; ?>
            </p>
            <?php if ($frozenNote !== ''): ?><p><?= e($frozenNote) ?></p><?php endif; ?>
        </div>
    </div>
    <div class="sa-panel-body">
        <form method="post">
            <input type="hidden" name="sub" value="<?= e($sub) ?>">
            <input type="hidden" name="action" value="unfreeze">
            <button type="submit" class="btn btn-outline" data-confirm="Unlock payout rates for editing?">Unlock rates</button>
        </form>
    </div>
</div>
<?php else: ?>
<div class="sa-panel" style="margin-bottom:1rem">
    <div class="sa-panel-head">
        <div>
            <h2>Freeze after written plan</h2>
            <p>Lock binary, referral, matching, level, DSI, rank and reward figures once product economics and compliance are signed off.</p>
        </div>
    </div>
    <div class="sa-panel-body">
        <form method="post">
            <input type="hidden" name="sub" value="<?= e($sub) ?>">
            <input type="hidden" name="action" value="freeze">
            <div class="form-group" style="max-width:32rem;margin-bottom:0.85rem">
                <label>Note (optional)</label>
                <input type="text" name="freeze_note" placeholder="e.g. Written plan v1 approved">
            </div>
            <button type="submit" class="btn btn-primary" data-confirm="Freeze all payout rates? You can unlock later if the plan changes.">Freeze payout rates</button>
        </form>
    </div>
</div>
<?php endif; ?>

<nav class="sa-tabs" aria-label="Commission sections">
    <a href="commission.php?sub=binary" class="sa-tab <?= $sub === 'binary' ? 'active' : '' ?>">Binary / Referral</a>
    <a href="commission.php?sub=level" class="sa-tab <?= $sub === 'level' ? 'active' : '' ?>">Level income</a>
    <a href="commission.php?sub=dsi" class="sa-tab <?= $sub === 'dsi' ? 'active' : '' ?>">DSI</a>
    <a href="commission.php?sub=ranks" class="sa-tab <?= $sub === 'ranks' ? 'active' : '' ?>">Ranks &amp; rewards</a>
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
            <input type="checkbox" name="binary_income_enabled" value="1" <?= ($settings['binary_income_enabled'] ?? '1') === '1' ? 'checked' : '' ?><?= $lockAttr ?>>
            Binary income enabled
        </label>
        <label class="sa-toggle-row">
            <input type="checkbox" name="feature_matching_income" value="1" <?= ($settings['feature_matching_income'] ?? '1') === '1' ? 'checked' : '' ?><?= $lockAttr ?>>
            Matching bonus enabled (sponsor % on downline binary gross)
        </label>
        <div class="sa-form-grid">
            <div class="form-group">
                <label>Binary commission %</label>
                <input type="number" step="0.01" min="0" name="binary_commission_percent" value="<?= e($settings['binary_commission_percent'] ?? '15') ?>"<?= $lockAttr ?>>
            </div>
            <div class="form-group">
                <label>Referral commission %</label>
                <input type="number" step="0.01" min="0" name="referral_commission_percent" value="<?= e($settings['referral_commission_percent'] ?? '5') ?>"<?= $lockAttr ?>>
            </div>
            <div class="form-group">
                <label>Matching commission %</label>
                <input type="number" step="0.01" min="0" name="matching_commission_percent" value="<?= e($settings['matching_commission_percent'] ?? '0') ?>"<?= $lockAttr ?>>
                <span class="sa-field-hint">0 = no matching bonus even if toggle is on</span>
            </div>
            <div class="form-group">
                <label>Pair PV (unit)</label>
                <input type="number" step="0.01" min="1" name="binary_pair_bv" value="<?= e($settings['binary_pair_bv'] ?? '1000') ?>"<?= $lockAttr ?>>
                <span class="sa-field-hint">Used for flush cap, rank/reward pair units, and consume mode</span>
            </div>
            <div class="form-group">
                <label>Match ratio</label>
                <select name="binary_match_ratio"<?= $lockAttr ?>>
                    <?php $ratio = (string) ($settings['binary_match_ratio'] ?? '1:2'); ?>
                    <option value="1:2" <?= !in_array($ratio, ['1:1', 'consume'], true) ? 'selected' : '' ?>>1:2 / 2:1 — weaker PV when unequal; equal legs → no pay</option>
                    <option value="1:1" <?= $ratio === '1:1' ? 'selected' : '' ?>>1:1 — match min(L,R) including equal legs</option>
                    <option value="consume" <?= $ratio === 'consume' ? 'selected' : '' ?>>Strict consume (Pair-PV units: 1 weak + 2 strong)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Flush pairs (0 = no)</label>
                <input type="number" step="1" min="0" name="binary_flush_pairs" value="<?= e($settings['binary_flush_pairs'] ?? '0') ?>"<?= $lockAttr ?>>
            </div>
            <div class="form-group">
                <label>Daily closing admin charge %</label>
                <input type="number" step="0.01" min="0" name="daily_closing_admin_charge" value="<?= e($settings['daily_closing_admin_charge'] ?? '0') ?>"<?= $lockAttr ?>>
                <span class="sa-field-hint">Deducted from binary net; matching bonus uses binary gross before this charge</span>
            </div>
        </div>
        <?php if (!$frozen): ?>
        <div class="sa-form-actions">
            <button type="submit" class="btn btn-primary">Save binary rates</button>
        </div>
        <?php endif; ?>
    </div>
</form>
<?php elseif ($sub === 'level'): ?>
<form method="post" class="sa-panel">
    <input type="hidden" name="sub" value="level">
    <div class="sa-panel-head">
        <div>
            <h2>Level income ladder</h2>
            <p>Percent of package amount paid up the sponsor chain<?= plan_mode() === 'binary' ? ' — not paid in pure binary mode (switch to Hybrid to combine)' : '' ?></p>
        </div>
    </div>
    <div class="sa-panel-body">
        <?php if (plan_mode() === 'binary'): ?>
        <div class="alert alert-warning" style="margin-bottom:1rem">Plan mode is Binary only. Level income is forced off. Use Hybrid mode to enable both binary and level.</div>
        <?php endif; ?>
        <label class="sa-toggle-row">
            <input type="checkbox" name="level_income_enabled" value="1" <?= ($settings['level_income_enabled'] ?? '1') === '1' ? 'checked' : '' ?><?= $lockAttr ?><?= plan_mode() === 'binary' ? ' disabled' : '' ?>>
            Level income enabled
        </label>
        <?php if (plan_mode() === 'binary'): ?>
        <input type="hidden" name="level_income_enabled" value="0">
        <?php endif; ?>
        <div class="sa-form-grid" style="margin-bottom:1rem">
            <div class="form-group">
                <label>Number of levels (1–20)</label>
                <input type="number" min="1" max="20" name="level_income_levels" value="<?= (int) $levelCount ?>"<?= $lockAttr ?>>
                <span class="sa-field-hint">Save after changing count to load more/fewer level fields</span>
            </div>
        </div>
        <div class="sa-form-grid">
            <?php for ($i = 1; $i <= $levelCount; $i++): ?>
            <div class="form-group">
                <label>Level <?= $i ?> %</label>
                <input type="number" step="0.01" min="0" name="level_<?= $i ?>_percent" value="<?= e($settings['level_' . $i . '_percent'] ?? '0') ?>"<?= $lockAttr ?>>
            </div>
            <?php endfor; ?>
        </div>
        <?php if (!$frozen): ?>
        <div class="sa-form-actions">
            <button type="submit" class="btn btn-primary">Save level rates</button>
        </div>
        <?php endif; ?>
    </div>
</form>
<?php elseif ($sub === 'dsi'): ?>
<form method="post" class="sa-panel">
    <input type="hidden" name="sub" value="dsi">
    <div class="sa-panel-head">
        <div>
            <h2>Direct Sponsor Incentive</h2>
            <p>On kit activation and paid product orders, a pool is queued up 5 sponsor levels and settled on <strong>binary closing</strong>. Requires binary income. Recipients must be active with a package.</p>
        </div>
    </div>
    <div class="sa-panel-body">
        <?php if (!plan_uses_binary() || ($settings['feature_binary_income'] ?? '1') !== '1'): ?>
        <div class="alert alert-warning" style="margin-bottom:1rem">DSI cannot settle without binary income / closing. Enable binary plan income first, or DSI will stay off.</div>
        <?php endif; ?>
        <label class="sa-toggle-row">
            <input type="checkbox" name="feature_dsi_income" value="1" <?= ($settings['feature_dsi_income'] ?? '1') === '1' ? 'checked' : '' ?><?= $lockAttr ?><?= (!plan_uses_binary() || ($settings['feature_binary_income'] ?? '1') !== '1') ? ' disabled' : '' ?>>
            DSI enabled
        </label>
        <?php if (!plan_uses_binary() || ($settings['feature_binary_income'] ?? '1') !== '1'): ?>
        <input type="hidden" name="feature_dsi_income" value="0">
        <?php endif; ?>
        <div class="sa-form-grid">
            <div class="form-group">
                <label>Distributable pool % of activity amount</label>
                <input type="number" step="0.01" min="0" name="dsi_pool_percent" value="<?= e($settings['dsi_pool_percent'] ?? '10') ?>"<?= $lockAttr ?>>
            </div>
            <div class="form-group">
                <label>Level 1 — First Direct % of pool</label>
                <input type="number" step="0.01" min="0" name="dsi_level_1_percent" value="<?= e($settings['dsi_level_1_percent'] ?? '50') ?>"<?= $lockAttr ?>>
            </div>
            <div class="form-group">
                <label>Level 2 — Indirect % of pool</label>
                <input type="number" step="0.01" min="0" name="dsi_level_2_percent" value="<?= e($settings['dsi_level_2_percent'] ?? '20') ?>"<?= $lockAttr ?>>
            </div>
            <div class="form-group">
                <label>Level 3 % of pool</label>
                <input type="number" step="0.01" min="0" name="dsi_level_3_percent" value="<?= e($settings['dsi_level_3_percent'] ?? '15') ?>"<?= $lockAttr ?>>
            </div>
            <div class="form-group">
                <label>Level 4 % of pool</label>
                <input type="number" step="0.01" min="0" name="dsi_level_4_percent" value="<?= e($settings['dsi_level_4_percent'] ?? '10') ?>"<?= $lockAttr ?>>
            </div>
            <div class="form-group">
                <label>Level 5 % of pool</label>
                <input type="number" step="0.01" min="0" name="dsi_level_5_percent" value="<?= e($settings['dsi_level_5_percent'] ?? '5') ?>"<?= $lockAttr ?>>
            </div>
        </div>
        <p class="sa-field-hint">Level split currently totals <?= e(rtrim(rtrim(number_format($dsiSplit, 2, '.', ''), '0'), '.')) ?>% of the pool<?= $dsiSplit > 100 ? ' — must be ≤ 100%' : '' ?>. Under 100% is retained by the company.</p>
        <?php if (!$frozen): ?>
        <div class="sa-form-actions">
            <button type="submit" class="btn btn-primary">Save DSI rates</button>
        </div>
        <?php endif; ?>
    </div>
</form>
<?php else: ?>
<form method="post" class="sa-panel">
    <input type="hidden" name="sub" value="ranks">
    <div class="sa-panel-head">
        <div>
            <h2>Ranks &amp; pair rewards</h2>
            <p>Ranks promote automatically after binary closing when lifetime pairs hit the threshold. Matching rewards become eligible for Client Admin fulfillment (tax, stock and written terms still apply).</p>
        </div>
    </div>
    <div class="sa-panel-body">
        <label class="sa-toggle-row">
            <input type="checkbox" name="feature_ranks_enabled" value="1" <?= ($settings['feature_ranks_enabled'] ?? '1') === '1' ? 'checked' : '' ?><?= $lockAttr ?>>
            Rank auto-promotion enabled
        </label>
        <label class="sa-toggle-row">
            <input type="checkbox" name="feature_rewards_enabled" value="1" <?= ($settings['feature_rewards_enabled'] ?? '1') === '1' ? 'checked' : '' ?><?= $lockAttr ?>>
            Business rewards enabled
        </label>

        <h3 class="sa-section-title">Rank ladder</h3>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Rank</th><th>Pairs required</th><th>Optional cash bonus</th></tr>
                </thead>
                <tbody>
                <?php foreach ($ranks as $r): ?>
                    <tr>
                        <td><strong><?= e((string) $r['title']) ?></strong></td>
                        <td><input type="number" min="0" name="rank_pairs[<?= e((string) $r['rank_key']) ?>]" value="<?= (int) $r['pairs_required'] ?>"<?= $lockAttr ?>></td>
                        <td><input type="number" step="0.01" min="0" name="rank_bonus[<?= e((string) $r['rank_key']) ?>]" value="<?= e(number_format((float) $r['bonus_amount'], 2, '.', '')) ?>"<?= $lockAttr ?>></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h3 class="sa-section-title">Reward milestones</h3>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Milestone</th><th>Pairs</th><th>Gift / benefit</th><th>Cash value (if credited)</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rewards as $g): ?>
                    <tr>
                        <td><strong><?= e((string) $g['title']) ?></strong></td>
                        <td><input type="number" min="0" name="reward_pairs[<?= e((string) $g['reward_key']) ?>]" value="<?= (int) $g['pairs_required'] ?>"<?= $lockAttr ?>></td>
                        <td><input type="text" name="reward_gift[<?= e((string) $g['reward_key']) ?>]" value="<?= e((string) $g['gift_label']) ?>"<?= $lockAttr ?>></td>
                        <td><input type="number" step="0.01" min="0" name="reward_cash[<?= e((string) $g['reward_key']) ?>]" value="<?= e(number_format((float) $g['cash_value'], 2, '.', '')) ?>"<?= $lockAttr ?>></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$frozen): ?>
        <div class="sa-form-actions">
            <button type="submit" class="btn btn-primary">Save ranks &amp; rewards</button>
        </div>
        <?php endif; ?>
    </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
