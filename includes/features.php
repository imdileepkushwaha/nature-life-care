<?php
/**
 * Feature flags / plan mode for this install (controlled by Super Admin).
 * Client Admin + User panels only see/run what is enabled here.
 */

/** @return array<string, string> default feature settings */
function feature_defaults(): array
{
    return [
        // Plan topology: hybrid | binary | level
        'plan_mode' => 'hybrid',

        // Activation rails
        'feature_package_enabled' => '1',
        'feature_tpin_enabled' => '1',
        'feature_utr_activation_enabled' => '1',
        'feature_wallet_topup_enabled' => '1',
        'feature_product_shop_enabled' => '1',
        // When on: paid product order can activate member via product.package_id
        'feature_product_activates_package' => '0',
        // When on: only product purchase activates (no UTR / T-PIN / wallet activate rails)
        'feature_product_only_activation' => '0',
        // Minimum product unit price required to activate (0 = any linked product)
        'product_activate_min_amount' => '0',

        // Client license (Super Admin)
        'client_license_status' => 'active',
        'client_license_expires' => '',

        // Client Admin cannot edit packages when locked
        'client_packages_locked' => '0',

        // Matrix width (slots per node) when plan_mode=matrix
        'matrix_width' => '3',

        // Income modules (also sync binary_income_enabled / level_income_enabled)
        'feature_binary_income' => '1',
        'feature_level_income' => '1',
        'feature_referral_income' => '1',
        'feature_matching_income' => '1',

        // Operational modules
        'feature_withdrawals_enabled' => '1',
        'feature_kyc_enabled' => '1',
        // When on (+ KYC on): members need approved KYC before withdrawal request
        'feature_withdraw_require_kyc' => '0',
        'feature_utility_enabled' => '1',
        'feature_reports_enabled' => '1',

        // Meta
        'feature_preset' => 'hybrid_full',
        'client_locked_note' => 'Commission rates and module switches are not editable here.',
    ];
}

/**
 * Named presets Super Admin can apply in one click.
 * @return array<string, array{label: string, description: string, settings: array<string, string>}>
 */
