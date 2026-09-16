<?php
/**
 * Public website popups (landing / contact) — image only.
 */

function website_popups_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if ($pdo->inTransaction()) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS website_popups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL DEFAULT '',
            content TEXT NULL,
            cta_label VARCHAR(100) NULL,
            cta_url VARCHAR(500) NULL,
            image_path VARCHAR(500) NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_wp_status (status, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $col = $pdo->query("SHOW COLUMNS FROM website_popups LIKE 'image_path'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE website_popups ADD COLUMN image_path VARCHAR(500) NULL AFTER cta_url");
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $pdo->exec("ALTER TABLE website_popups MODIFY title VARCHAR(200) NOT NULL DEFAULT ''");
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $pdo->exec("ALTER TABLE website_popups MODIFY content TEXT NULL");
    } catch (Throwable $e) {
        // ignore
    }
    $done = true;
}

/** Store popup image. Returns ['ok'=>bool,'error'=>?string,'path'=>?string] */
function website_popup_store_image(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'error' => null, 'path' => null];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Image upload failed. Try again.', 'path' => null];
    }
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Image must be under 3MB.', 'path' => null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Image must be JPG, PNG, WebP or GIF.', 'path' => null];
    }
    if (@getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'Invalid image file.', 'path' => null];
    }

    $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
    $uploadDir = $base . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'popups';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Could not create popup upload folder.', 'path' => null];
    }

    $name = 'popup_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $allowed[$mime];
    $dest = $uploadDir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save image.', 'path' => null];
    }

    return ['ok' => true, 'error' => null, 'path' => 'uploads/popups/' . $name];
}

function website_popup_delete_file(?string $path): void
{
    $path = trim((string) $path);
    if ($path === '' || !str_starts_with($path, 'uploads/popups/')) {
        return;
    }
    $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
    $full = $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (is_file($full)) {
        @unlink($full);
    }
}

/** Public URL for popup image from current page context. */
function website_popup_image_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $inAdmin = str_contains($script, '/admin/');
    return ($inAdmin ? '../' : '') . ltrim($path, '/');
}

/** Latest active popup with image for the public website. */
function website_popup_active(PDO $pdo): ?array
{
    website_popups_ensure_table($pdo);
    try {
        $row = $pdo->query("
            SELECT * FROM website_popups
            WHERE status = 'active'
              AND image_path IS NOT NULL
              AND TRIM(image_path) != ''
            ORDER BY id DESC
            LIMIT 1
        ")->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}
