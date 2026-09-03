<?php
require_once __DIR__ . '/../config/database.php';
$pageTitle = 'Plan & Features';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $before = feature_audit_snapshot($pdo);

    if ($action === 'preset') {
        $preset = trim((string) ($_POST['preset'] ?? ''));
        if (feature_apply_preset($pdo, $preset)) {
            feature_audit_log($pdo, 'feature_preset', 'Applied preset: ' . $preset, $before);
            flash('success', 'Preset applied. Client Admin & User menus will update immediately.');
        } else {
            flash('error', 'Unknown preset.');
        }
    } else {
        feature_save_from_post($pdo, $_POST);
        feature_audit_log($pdo, 'feature_save', 'Updated plan mode / feature flags', $before);
        flash('success', 'Features saved. Disabled modules are hidden from Client Admin and User panels.');
    }

    header('Location: features.php');
    exit;
}

require __DIR__ . '/includes/header.php';

$presets = feature_presets();
clear_setting_cache();
$currentPreset = feature_active_preset_key();
$presetModified = feature_preset_is_modified($currentPreset);
$mode = plan_mode();
$productOnlyOn = feature_product_only_activation() || $currentPreset === 'product_only';
$activateValue = product_activate_min_amount();
$currencySym = function_exists('currency_symbol') ? currency_symbol() : '₹';

$secIco = static function (string $d): string {
    return '<span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">' . $d . '</svg></span>';
};

$switch = static function (string $name, string $label, string $hint, bool $on, array $attrs = []): string {
    $id = 'sw_' . preg_replace('/[^a-z0-9_]/i', '', $name);
    $checked = $on ? ' checked' : '';
    $state = $on ? 'is-on' : '';
    $extra = '';
    foreach ($attrs as $ak => $av) {
        $extra .= ' ' . htmlspecialchars((string) $ak, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string) $av, ENT_QUOTES, 'UTF-8') . '"';
    }
    return '<label class="sa-switch ' . $state . '" for="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' . $extra . '>'
        . '<span class="sa-switch-copy">'
        . '<strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong>'
        . '<small>' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</small>'
        . '</span>'
        . '<span class="sa-switch-control">'
        . '<input type="checkbox" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" value="1"' . $checked . '>'
        . '<span class="sa-switch-track" aria-hidden="true"><span class="sa-switch-thumb"></span></span>'
        . '<span class="sa-switch-state">' . ($on ? 'ON' : 'OFF') . '</span>'
        . '</span>'
        . '</label>';
};
?>
<section class="sa-hero">
    <div>
        <span class="sa-hero-kicker">Client package</span>
        <h1>Plan &amp; Features</h1>
        <p>Apply a ready preset in one click, or fine-tune activation rails and income modules for this install.</p>
    </div>
</section>