function feature_presets(): array
{
    return [
        'hybrid_full' => [
            'label' => 'Hybrid Full',
            'description' => 'Binary + Level, Package, T-PIN, Products, UTR, Wallet — everything on.',
            'settings' => [
                'plan_mode' => 'hybrid',
                'feature_package_enabled' => '1',
                'feature_tpin_enabled' => '1',
                'feature_utr_activation_enabled' => '1',
                'feature_wallet_topup_enabled' => '1',
                'feature_product_shop_enabled' => '1',
                'feature_product_activates_package' => '0',
                'feature_product_only_activation' => '0',
                'feature_binary_income' => '1',
                'feature_level_income' => '1',
                'feature_referral_income' => '1',
                'feature_matching_income' => '1',
                'feature_withdrawals_enabled' => '1',
                'feature_kyc_enabled' => '1',
                'feature_utility_enabled' => '1',
                'feature_reports_enabled' => '1',
            ],
        ],
        'binary_package_tpin' => [
            'label' => 'Binary + Package + T-PIN',
            'description' => 'Classic binary MLM. No product shop. Activate via package / T-PIN.',
            'settings' => [
                'plan_mode' => 'binary',
                'feature_package_enabled' => '1',
                'feature_tpin_enabled' => '1',
                'feature_utr_activation_enabled' => '1',
                'feature_wallet_topup_enabled' => '1',
                'feature_product_shop_enabled' => '0',
                'feature_product_activates_package' => '0',
                'feature_product_only_activation' => '0',
                'feature_binary_income' => '1',
                'feature_level_income' => '0',
                'feature_referral_income' => '1',
                'feature_matching_income' => '1',
                'feature_withdrawals_enabled' => '1',
                'feature_kyc_enabled' => '1',
                'feature_utility_enabled' => '1',
                'feature_reports_enabled' => '1',
            ],
        ],
        'level_only' => [
            'label' => 'Level Plan Only',
            'description' => 'Sponsor-level income only. No binary tree / closing.',
            'settings' => [
                'plan_mode' => 'level',
                'feature_package_enabled' => '1',
                'feature_tpin_enabled' => '1',
                'feature_utr_activation_enabled' => '1',
                'feature_wallet_topup_enabled' => '0',
                'feature_product_shop_enabled' => '0',
                'feature_product_activates_package' => '0',
                'feature_product_only_activation' => '0',
                'feature_binary_income' => '0',
                'feature_level_income' => '1',
                'feature_referral_income' => '1',
                'feature_matching_income' => '0',
                'feature_withdrawals_enabled' => '1',
                'feature_kyc_enabled' => '1',
                'feature_utility_enabled' => '1',
                'feature_reports_enabled' => '1',
            ],
        ],
        'epin_company' => [
            'label' => 'E-Pin / T-PIN Company',
            'description' => 'Hybrid income. T-PIN required style; product shop off.',
            'settings' => [
                'plan_mode' => 'hybrid',
                'feature_package_enabled' => '1',
                'feature_tpin_enabled' => '1',
                'feature_utr_activation_enabled' => '0',
                'feature_wallet_topup_enabled' => '0',
                'feature_product_shop_enabled' => '0',
                'feature_product_activates_package' => '0',
                'feature_product_only_activation' => '0',
                'feature_binary_income' => '1',
                'feature_level_income' => '1',
                'feature_referral_income' => '1',
                'feature_matching_income' => '1',
                'feature_withdrawals_enabled' => '1',
                'feature_kyc_enabled' => '1',
                'feature_utility_enabled' => '1',
                'feature_reports_enabled' => '1',
            ],
        ],
        'product_binary' => [
            'label' => 'Product + Binary',
            'description' => 'Product shop activates package + binary income.',
            'settings' => [
                'plan_mode' => 'binary',
                'feature_package_enabled' => '1',
                'feature_tpin_enabled' => '0',
                'feature_utr_activation_enabled' => '1',
                'feature_wallet_topup_enabled' => '1',
                'feature_product_shop_enabled' => '1',
                'feature_product_activates_package' => '1',
                'feature_product_only_activation' => '0',
                'feature_binary_income' => '1',
                'feature_level_income' => '0',
                'feature_referral_income' => '1',
                'feature_matching_income' => '0',
                'feature_withdrawals_enabled' => '1',
                'feature_kyc_enabled' => '1',
                'feature_utility_enabled' => '1',
                'feature_reports_enabled' => '1',
            ],
        ],
        'unilevel_only' => [
            'label' => 'Unilevel Only',
            'description' => 'Pure sponsor-chain (unilevel). No binary legs or matrix.',
            'settings' => [
                'plan_mode' => 'unilevel',
                'feature_package_enabled' => '1',
                'feature_tpin_enabled' => '1',
                'feature_utr_activation_enabled' => '1',
                'feature_wallet_topup_enabled' => '1',
                'feature_product_shop_enabled' => '0',
                'feature_product_activates_package' => '0',
                'feature_product_only_activation' => '0',
                'feature_binary_income' => '0',
                'feature_level_income' => '1',
                'feature_referral_income' => '1',
                'feature_matching_income' => '0',
                'feature_withdrawals_enabled' => '1',
                'feature_kyc_enabled' => '1',
                'feature_utility_enabled' => '1',
                'feature_reports_enabled' => '1',
            ],
        ],
        'matrix_3x' => [
            'label' => 'Matrix 3×',
            'description' => '3-wide matrix spillover + level income. No binary pair closing.',
            'settings' => [
                'plan_mode' => 'matrix',
                'matrix_width' => '3',
                'feature_package_enabled' => '1',
                'feature_tpin_enabled' => '1',
                'feature_utr_activation_enabled' => '1',
                'feature_wallet_topup_enabled' => '1',
                'feature_product_shop_enabled' => '0',
                'feature_product_activates_package' => '0',
                'feature_product_only_activation' => '0',
                'feature_binary_income' => '0',
                'feature_level_income' => '1',
                'feature_referral_income' => '1',
                'feature_matching_income' => '0',
                'feature_withdrawals_enabled' => '1',
                'feature_kyc_enabled' => '1',
                'feature_utility_enabled' => '1',
                'feature_reports_enabled' => '1',
            ],
        ],
        'product_only' => [
            'label' => 'Product Only',
            'description' => 'No starter/package plans. Admin adds products; member buys a product at/above the set activation value → ID activates. No UTR / T-PIN / wallet activate.',
            'settings' => [
                'plan_mode' => 'hybrid',
                'feature_package_enabled' => '0',
                'feature_tpin_enabled' => '0',
                'feature_utr_activation_enabled' => '0',
                'feature_wallet_topup_enabled' => '1',
                'feature_product_shop_enabled' => '1',
                'feature_product_activates_package' => '1',
                'feature_product_only_activation' => '1',
                'feature_binary_income' => '1',
                'feature_level_income' => '1',
                'feature_referral_income' => '1',
                'feature_matching_income' => '1',
                'feature_withdrawals_enabled' => '1',
                'feature_kyc_enabled' => '1',
                'feature_utility_enabled' => '1',
                'feature_reports_enabled' => '1',
            ],
        ],
    ];
}

