<?php
/**
 * Database Configuration - Binary MLM
 * Local (localhost) → XAMPP defaults
 * Live server → online DB credentials
 */
require_once __DIR__ . '/env.php';

$hostName = app_http_host();
$isLocal = app_is_local();
$dbConfig = app_db_config_for_current();

define('DB_HOST', $dbConfig['host']);
define('DB_PORT', (string) ($dbConfig['port'] ?? '3306'));
define('DB_NAME', $dbConfig['name']);
define('DB_USER', $dbConfig['user']);
define('DB_PASS', $dbConfig['pass']);
define('DB_CHARSET', 'utf8mb4');
define('DB_SLOT', app_db_active_slot()); // local|live
define('DB_MODE', app_db_connection_mode()); // auto|online|offline

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
define('APP_NAME', 'Binary MLM Admin');
define('APP_URL', $scheme . '://' . $hostName);
define('BASE_PATH', dirname(__DIR__));

date_default_timezone_set('Asia/Kolkata');

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

if (session_status() === PHP_SESSION_NONE) {
    // Keep PHP session cookie alive long enough; idle logout is enforced in-app (15 min).
    ini_set('session.gc_maxlifetime', (string) max(1800, (int) ini_get('session.gc_maxlifetime')));
    session_start();
}

/** Idle timeout in seconds (15 minutes). */
define('SESSION_IDLE_TIMEOUT', 15 * 60);

/**
 * Record last activity for a session scope (user|admin|member).
 */
function session_touch(string $scope): void
{
    $_SESSION[$scope . '_last_activity'] = time();
}

/**
 * Clear keys for a session scope after timeout / logout.
 */
function session_clear_scope(string $scope): void
{
    $map = [
        'user' => [
            'user_id', 'user_name', 'user_code', 'user_last_activity',
            'user_login_by_admin', 'user_login_admin_id',
        ],
        'admin' => ['admin_id', 'admin_name', 'admin_username', 'admin_last_activity'],
        'superadmin' => [
            'superadmin_id', 'superadmin_name', 'superadmin_username', 'superadmin_last_activity',
        ],
        'member' => [
            'member_id', 'member_code', 'member_name',
            'member_login_by_admin', 'member_login_admin_id', 'member_last_activity',
        ],
        'franchise' => [
            'franchise_id', 'franchise_code', 'franchise_name', 'franchise_type',
            'franchise_login_by_admin', 'franchise_last_activity',
        ],
    ];
    foreach ($map[$scope] ?? [] as $key) {
        unset($_SESSION[$key]);
    }
}

/**
 * Enforce idle timeout for an authenticated scope.
 * Call after confirming the primary session id key exists.
 */
function session_enforce_idle(string $scope, string $loginUrl): void
{
    $activityKey = $scope . '_last_activity';
    $now = time();
    $last = (int) ($_SESSION[$activityKey] ?? 0);

    if ($last > 0 && ($now - $last) > SESSION_IDLE_TIMEOUT) {
        session_clear_scope($scope);
        flash('error', 'Your session expired after 15 minutes of inactivity. Please sign in again.');
        header('Location: ' . $loginUrl);
        exit;
    }

    $_SESSION[$activityKey] = $now;
}

/**
 * Shared settings cache used by setting() / clear_setting_cache().
 * @return array<string, string>
 */
function &settings_cache_store(): array
{
    static $cache = [];
    return $cache;
}

function setting(string $key, string $default = ''): string
{
    global $pdo;
    $cache = &settings_cache_store();
    if (!array_key_exists($key, $cache)) {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = $row ? utf8_mojibake_fix((string) $row['setting_value']) : $default;
    }
    return $cache[$key];
}

/** Drop cached settings (call after admin saves settings). */
function clear_setting_cache(?string $key = null): void
{
    $cache = &settings_cache_store();
    if ($key === null) {
        foreach (array_keys($cache) as $k) {
            unset($cache[$k]);
        }
        return;
    }
    unset($cache[$key]);
}

function is_maintenance_mode(): bool
{
    return setting('maintenance_mode', 'off') === 'on';
}

