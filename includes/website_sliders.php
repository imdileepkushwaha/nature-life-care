<?php
/**
 * Home hero sliders (lp-hero).
 */

function website_sliders_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if ($pdo->inTransaction()) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS website_sliders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            heading VARCHAR(200) NOT NULL DEFAULT '',
            lead VARCHAR(400) NOT NULL DEFAULT '',
            image_path VARCHAR(500) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ws_status_sort (status, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

/** Store slider image. Returns ['ok'=>bool,'error'=>?string,'path'=>?string] */
function website_slider_store_image(array $file): array
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
    $uploadDir = $base . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sliders';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Could not create slider upload folder.', 'path' => null];
    }

    $name = 'slider_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $allowed[$mime];
    $dest = $uploadDir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save image.', 'path' => null];
    }

    return ['ok' => true, 'error' => null, 'path' => 'uploads/sliders/' . $name];
}

function website_slider_delete_file(?string $path): void
{
    $path = trim((string) $path);
    if ($path === '' || !str_starts_with($path, 'uploads/sliders/')) {
        return;
    }
    $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
    $full = $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (is_file($full)) {
        @unlink($full);
    }
}

function website_slider_image_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $nested = (bool) preg_match('#/(admin|user|member|superadmin)/#', $script);
    return ($nested ? '../' : '') . ltrim($path, '/');
}

/**
 * Active home hero slides. Empty = landing page should use built-in defaults.
 * @return list<array{heading:string,lead:string,image_url:string,alt:string}>
 */
function website_sliders_active(PDO $pdo): array
{
    website_sliders_ensure_table($pdo);
    try {
        $rows = $pdo->query("
            SELECT heading, lead, image_path
            FROM website_sliders
            WHERE status = 'active'
              AND image_path IS NOT NULL
              AND TRIM(image_path) != ''
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $url = website_slider_image_url((string) ($row['image_path'] ?? ''));
        if ($url === '') {
            continue;
        }
        $heading = trim((string) ($row['heading'] ?? ''));
        $out[] = [
            'heading' => $heading,
            'lead' => trim((string) ($row['lead'] ?? '')),
            'image_url' => $url,
            'alt' => $heading !== '' ? $heading : 'Home slider',
        ];
    }
    return $out;
}