function feature_save(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
    clear_setting_cache($key);
}

/** Ensure default feature keys exist (safe to call often). */
function feature_ensure_defaults(PDO $pdo): void
{
    foreach (feature_defaults() as $key => $val) {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$key]);
            if (!$stmt->fetchColumn()) {
                feature_save($pdo, $key, $val);
            }
        } catch (Throwable $e) {
            // settings table may not exist yet
        }
    }

    // Only enforce Product Only rails when that preset is the active selection.
    // Do NOT rewrite flags just because the checkbox was left on — that was wiping
    // ON/OFF saves after switching to another plan.
    try {
        if (setting('feature_preset', '') === 'product_only') {
            feature_save($pdo, 'feature_product_only_activation', '1');
            feature_save($pdo, 'feature_product_shop_enabled', '1');
            feature_save($pdo, 'feature_product_activates_package', '1');
            feature_save($pdo, 'feature_package_enabled', '0');
            feature_save($pdo, 'feature_tpin_enabled', '0');
            feature_save($pdo, 'feature_utr_activation_enabled', '0');
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function feature_enabled(string $key, bool $default = true): bool
{
    $defaults = feature_defaults();
    $fallback = array_key_exists($key, $defaults) ? $defaults[$key] : ($default ? '1' : '0');
    return setting($key, $fallback) === '1';
}

/** @return 'hybrid'|'binary'|'level'|'unilevel'|'matrix' */
function plan_mode(): string
{
    $mode = strtolower(setting('plan_mode', 'hybrid'));
    return in_array($mode, ['hybrid', 'binary', 'level', 'unilevel', 'matrix'], true) ? $mode : 'hybrid';
}

function plan_uses_binary(): bool
{
    $mode = plan_mode();
    return ($mode === 'binary' || $mode === 'hybrid') && feature_enabled('feature_binary_income');
}

function plan_uses_level(): bool
{
    $mode = plan_mode();
    return in_array($mode, ['level', 'unilevel', 'hybrid', 'matrix'], true)
        && feature_enabled('feature_level_income');
}

function plan_uses_matrix(): bool
{
    return plan_mode() === 'matrix';
}

function client_packages_locked(): bool
{
    // Packages are managed by Client Admin only (not Super Admin).
    return false;
}

/**
 * Apply a preset + keep engine income flags in sync.
 * @param array<string, string>|null $overrides
 */
function feature_apply_preset(PDO $pdo, string $presetKey, ?array $overrides = null): bool
{
    $presets = feature_presets();
    if (!isset($presets[$presetKey])) {
        return false;
    }

    $settings = $presets[$presetKey]['settings'];
    if ($overrides) {
        $settings = array_merge($settings, $overrides);
    }

    foreach ($settings as $key => $val) {
        feature_save($pdo, $key, (string) $val);
    }
    feature_save($pdo, 'feature_preset', $presetKey);

    // Keep legacy closing/income settings aligned
    feature_save($pdo, 'binary_income_enabled', ($settings['feature_binary_income'] ?? '0') === '1' ? '1' : '0');
    feature_save($pdo, 'level_income_enabled', ($settings['feature_level_income'] ?? '0') === '1' ? '1' : '0');

    clear_setting_cache();
    return true;
}

/**
 * Last applied quick-preset key (stable — not recomputed from toggles).
 */
function feature_active_preset_key(): string
{
    $key = trim((string) setting('feature_preset', 'hybrid_full'));
    $presets = feature_presets();
    if ($key !== '' && $key !== 'custom' && isset($presets[$key])) {
        return $key;
    }
    return 'custom';
}

/**
 * True when current flags differ from the last applied preset template.
 */
function feature_preset_is_modified(?string $presetKey = null): bool
{
    $presetKey = $presetKey ?? feature_active_preset_key();
    $presets = feature_presets();
    if ($presetKey === '' || $presetKey === 'custom' || !isset($presets[$presetKey])) {
        return $presetKey === 'custom';
    }
    $defaults = feature_defaults();
    foreach ($presets[$presetKey]['settings'] as $sk => $sv) {
        $fallback = $defaults[$sk] ?? '';
        if ((string) setting($sk, (string) $fallback) !== (string) $sv) {
            return true;
        }
    }
    return false;
}

/**
 * @deprecated Use feature_active_preset_key() — kept for callers.
 */
function feature_resolve_active_preset(): string
{
    return feature_active_preset_key();
}

/**
 * Save custom feature form from Super Admin.
 * Preserves the last applied preset selection; does not kick the card to “custom”
 * just because a switch was toggled.
 * @param array<string, mixed> $post
 */
function feature_save_from_post(PDO $pdo, array $post): void
{
    $boolKeys = [
        'feature_package_enabled',
        'feature_tpin_enabled',
        'feature_utr_activation_enabled',
        'feature_wallet_topup_enabled',
        'feature_product_shop_enabled',
        'feature_product_activates_package',
        'feature_product_only_activation',
        'feature_binary_income',
        'feature_level_income',
        'feature_referral_income',
        'feature_matching_income',
        'feature_withdrawals_enabled',
        'feature_kyc_enabled',
        'feature_withdraw_require_kyc',
        'feature_utility_enabled',
        'feature_reports_enabled',
    ];

    // Remember selection before writes (clear_setting_cache may run later)
    $previousPreset = trim((string) setting('feature_preset', 'hybrid_full'));

    $mode = strtolower(trim((string) ($post['plan_mode'] ?? 'hybrid')));
    if (!in_array($mode, ['hybrid', 'binary', 'level', 'unilevel', 'matrix'], true)) {
        $mode = 'hybrid';
    }
    feature_save($pdo, 'plan_mode', $mode);

    $mw = max(2, min(10, (int) ($post['matrix_width'] ?? 3)));
    feature_save($pdo, 'matrix_width', (string) $mw);

    $minActivate = max(0.0, (float) ($post['product_activate_min_amount'] ?? 0));
    feature_save($pdo, 'product_activate_min_amount', (string) round($minActivate, 2));

    foreach ($boolKeys as $key) {
        feature_save($pdo, $key, isset($post[$key]) ? '1' : '0');
    }

    $productOnly = isset($post['feature_product_only_activation']);

    // Product-only mode: shop activates by product value — force packages/UTR/T-PIN off
    if ($productOnly) {
        feature_save($pdo, 'feature_product_shop_enabled', '1');
        feature_save($pdo, 'feature_product_activates_package', '1');
        feature_save($pdo, 'feature_product_only_activation', '1');
        feature_save($pdo, 'feature_package_enabled', '0');
        feature_save($pdo, 'feature_tpin_enabled', '0');
        feature_save($pdo, 'feature_utr_activation_enabled', '0');
    } elseif ($previousPreset === 'product_only' && !$productOnly) {
        // Leaving Product Only — restore classic package + UTR if user left all activation rails off
        feature_save($pdo, 'feature_product_only_activation', '0');
        $hasRail = isset($post['feature_package_enabled'])
            || isset($post['feature_tpin_enabled'])
            || isset($post['feature_utr_activation_enabled'])
            || isset($post['feature_product_activates_package']);
        if (!$hasRail) {
            feature_save($pdo, 'feature_package_enabled', '1');
            feature_save($pdo, 'feature_utr_activation_enabled', '1');
        }
    }

    // Packages are always editable by Client Admin
    feature_save($pdo, 'client_packages_locked', '0');

    // Mode constraints (income topology only — do not wipe activation rails)
    if ($mode === 'binary') {
        feature_save($pdo, 'feature_binary_income', '1');
        feature_save($pdo, 'feature_level_income', isset($post['feature_level_income']) ? '1' : '0');
    } elseif ($mode === 'level' || $mode === 'unilevel') {
        feature_save($pdo, 'feature_binary_income', '0');
        feature_save($pdo, 'feature_level_income', '1');
        feature_save($pdo, 'feature_matching_income', '0');
    } elseif ($mode === 'matrix') {
        feature_save($pdo, 'feature_binary_income', '0');
        feature_save($pdo, 'feature_level_income', '1');
        feature_save($pdo, 'feature_matching_income', '0');
    }

    feature_save($pdo, 'binary_income_enabled', feature_enabled('feature_binary_income') ? '1' : '0');
    feature_save($pdo, 'level_income_enabled', feature_enabled('feature_level_income') ? '1' : '0');

    // Keep last applied preset selected. Only switch badge when Product Only is explicitly on/off.
    $presets = feature_presets();
    if ($productOnly) {
        feature_save($pdo, 'feature_preset', 'product_only');
    } elseif ($previousPreset === 'product_only') {
        // Left Product Only — pick a preset matching current plan_mode
        $fallback = 'hybrid_full';
        foreach ($presets as $pkey => $p) {
            if ($pkey === 'product_only') {
                continue;
            }
            if (($p['settings']['plan_mode'] ?? '') === $mode) {
                $fallback = $pkey;
                break;
            }
        }
        feature_save($pdo, 'feature_preset', $fallback);
    } elseif ($previousPreset !== '' && isset($presets[$previousPreset])) {
        feature_save($pdo, 'feature_preset', $previousPreset);
    } else {
        feature_save($pdo, 'feature_preset', 'custom');
    }

    clear_setting_cache();
}

/** Admin pages → required feature key (null = always allowed). */
function feature_admin_page_map(): array
{
    return [
        'tree-view' => 'binary_tree',
        'binary-tree' => 'binary_tree',
        'matrix-tree' => 'matrix_tree',
        'level-tree' => 'level_tree',
        'binary-closing' => 'binary_closing',
        'report-binary-closing' => 'binary_closing',
        'packages' => 'packages',
        'package-assign-products' => 'packages',
        'package-plans' => 'packages',
        'plans' => 'packages',
        'activations' => 'activations',
        'tpin' => 'tpin',
        'tpin-transfer' => 'tpin',
        'tpin-report' => 'tpin',
        'product-categories' => 'products',
        'product-subcategories' => 'products',
        'product-sizes' => 'products',
        'product-colors' => 'products',
        'subcategory-settings' => 'products',
        'product-add' => 'products',
        'product-form' => 'products',
        'product-details' => 'products',
        'product-status' => 'products',
        'product-orders' => 'products',
        'stock-report' => 'products',
        'vendors' => 'products',
        'stock-purchase' => 'products',
        'purchase-details' => 'products',
        'commodity-prices' => 'products',
        'wallet-topup-requests' => 'wallet_topup',
        'withdrawals' => 'withdrawals',
        'approve-kyc' => 'kyc',
        'countries' => 'utility',
        'states' => 'utility',
        'cities' => 'utility',
        'banks' => 'utility',
        'bank-accounts' => 'utility',
        'news' => 'utility',
        'direct-member-login' => 'utility',
        'reports' => 'reports',
        'report-commission' => 'reports',
        'report-joining' => 'reports',
        'report-package-sales' => 'reports',
        'report-top-earners' => 'reports',
        'tds-report' => 'reports',
    ];
}

/** User pages → required feature key. */
function feature_user_page_map(): array
{
    return [
        'my-treeview' => 'binary_tree',
        'matrix-tree' => 'matrix_tree',
        'level-tree' => 'level_tree',
        'activate' => 'activations',
        'tpin' => 'tpin',
        'wallet-topup' => 'wallet_topup',
        'wallet-topup-activate' => 'wallet_activate',
        'purchase-product' => 'products',
        'purchase-cart' => 'products',
        'purchase-checkout' => 'products',
        'purchase-report' => 'products',
        'purchase-tracking' => 'products',
        'purchase-invoice' => 'products',
        'wallet-shopping' => 'products',
        'income-binary' => 'income_binary',
        'income-level' => 'income_level',
        'income-referral' => 'income_referral',
        'income-matching' => 'income_matching',
        'withdrawal-fund' => 'withdrawals',
        'withdrawal-report' => 'withdrawals',
        'kyc-pan' => 'kyc',
        'kyc-bank' => 'kyc',
        'kyc-aadhar' => 'kyc',
        'kyc-upi' => 'kyc',
    ];
}

/**
 * Resolve abstract module keys used by page maps.
 */
function feature_module_allowed(string $module): bool
{
    switch ($module) {
        case 'binary_tree':
        case 'binary_closing':
            return plan_uses_binary();
        case 'matrix_tree':
            return plan_uses_matrix();
        case 'level_tree':
            return plan_uses_level() || in_array(plan_mode(), ['level', 'unilevel', 'matrix'], true);
        case 'packages':
            return feature_enabled('feature_package_enabled');
        case 'activations':
            return feature_activation_any_rail();
        case 'tpin':
            return feature_enabled('feature_tpin_enabled');
        case 'products':
            return feature_enabled('feature_product_shop_enabled');
        case 'wallet_topup':
            return feature_enabled('feature_wallet_topup_enabled');
        case 'wallet_activate':
            return feature_enabled('feature_wallet_topup_enabled')
                && feature_enabled('feature_package_enabled')
                && !feature_product_only_activation();
        case 'withdrawals':
            return feature_enabled('feature_withdrawals_enabled');
        case 'kyc':
            return feature_enabled('feature_kyc_enabled');
        case 'utility':
            return feature_enabled('feature_utility_enabled');
        case 'reports':
            return feature_enabled('feature_reports_enabled');
        case 'income_binary':
            return plan_uses_binary();
        case 'income_level':
            return plan_uses_level();
        case 'income_referral':
            return feature_enabled('feature_referral_income');
        case 'income_matching':
            return feature_enabled('feature_matching_income') && plan_uses_binary();
        default:
            return true;
    }
}

function feature_guard_admin_page(?string $page = null): void
{
    $page = $page ?? basename($_SERVER['PHP_SELF'] ?? '', '.php');
    $map = feature_admin_page_map();
    if (!isset($map[$page])) {
        return;
    }
    if (!feature_module_allowed($map[$page])) {
        flash('error', 'This module is not available for your account.');
        header('Location: index.php');
        exit;
    }
}

function feature_guard_user_page(?string $page = null): void
{
    $page = $page ?? basename($_SERVER['PHP_SELF'] ?? '', '.php');
    $map = feature_user_page_map();
    if (!isset($map[$page])) {
        return;
    }
    if (!feature_module_allowed($map[$page])) {
        flash('error', 'This feature is not available on your plan.');
        header('Location: index.php');
        exit;
    }
}

/** Human-readable summary for Super Admin dashboard. */
function feature_summary(): array
{
    return [
        'plan_mode' => plan_mode(),
        'preset' => setting('feature_preset', 'hybrid_full'),
        'binary' => plan_uses_binary(),
        'level' => plan_uses_level(),
        'package' => feature_enabled('feature_package_enabled'),
        'tpin' => feature_enabled('feature_tpin_enabled'),
        'utr' => feature_enabled('feature_utr_activation_enabled'),
        'wallet_topup' => feature_enabled('feature_wallet_topup_enabled'),
        'products' => feature_enabled('feature_product_shop_enabled'),
        'product_activates' => feature_enabled('feature_product_activates_package'),
        'product_only' => feature_product_only_activation(),
        'product_min_amount' => product_activate_min_amount(),
        'referral' => feature_enabled('feature_referral_income'),
        'matching' => feature_enabled('feature_matching_income'),
        'withdrawals' => feature_enabled('feature_withdrawals_enabled'),
        'kyc' => feature_enabled('feature_kyc_enabled'),
        'withdraw_require_kyc' => feature_enabled('feature_withdraw_require_kyc', false),
        'license_ok' => client_license_ok(),
        'license_status' => client_license_status(),
        'license_expires' => setting('client_license_expires', ''),
        'packages_locked' => client_packages_locked(),
        'matrix_width' => matrix_width(),
    ];
}

/** Client install license: active | suspended */
function client_license_status(): string
{
    $s = strtolower(setting('client_license_status', 'active'));
    return $s === 'suspended' ? 'suspended' : 'active';
}

/** True when Client Admin + User panels may be used. */
function client_license_ok(): bool
{
    if (client_license_status() === 'suspended') {
        return false;
    }
    $expires = trim(setting('client_license_expires', ''));
    if ($expires === '') {
        return true;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
        return true;
    }
    return $expires >= date('Y-m-d');
}

function client_license_message(): string
{
    if (client_license_status() === 'suspended') {
        return 'Access is temporarily unavailable. Please contact support.';
    }
    $expires = trim(setting('client_license_expires', ''));
    if ($expires !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) && $expires < date('Y-m-d')) {
        return 'Access expired on ' . $expires . '. Please contact support.';
    }
    return '';
}

/**
 * Store company logo / favicon / signature. Returns ['ok'=>bool,'error'=>?string,'path'=>?string]
 * @param 'logo'|'favicon'|'signature' $kind
 */
function branding_store_image(array $file, string $kind = 'logo'): array
{
    $label = $kind === 'signature' ? 'Signature' : ucfirst($kind);
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'error' => null, 'path' => null];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => $label . ' upload failed. Try again.', 'path' => null];
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['ok' => false, 'error' => $label . ' must be under 2MB.', 'path' => null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];
    if ($kind === 'logo' || $kind === 'signature') {
        unset($allowed['image/x-icon'], $allowed['image/vnd.microsoft.icon']);
    }
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => $label . ' must be JPG, PNG' . ($kind === 'favicon' ? ', WebP or ICO' : ' or WebP') . '.', 'path' => null];
    }
    if ($allowed[$mime] !== 'ico' && @getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'Invalid ' . strtolower($label) . ' image file.', 'path' => null];
    }

    $uploadDir = BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'branding';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Could not create branding upload folder.', 'path' => null];
    }

    $name = $kind . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $allowed[$mime];
    $dest = $uploadDir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save ' . strtolower($label) . '.', 'path' => null];
    }

    return ['ok' => true, 'error' => null, 'path' => 'uploads/branding/' . $name];
}