/** Clear member portal session keys. */
function user_logout_session(): void
{
    session_clear_scope('user');
}

/** Unicode rupee (U+20B9) — avoid a literal ₹ in source so file encoding cannot break it. */
function currency_inr_symbol(): string
{
    return "\u{20B9}";
}

/** True when the stored/posted symbol is ₹ or a known broken encoding of it. */
function currency_symbol_looks_rupee(string $symbol): bool
{
    $raw = trim($symbol);
    $decoded = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $rupee = currency_inr_symbol();

    if ($decoded === '' || $decoded === $rupee || str_contains($decoded, $rupee)) {
        return true;
    }
    if (preg_match('/^(rs\.?|inr)$/i', $decoded)) {
        return true;
    }
    // Classic mojibake of UTF-8 ₹ (E2 82 B9) read as Windows-1252 / double-encoded UTF-8
    if (preg_match('/â‚¹|Ôé╣|Ã¢|Â‚|\\\\u20b9|&#8377;|&#x20b9;/iu', $raw . $decoded)) {
        return true;
    }

    return false;
}

/** Clean symbol for DB storage and form values. */
function currency_symbol_normalize(?string $symbol, ?string $code = null): string
{
    $symbol = trim((string) $symbol);
    $code = strtoupper(trim((string) ($code ?? setting('currency', 'INR'))));
    if ($code === 'INR' || currency_symbol_looks_rupee($symbol)) {
        return currency_inr_symbol();
    }
    $decoded = trim(html_entity_decode($symbol, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $decoded !== '' ? $decoded : currency_inr_symbol();
}

function currency(float $amount): string
{
    $symbol = currency_symbol();
    $code = strtoupper(setting('currency', 'INR'));

    if ($symbol === currency_inr_symbol() || $code === 'INR') {
        return '&#8377;' . number_format($amount, 2);
    }

    return htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8') . number_format($amount, 2);
}

/** Normalized currency symbol for display (fixes corrupted UTF-8 in DB). */
function currency_symbol(): string
{
    return currency_symbol_normalize(
        setting('currency_symbol', currency_inr_symbol()),
        setting('currency', 'INR')
    );
}

/** HTML-safe currency symbol for labels (INR uses &#8377;). */
function currency_symbol_html(): string
{
    $symbol = currency_symbol();
    return $symbol === currency_inr_symbol()
        ? '&#8377;'
        : htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8');
}

/** Value attribute that stays a real ₹ even if the page encoding slips. */
function currency_symbol_input_value(): string
{
    $symbol = currency_symbol();
    return $symbol === currency_inr_symbol()
        ? '&#8377;'
        : htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8');
}

/** Rewrite a corrupted currency_symbol row to a real rupee. */
function currency_symbol_heal(PDO $pdo): void
{
    $raw = setting('currency_symbol', currency_inr_symbol());
    $fixed = currency_symbol_normalize($raw, setting('currency', 'INR'));
    if ($raw === $fixed) {
        return;
    }
    feature_save($pdo, 'currency_symbol', $fixed);
}

/**
 * Fix UTF-8 punctuation that was stored as Windows-1252 mojibake (â€“ etc).
 */
function utf8_mojibake_fix(string $text): string
{
    if ($text === '') {
        return $text;
    }

    static $map = [
        // UTF-8 en/em dash (E2 80 93/94) misread as Windows-1252
        "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9C" => '-',
        "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9D" => '-',
        "\xC3\xA2\xE2\x82\xAC\xE2\x80\x93" => '-',
        "\xC3\xA2\xE2\x82\xAC\xE2\x80\x94" => '-',
        "\xC3\xA2\xE2\x82\xAC\xE2\x84\xA2" => "'",
        "\xC3\xA2\xE2\x82\xAC\xC5\x93" => '"',
        "\xC3\xA2\xE2\x80\x9A\xC2\xB9" => "\u{20B9}",
    ];

    return strtr($text, $map);
}

/** Rewrite settings rows that still contain classic UTF-8 mojibake. */
function settings_mojibake_heal(PDO $pdo): void
{
    try {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
    } catch (Throwable $e) {
        return;
    }
    $upd = $pdo->prepare(
        'UPDATE settings SET setting_value = ? WHERE setting_key = ?'
    );
    foreach ($rows as $row) {
        $key = (string) ($row['setting_key'] ?? '');
        $raw = (string) ($row['setting_value'] ?? '');
        $fixed = utf8_mojibake_fix($raw);
        if ($key === '' || $fixed === $raw) {
            continue;
        }
        $upd->execute([$fixed, $key]);
        clear_setting_cache($key);
    }
}

function e(?string $str): string
{
    return htmlspecialchars(utf8_mojibake_fix($str ?? ''), ENT_QUOTES, 'UTF-8');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function require_admin(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
    if (!client_license_ok()) {
        session_clear_scope('admin');
        flash('error', client_license_message() ?: 'Access is temporarily unavailable. Please contact support.');
        header('Location: login.php');
        exit;
    }
    session_enforce_idle('admin', 'login.php');
}

function require_superadmin(): void
{
    if (empty($_SESSION['superadmin_id'])) {
        header('Location: login.php');
        exit;
    }
    session_enforce_idle('superadmin', 'login.php');
}

function log_superadmin_activity(string $action, string $details = ''): void
{
    global $pdo;
    try {
        $stmt = $pdo->prepare('INSERT INTO activity_logs (admin_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            null,
            'superadmin:' . $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // activity_logs may be unavailable during setup
    }
}

function log_activity(string $action, string $details = ''): void
{
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO activity_logs (admin_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $_SESSION['admin_id'] ?? null,
        $action,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function member_id_prefix(): string
{
    $raw = setting('member_id_prefix', 'MLM');
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    return $prefix !== '' ? substr($prefix, 0, 10) : 'MLM';
}

function member_id_pad(): int
{
    return max(3, min(8, (int) setting('member_id_pad', '5')));
}

/** Next sample ID for settings preview (does not reserve). */
function member_id_preview(PDO $pdo): string
{
    $prefix = member_id_prefix();
    $pad = member_id_pad();
    $next = member_id_next_number($pdo, $prefix);
    return $prefix . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
}

function member_id_next_number(PDO $pdo, string $prefix): int
{
    $max = 0;
    try {
        $stmt = $pdo->prepare('SELECT member_id FROM members WHERE member_id LIKE ?');
        $stmt->execute([$prefix . '%']);
        $plen = strlen($prefix);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $mid) {
            $suffix = substr((string) $mid, $plen);
            if ($suffix !== '' && ctype_digit($suffix)) {
                $max = max($max, (int) $suffix);
            }
        }
    } catch (Throwable $e) {
        // fall through
    }
    return $max + 1;
}

function generate_member_id(PDO $pdo): string
{
    $prefix = member_id_prefix();
    $pad = member_id_pad();
    $start = member_id_next_number($pdo, $prefix);

    $check = $pdo->prepare('SELECT id FROM members WHERE member_id = ? LIMIT 1');
    for ($n = $start; $n < $start + 100000; $n++) {
        $code = $prefix . str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        $check->execute([$code]);
        if (!$check->fetch()) {
            return $code;
        }
    }

    return $prefix . strtoupper(bin2hex(random_bytes(3)));
}

require_once __DIR__ . '/../includes/utility.php';
require_once __DIR__ . '/../includes/features.php';
require_once __DIR__ . '/../includes/matrix.php';
require_once __DIR__ . '/../includes/plan_incentives.php';
require_once __DIR__ . '/../includes/income_tables.php';
require_once __DIR__ . '/../includes/franchise.php';

// Ensure feature defaults + super_admins when DB is ready (no-op if tables missing).
try {
    feature_ensure_defaults($pdo);
    feature_ensure_superadmin_table($pdo);
    currency_symbol_heal($pdo);
    settings_mojibake_heal($pdo);
    plan_incentives_ensure($pdo);
    income_tables_ensure($pdo);
    franchise_ensure_tables($pdo);
} catch (Throwable $e) {
    // ignore during early install
}

