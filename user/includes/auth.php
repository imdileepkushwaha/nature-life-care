<?php
/**
 * User panel auth helpers
 */
require_once __DIR__ . '/../../config/database.php';

function require_user(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    // License: kick logged-in members when install is suspended/expired
    if (!client_license_ok()) {
        user_logout_session();
        flash('error', client_license_message() ?: 'Access is temporarily unavailable. Please contact support.');
        header('Location: login.php');
        exit;
    }

    // Maintenance: kick logged-in members immediately
    if (is_maintenance_mode()) {
        user_logout_session();
        flash('error', 'Portal is under maintenance. You have been signed out. Please try again later.');
        header('Location: login.php');
        exit;
    }

    session_enforce_idle('user', 'login.php');
}

function current_user(PDO $pdo, bool $fresh = false): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $cached = null;
    static $cachedId = null;
    $id = (int) $_SESSION['user_id'];
    if (!$fresh && $cached !== null && $cachedId === $id) {
        return $cached;
    }
    ensure_member_photo_column($pdo);
    $stmt = $pdo->prepare('
        SELECT m.*, p.name AS package_name
        FROM members m
        LEFT JOIN packages p ON p.id = m.package_id
        WHERE m.id = ?
        LIMIT 1
    ');
    $stmt->execute([$id]);
    $cached = $stmt->fetch() ?: null;
    $cachedId = $id;
    return $cached;
}

/**
 * Display status for UI: "active" only when member status is active AND a package is assigned.
 * Canonical implementation lives in includes/utility.php.
 */
if (!function_exists('member_effective_status')) {
    function member_effective_status(array $member): string
    {
        $status = strtolower(trim((string) ($member['status'] ?? 'inactive')));
        if ($status === 'blocked') {
            return 'blocked';
        }
        if (empty($member['package_id'])) {
            return 'inactive';
        }
        if ($status === 'active') {
            return 'active';
        }
        return $status !== '' ? $status : 'inactive';
    }
}

if (!function_exists('member_is_active')) {
    function member_is_active(array $member): bool
    {
        return member_effective_status($member) === 'active';
    }
}

function user_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $initials .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $initials !== '' ? $initials : 'U';
}

/**
 * Ensure members.photo column exists (safe to call repeatedly).
 */
function ensure_member_photo_column(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM members LIKE 'photo'");
        if ($chk && !$chk->fetch()) {
            $pdo->exec('ALTER TABLE members ADD COLUMN photo VARCHAR(255) NULL AFTER phone');
        }
    } catch (Throwable $e) {
        // ignore — page can still run without column if DB user can't ALTER
    }
    $done = true;
}

/** Absolute filesystem path for a stored photo relative path. */
function user_photo_fs_path(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }
    $rel = str_replace(['\\', '..'], ['/', ''], $path);
    $full = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    return is_file($full) ? $full : null;
}

/**
 * Web path to member photo from user/ pages (../uploads/...).
 */
function user_photo_url(?string $path): ?string
{
    if ($path === null || trim($path) === '') {
        return null;
    }
    if (!user_photo_fs_path($path)) {
        return null;
    }
    return '../' . ltrim(str_replace('\\', '/', $path), '/');
}

/**
 * Render avatar HTML: photo img, or initials fallback.
 */
function user_avatar_html(?array $user, string $class = 'up-avatar', bool $onlineDot = false): string
{
    $name = (string) ($user['full_name'] ?? 'User');
    $initials = user_initials($name);
    $url = user_photo_url($user['photo'] ?? null);
    $hasPhoto = $url !== null;
    $classes = trim($class . ($hasPhoto ? ' has-photo' : ''));

    $html = '<div class="' . e($classes) . '">';
    if ($hasPhoto) {
        $html .= '<img src="' . e($url) . '" alt="' . e($name) . '" loading="lazy">';
    } else {
        $html .= e($initials);
    }
    if ($onlineDot) {
        $html .= '<span class="up-online" aria-hidden="true"></span>';
    }
    $html .= '</div>';
    return $html;
}

function user_delete_photo_file(?string $path): void
{
    $full = user_photo_fs_path($path);
    if ($full) {
        @unlink($full);
    }
}

/**
 * Validate + store uploaded member photo. Returns ['ok'=>true,'path'=>...] or ['ok'=>false,'error'=>...].
 */
function user_store_photo(array $file, int $memberId, string $uploadDir): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please choose a photo to upload.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Photo upload failed. Please try again.'];
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Photo must be under 2MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG or WebP photos are allowed.'];
    }

    // Extra safety: reject non-images even if mime lied
    if (@getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'Invalid image file.'];
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Could not create upload folder.'];
    }

    $name = 'm' . $memberId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save uploaded photo.'];
    }

    return ['ok' => true, 'path' => 'uploads/members/' . $name];
}

function user_flash_render(): void
{
    $flash = get_flash();
    if (!$flash) {
        return;
    }
    $type = $flash['type'] === 'success' ? 'ok' : ($flash['type'] === 'error' ? 'err' : 'info');
    echo '<div class="up-alert up-alert-' . e($type) . '">' . e($flash['message']) . '</div>';
}