function branding_delete_file(?string $path): void
{
    if ($path === null || $path === '') {
        return;
    }
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (!str_starts_with($path, 'uploads' . DIRECTORY_SEPARATOR . 'branding')) {
        return;
    }
    $full = BASE_PATH . DIRECTORY_SEPARATOR . $path;
    if (is_file($full)) {
        @unlink($full);
    }
}

/** Web URL relative to admin/user/superadmin pages (../uploads/...). */
function branding_asset_url(?string $path): ?string
{
    if ($path === null || trim($path) === '') {
        return null;
    }
    $rel = ltrim(str_replace('\\', '/', $path), '/');
    $full = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full)) {
        return null;
    }
    // Admin/user/superadmin live one folder deep → "../uploads/..."
    // Root pages (index/contact) need "uploads/..."
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $baseName = str_replace('\\', '/', BASE_PATH);
    $inSubdir = (bool) preg_match('#/(admin|user|superadmin|member)(/|$)#i', $script);
    if (!$inSubdir && isset($_SERVER['SCRIPT_FILENAME'])) {
        $dir = str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_FILENAME']));
        $inSubdir = (rtrim($dir, '/') !== rtrim($baseName, '/'));
    }
    return ($inSubdir ? '../' : '') . $rel;
}

function company_logo_url(): ?string
{
    return branding_asset_url(setting('company_logo', ''));
}