<div class="sa-panel">
    <div class="sa-panel-head">
        <div>
            <h2>Quick presets</h2>
            <p>One-click templates for common client types</p>
        </div>
    </div>
    <div class="sa-panel-body">
        <?php
        $presetMeta = [
            'hybrid_full' => [
                'tone' => 'rose',
                'ico' => '<circle cx="12" cy="5" r="3"/><circle cx="6" cy="19" r="3"/><circle cx="18" cy="19" r="3"/><path d="M12 8v4M9 17l3-5 3 5"/>',
                'tags' => ['Hybrid', 'Packages', 'T-PIN', 'Shop', 'Full'],
            ],
            'binary_package_tpin' => [
                'tone' => 'slate',
                'ico' => '<path d="M12 3v18M5 8l7-5 7 5M5 16l7 5 7-5"/>',
                'tags' => ['Binary', 'Package', 'T-PIN'],
            ],
            'level_only' => [
                'tone' => 'amber',
                'ico' => '<path d="M4 20h16M7 16V8m5 8V4m5 12v-6"/>',
                'tags' => ['Level', 'No binary'],
            ],
            'epin_company' => [
                'tone' => 'violet',
                'ico' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 9h4M7 13h6M15 13h2"/>',
                'tags' => ['E-Pin', 'Hybrid', 'No shop'],
            ],
            'product_binary' => [
                'tone' => 'teal',
                'ico' => '<path d="M6 6h15l-1.5 9H8L6 6z"/><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M6 6L5 3H2"/>',
                'tags' => ['Shop', 'Binary', 'Activate'],
            ],
            'unilevel_only' => [
                'tone' => 'sky',
                'ico' => '<circle cx="12" cy="5" r="2.5"/><circle cx="6" cy="14" r="2.5"/><circle cx="18" cy="14" r="2.5"/><circle cx="12" cy="20" r="2.5"/><path d="M12 7.5v4M8 14l4 4 4-4"/>',
                'tags' => ['Unilevel', 'Sponsor chain'],
            ],
            'matrix_3x' => [
                'tone' => 'indigo',
                'ico' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
                'tags' => ['Matrix', '3× wide', 'Level'],
            ],
            'product_only' => [
                'tone' => 'emerald',
                'ico' => '<path d="M4 7h16l-1.2 11.2A2 2 0 0116.81 20H7.19a2 2 0 01-1.99-1.8L4 7z"/><path d="M9 7V5a3 3 0 016 0v2"/>',
                'tags' => ['Shop only', 'No plans', 'Value activate'],
            ],
        ];
        $defaultMeta = [
            'tone' => 'rose',
            'ico' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 2.5"/>',
            'tags' => ['Preset'],
        ];
        ?>
        <div class="sa-preset-grid">
            <?php foreach ($presets as $key => $p):
                $meta = $presetMeta[$key] ?? $defaultMeta;
                $isActive = $currentPreset === $key;
            ?>
            <form method="post" class="sa-preset-card tone-<?= e($meta['tone']) ?><?= $isActive ? ' is-active' : '' ?><?= $isActive && $presetModified ? ' is-modified' : '' ?>">
                <input type="hidden" name="action" value="preset">
                <input type="hidden" name="preset" value="<?= e($key) ?>">
                <div class="sa-preset-top">
                    <span class="sa-preset-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><?= $meta['ico'] ?></svg>
                    </span>
                    <?php if ($isActive && $presetModified): ?>
                    <span class="sa-preset-badge is-modified"><span class="sa-preset-badge-dot"></span> Modified</span>
                    <?php elseif ($isActive): ?>
                    <span class="sa-preset-badge"><span class="sa-preset-badge-dot"></span> Active</span>
                    <?php else: ?>
                    <span class="sa-preset-badge is-idle">Preset</span>
                    <?php endif; ?>
                </div>
                <div class="sa-preset-body">
                    <strong><?= e($p['label']) ?></strong>
                    <p><?= e($p['description']) ?></p>
                    <?php if (!empty($meta['tags'])): ?>
                    <div class="sa-preset-tags">
                        <?php foreach ($meta['tags'] as $tag): ?>
                        <span><?= e($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="sa-preset-foot">
                    <button type="submit" class="sa-preset-btn<?= $isActive ? ' is-active' : '' ?>">
                        <?php if ($isActive && $presetModified): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                            Active · customized
                        <?php elseif ($isActive): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                            Currently active
                        <?php else: ?>
                            Apply preset
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        <?php endif; ?>
                    </button>
                </div>
            </form>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<form method="post" class="sa-panel" id="saFeaturesForm">
    <input type="hidden" name="action" value="save">
    <div class="sa-panel-head">
        <div>
            <h2>Custom configuration</h2>
            <p>Toggle modules below and Save — your selected preset stays active (shows Modified if you changed switches)</p>
        </div>
    </div>
    <div class="sa-panel-body">
        <h3 class="sa-section-title"><?= $secIco('<circle cx="12" cy="5" r="3"/><circle cx="6" cy="19" r="3"/><circle cx="18" cy="19" r="3"/>') ?> Plan mode</h3>
        <div class="sa-mode-grid">
            <label class="sa-mode-opt <?= $mode === 'hybrid' ? 'is-on' : '' ?>">
                <input type="radio" name="plan_mode" value="hybrid" <?= $mode === 'hybrid' ? 'checked' : '' ?>>
                <strong>Hybrid</strong>
                <small>Binary tree + Level income together</small>
            </label>
            <label class="sa-mode-opt <?= $mode === 'binary' ? 'is-on' : '' ?>">
                <input type="radio" name="plan_mode" value="binary" <?= $mode === 'binary' ? 'checked' : '' ?>>
                <strong>Binary only</strong>
                <small>Left/Right tree &amp; pair closing focus</small>
            </label>
            <label class="sa-mode-opt <?= $mode === 'level' ? 'is-on' : '' ?>">
                <input type="radio" name="plan_mode" value="level" <?= $mode === 'level' ? 'checked' : '' ?>>
                <strong>Level only</strong>
                <small>Sponsor levels — hide binary tree / closing</small>
            </label>
            <label class="sa-mode-opt <?= $mode === 'unilevel' ? 'is-on' : '' ?>">
                <input type="radio" name="plan_mode" value="unilevel" <?= $mode === 'unilevel' ? 'checked' : '' ?>>
                <strong>Unilevel</strong>
                <small>Pure sponsor chain (same as level topology)</small>
            </label>
            <label class="sa-mode-opt <?= $mode === 'matrix' ? 'is-on' : '' ?>">
                <input type="radio" name="plan_mode" value="matrix" <?= $mode === 'matrix' ? 'checked' : '' ?>>
                <strong>Matrix</strong>
                <small>N-wide spillover tree + level income</small>
            </label>
        </div>
        <div class="sa-form-grid" style="margin-top:1rem;max-width:280px">
            <div class="form-group">
                <label>Matrix width</label>
                <input type="number" min="2" max="10" name="matrix_width" value="<?= e(setting('matrix_width', '3')) ?>">
                <span class="sa-field-hint">Used when plan mode is Matrix (2–10)</span>
            </div>
        </div>

        <h3 class="sa-section-title"><?= $secIco('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 9h4M7 13h10"/>') ?> Activation</h3>
        <div class="sa-switch-grid" id="saActivationSwitches">
            <?= $switch('feature_package_enabled', 'Packages', 'Starter / investment packages for activation (OFF in Product Only)', feature_enabled('feature_package_enabled'), ['data-sa-rail' => 'package']) ?>
            <?= $switch('feature_tpin_enabled', 'T-PIN / E-Pin', 'Pin generate, transfer & redeem (OFF in Product Only)', feature_enabled('feature_tpin_enabled'), ['data-sa-rail' => 'tpin']) ?>
            <?= $switch('feature_utr_activation_enabled', 'UTR activation', 'Manual payment slip / UTR requests (OFF in Product Only)', feature_enabled('feature_utr_activation_enabled'), ['data-sa-rail' => 'utr']) ?>
            <?= $switch('feature_wallet_topup_enabled', 'Wallet topup', 'Member topup wallet funding for shopping', feature_enabled('feature_wallet_topup_enabled')) ?>
            <?= $switch('feature_product_shop_enabled', 'Product shop', 'Member shopping wallet checkout', feature_enabled('feature_product_shop_enabled'), ['data-sa-rail' => 'shop']) ?>
            <?= $switch('feature_product_activates_package', 'Product → activation', 'Paid shop order can activate the member ID', feature_enabled('feature_product_activates_package'), ['data-sa-rail' => 'pact']) ?>
            <?= $switch('feature_product_only_activation', 'Product Only activation', 'No starter plans — activate by buying a product at/above the value below', feature_enabled('feature_product_only_activation'), ['data-sa-rail' => 'ponly']) ?>
        </div>

        <div class="sa-activate-value is-emphasis<?= $productOnlyOn ? ' is-visible' : '' ?>" id="saActivateValueCard"<?= $productOnlyOn ? '' : ' hidden' ?>>
            <div class="sa-activate-value-head">
                <div class="sa-activate-value-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </div>
                <div>
                    <h4>Activation product value</h4>
                    <p>Product Only mode: member ID activates when they buy a product priced at least this amount. No package link needed.</p>
                </div>
            </div>
            <div class="sa-activate-value-body">
                <label class="sa-activate-value-label" for="product_activate_min_amount">Minimum selling price</label>
                <div class="sa-activate-value-input-wrap">
                    <span class="sa-activate-value-cur"><?= e($currencySym === '₹' ? '₹' : $currencySym) ?></span>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="product_activate_min_amount"
                        id="product_activate_min_amount"
                        value="<?= e(number_format($activateValue, 2, '.', '')) ?>"
                        placeholder="0.00"
                        <?= $productOnlyOn ? '' : 'disabled' ?>
                    >
                </div>
                <div class="sa-activate-value-chips" role="group" aria-label="Quick amounts">
                    <?php foreach ([0, 500, 1000, 2500, 5000] as $chip): ?>
                    <button type="button" class="sa-activate-chip<?= abs($activateValue - $chip) < 0.001 ? ' is-on' : '' ?>" data-amount="<?= $chip ?>">
                        <?= $chip === 0 ? 'Any product' : e(($currencySym === '₹' ? '₹' : $currencySym) . number_format($chip, 0)) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <p class="sa-activate-value-hint" id="saActivateValueHint">
                    <?php if ($activateValue <= 0): ?>
                        <strong>0 = any product</strong> purchase can activate the ID.
                    <?php else: ?>
                        Buy product of <strong><?= e(strip_tags(currency($activateValue))) ?></strong> or more → ID activates.
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <?php if (!$productOnlyOn): ?>
        <input type="hidden" name="product_activate_min_amount" id="product_activate_min_amount_keep" value="<?= e(number_format($activateValue, 2, '.', '')) ?>">
        <?php endif; ?>

        <h3 class="sa-section-title"><?= $secIco('<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>') ?> Income modules</h3>
        <div class="sa-switch-grid">
            <?= $switch('feature_binary_income', 'Binary income', 'Pair matching / binary closing payouts', feature_enabled('feature_binary_income')) ?>
            <?= $switch('feature_level_income', 'Level income', 'Sponsor-level % on activations', feature_enabled('feature_level_income')) ?>
            <?= $switch('feature_referral_income', 'Referral income', 'Direct sponsor bonus on activation', feature_enabled('feature_referral_income')) ?>
            <?= $switch('feature_matching_income', 'Matching income', 'Matching bonus on downline earnings', feature_enabled('feature_matching_income')) ?>
        </div>

        <h3 class="sa-section-title"><?= $secIco('<circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>') ?> Operations</h3>
        <div class="sa-switch-grid">
            <?= $switch('feature_withdrawals_enabled', 'Withdrawals', 'Member withdrawal fund requests', feature_enabled('feature_withdrawals_enabled')) ?>
            <?= $switch('feature_kyc_enabled', 'KYC', 'PAN / bank / Aadhaar / UPI verification', feature_enabled('feature_kyc_enabled')) ?>
            <?= $switch('feature_withdraw_require_kyc', 'Withdrawal requires KYC', 'Block withdrawal requests until member KYC is fully approved', feature_enabled('feature_withdraw_require_kyc', false)) ?>
            <?= $switch('feature_utility_enabled', 'Utility management', 'Geo, banks, news & helpers', feature_enabled('feature_utility_enabled')) ?>
            <?= $switch('feature_reports_enabled', 'Reports', 'Admin commission & activity reports', feature_enabled('feature_reports_enabled')) ?>
        </div>

        <div class="sa-form-actions">
            <button type="submit" class="btn btn-primary">Save configuration</button>
            <a href="index.php" class="btn btn-outline">Back to dashboard</a>
        </div>
    </div>
</form>
<script>
(function () {
    function syncSwitch(input) {
        var row = input.closest('.sa-switch');
        var state = row && row.querySelector('.sa-switch-state');
        if (!row) return;
        row.classList.toggle('is-on', input.checked);
        if (state) state.textContent = input.checked ? 'ON' : 'OFF';
    }

    function setChecked(id, on) {
        var input = document.getElementById(id);
        if (!input) return;
        input.checked = !!on;
        syncSwitch(input);
    }

    function setActivateValueVisible(on) {
        var card = document.getElementById('saActivateValueCard');
        var amountInput = document.getElementById('product_activate_min_amount');
        var keep = document.getElementById('product_activate_min_amount_keep');
        if (!card) return;
        card.classList.toggle('is-visible', !!on);
        card.classList.toggle('is-emphasis', !!on);
        if (on) {
            card.removeAttribute('hidden');
            if (amountInput) amountInput.disabled = false;
            if (keep) keep.disabled = true;
        } else {
            card.setAttribute('hidden', 'hidden');
            if (amountInput) {
                amountInput.disabled = true;
                if (keep) {
                    keep.disabled = false;
                    keep.value = amountInput.value;
                } else {
                    // create keep field so value is not lost on save
                    keep = document.createElement('input');
                    keep.type = 'hidden';
                    keep.name = 'product_activate_min_amount';
                    keep.id = 'product_activate_min_amount_keep';
                    keep.value = amountInput.value;
                    card.insertAdjacentElement('afterend', keep);
                }
            }
        }
    }

    function applyProductOnlyRails(on) {
        setActivateValueVisible(on);
        if (on) {
            setChecked('sw_feature_package_enabled', false);
            setChecked('sw_feature_tpin_enabled', false);
            setChecked('sw_feature_utr_activation_enabled', false);
            setChecked('sw_feature_product_shop_enabled', true);
            setChecked('sw_feature_product_activates_package', true);
            return;
        }
        // Leaving Product Only — turn packages + UTR back on if all classic rails are off
        var pkg = document.getElementById('sw_feature_package_enabled');
        var tpin = document.getElementById('sw_feature_tpin_enabled');
        var utr = document.getElementById('sw_feature_utr_activation_enabled');
        var anyRail = (pkg && pkg.checked) || (tpin && tpin.checked) || (utr && utr.checked);
        if (!anyRail) {
            setChecked('sw_feature_package_enabled', true);
            setChecked('sw_feature_utr_activation_enabled', true);
        }
    }

    document.querySelectorAll('.sa-mode-opt input[type="radio"]').forEach(function (input) {
        input.addEventListener('change', function () {
            document.querySelectorAll('.sa-mode-opt').forEach(function (el) { el.classList.remove('is-on'); });
            if (input.checked && input.closest('.sa-mode-opt')) {
                input.closest('.sa-mode-opt').classList.add('is-on');
            }
        });
    });

    document.querySelectorAll('.sa-switch input[type="checkbox"]').forEach(function (input) {
        input.addEventListener('change', function () {
            syncSwitch(input);
            if (input.id === 'sw_feature_product_only_activation') {
                applyProductOnlyRails(input.checked);
            }
            // Turning Packages back ON while Product Only is on → turn Product Only off
            if (input.id === 'sw_feature_package_enabled' && input.checked) {
                setChecked('sw_feature_product_only_activation', false);
                setActivateValueVisible(false);
            }
        });
        syncSwitch(input);
    });

    // Initial sync
    var pOnly = document.getElementById('sw_feature_product_only_activation');
    setActivateValueVisible(!!(pOnly && pOnly.checked));
    if (pOnly && pOnly.checked) {
        applyProductOnlyRails(true);
    }

    var amountInput = document.getElementById('product_activate_min_amount');
    var hint = document.getElementById('saActivateValueHint');
    var chips = document.querySelectorAll('.sa-activate-chip');
    function formatMoney(n) {
        try {
            return new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(n);
        } catch (e) {
            return String(n);
        }
    }
    function refreshHint() {
        if (!amountInput || !hint) return;
        var v = parseFloat(amountInput.value || '0');
        if (!isFinite(v) || v <= 0) {
            hint.innerHTML = '<strong>0 = any product</strong> purchase can activate the ID (Product Only mode).';
        } else {
            hint.innerHTML = 'Buy product of <strong>₹' + formatMoney(v) + '</strong> or more → ID activates. No package link needed in Product Only.';
        }
        chips.forEach(function (chip) {
            var a = parseFloat(chip.getAttribute('data-amount') || '0');
            chip.classList.toggle('is-on', Math.abs(a - v) < 0.001);
        });
    }
    if (amountInput) {
        amountInput.addEventListener('input', refreshHint);
        amountInput.addEventListener('change', refreshHint);
    }
    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            if (!amountInput) return;
            amountInput.value = parseFloat(chip.getAttribute('data-amount') || '0').toFixed(2);
            refreshHint();
            amountInput.focus();
        });
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