function company_favicon_url(): ?string
{
    return branding_asset_url(setting('company_favicon', '')) ?: company_logo_url();
}

/** Authorized signatory image for invoices / letters (../uploads/...). */
function company_signature_url(): ?string
{
    return branding_asset_url(setting('company_signature', ''));
}

/** Allowed activation pay modes for this install: utr|tpin|wallet */
function feature_activation_pay_modes(): array
{
    if (feature_product_only_activation()) {
        return [];
    }
    $modes = [];
    if (feature_enabled('feature_utr_activation_enabled') && feature_enabled('feature_package_enabled')) {
        $modes[] = 'utr';
    }
    if (feature_enabled('feature_tpin_enabled')) {
        $modes[] = 'tpin';
    }
    if (feature_enabled('feature_wallet_topup_enabled') && feature_enabled('feature_package_enabled')) {
        $modes[] = 'wallet';
    }
    return $modes;
}

/** True when ID activates only via product purchase (no package/UTR/T-PIN/wallet activate). */
function feature_product_only_activation(): bool
{
    return feature_enabled('feature_product_only_activation')
        && feature_enabled('feature_product_activates_package')
        && feature_enabled('feature_product_shop_enabled');
}

/** Minimum product unit price required to activate (0 = any linked product). */
function product_activate_min_amount(): float
{
    return max(0.0, round((float) setting('product_activate_min_amount', '0'), 2));
}

function feature_activation_default_pay_mode(): string
{
    $modes = feature_activation_pay_modes();
    return $modes[0] ?? '';
}

function feature_activation_any_rail(): bool
{
    return feature_activation_pay_modes() !== [];
}

/** True when registration should collect left/right binary position. */
function feature_registration_uses_binary_placement(): bool
{
    return in_array(plan_mode(), ['binary', 'hybrid'], true);
}

/** True when registration uses matrix spillover placement. */
function feature_registration_uses_matrix_placement(): bool
{
    return plan_mode() === 'matrix';
}

/**
 * Create super_admins table + default login (superadmin / superadmin123).
 */
function feature_ensure_superadmin_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS super_admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            status ENUM('active','inactive') DEFAULT 'active',
            last_login DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $hash = password_hash('superadmin123', PASSWORD_DEFAULT);
    try {
        $row = $pdo->query("SELECT id FROM super_admins WHERE username = 'superadmin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM super_admins')->fetchColumn();
            if ($count === 0) {
                $pdo->prepare('INSERT INTO super_admins (username, email, password, full_name, status) VALUES (?, ?, ?, ?, ?)')
                    ->execute(['superadmin', 'superadmin@binarymlm.com', $hash, 'Platform Super Admin', 'active']);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Snapshot of feature / commission keys for change audit.
 * @return array<string,string>
 */
function feature_audit_snapshot(PDO $pdo, ?array $keys = null): array
{
    if ($keys === null) {
        $keys = array_keys(feature_defaults());
        for ($i = 1; $i <= 20; $i++) {
            $keys[] = 'level_' . $i . '_percent';
        }
        $keys = array_merge($keys, [
            'binary_commission_percent',
            'referral_commission_percent',
            'matching_commission_percent',
            'binary_flush_pairs',
            'binary_pair_bv',
            'daily_closing_admin_charge',
            'binary_income_enabled',
            'level_income_enabled',
            'level_income_levels',
        ]);
        $keys = array_values(array_unique($keys));
    }
    $out = [];
    clear_setting_cache();
    foreach ($keys as $key) {
        $out[$key] = (string) setting($key, '');
    }
    return $out;
}

/**
 * Diff two snapshots → human lines "key: old → new".
 * @param array<string,string> $before
 * @param array<string,string> $after
 * @return list<string>
 */
function feature_audit_diff_lines(array $before, array $after): array
{
    $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
    sort($keys);
    $lines = [];
    foreach ($keys as $key) {
        $old = (string) ($before[$key] ?? '');
        $new = (string) ($after[$key] ?? '');
        if ($old === $new) {
            continue;
        }
        $lines[] = $key . ': ' . ($old === '' ? '(empty)' : $old) . ' → ' . ($new === '' ? '(empty)' : $new);
    }
    return $lines;
}

/**
 * Log Super Admin feature/commission changes with old → new detail.
 * @param array<string,string> $before
 * @param list<string>|null $onlyKeys limit after-snapshot comparison
 */
function feature_audit_log(PDO $pdo, string $action, string $summary, array $before, ?array $onlyKeys = null): void
{
    $after = feature_audit_snapshot($pdo, $onlyKeys);
    if ($onlyKeys !== null) {
        $before = array_intersect_key($before, array_flip($onlyKeys));
        $after = array_intersect_key($after, array_flip($onlyKeys));
    }
    $lines = feature_audit_diff_lines($before, $after);
    $details = $summary;
    if ($lines) {
        $details .= ' | ' . implode('; ', array_slice($lines, 0, 40));
        if (count($lines) > 40) {
            $details .= '; …+' . (count($lines) - 40) . ' more';
        }
    } else {
        $details .= ' | (no field changes)';
    }
    if (strlen($details) > 1900) {
        $details = substr($details, 0, 1890) . '…';
    }
    log_superadmin_activity($action, $details);
}
