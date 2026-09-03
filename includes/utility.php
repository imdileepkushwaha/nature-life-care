<?php
/**
 * Shared helpers for Utility CRUD pages
 */

function utility_toggle_status(PDO $pdo, string $table, int $id): void
{
    $allowed = [
        'countries','states','cities','banks','bank_accounts','deductions','news','plans','package_plans',
        'product_categories','product_subcategories','product_sizes','product_colors','subcategory_settings',
        'products','product_vendors','commodity_prices',
    ];
    if (!in_array($table, $allowed, true) || $id < 1) {
        flash('error', 'Invalid request.');
        return;
    }
    $pdo->prepare("UPDATE `$table` SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$id]);
    log_activity('utility_toggle', "$table #$id status toggled");
    flash('success', 'Status updated.');
}

function utility_delete(PDO $pdo, string $table, int $id): bool
{
    $allowed = [
        'countries','states','cities','banks','bank_accounts','deductions','news','plans','package_plans',
        'product_categories','product_subcategories','product_sizes','product_colors','subcategory_settings',
        'products','product_vendors','commodity_prices','stock_purchases',
    ];
    if (!in_array($table, $allowed, true) || $id < 1) {
        flash('error', 'Invalid request.');
        return false;
    }
    try {
        $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);
        log_activity('utility_delete', "Deleted $table #$id");
        flash('success', 'Record deleted.');
        return true;
    } catch (PDOException $e) {
        flash('error', 'Cannot delete: record is in use by other data.');
        return false;
    }
}

function status_badge(string $status): string
{
    $raw = trim($status);
    $key = strtolower(str_replace([' ', '-'], '_', $raw));
    // Normalize common aliases to shared color classes
    $map = [
        'deactive' => 'inactive',
        'deactivated' => 'inactive',
        'disabled' => 'inactive',
        'reject' => 'rejected',
        'declined' => 'rejected',
        'canceled' => 'cancelled',
        'process' => 'processing',
        'inprogress' => 'processing',
        'in_progress' => 'processing',
        'waiting' => 'pending',
        'ok' => 'success',
        'enabled' => 'active',
    ];
    $class = $map[$key] ?? $key;
    $label = $raw !== '' ? $raw : '—';
    return '<span class="badge badge-' . e($class) . '">' . e($label) . '</span>';
}

/**
 * Display status: "active" only when not blocked AND a package is assigned.
 * Shared by admin + user panels.
 */
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

function member_is_active(array $member): bool
{
    return member_effective_status($member) === 'active';
}

function icon_svg(string $name): string
{
    $icons = [
        'edit' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
        'toggle-on' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="5" width="22" height="14" rx="7" fill="currentColor" fill-opacity="0.2"/><circle cx="16" cy="12" r="3" fill="currentColor"/></svg>',
        'toggle-off' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="5" width="22" height="14" rx="7"/><circle cx="8" cy="12" r="3" fill="currentColor"/></svg>',
        'toggle' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="5" width="22" height="14" rx="7"/><circle cx="16" cy="12" r="3"/></svg>',
        'delete' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>',
        'view' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
        'package' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>',
        'check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        'x' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'paid' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M16 15h2"/></svg>',
    ];
    return $icons[$name] ?? '';
}

function action_edit(string $href): string
{
    return '<a href="' . e($href) . '" class="btn-icon btn-icon-edit" title="Edit" aria-label="Edit">' . icon_svg('edit') . '</a>';
}

function action_toggle(string $href, string $status = 'active'): string
{
    $on = ($status === 'active');
    $cls = $on ? 'btn-icon btn-icon-toggle is-on' : 'btn-icon btn-icon-toggle is-off';
    $title = $on ? 'Active — click to deactivate' : 'Inactive — click to activate';
    $icon = $on ? 'toggle-on' : 'toggle-off';
    return '<a href="' . e($href) . '" class="' . $cls . '" title="' . e($title) . '" aria-label="' . e($title) . '">' . icon_svg($icon) . '</a>';
}

function action_delete(string $href, string $confirm = 'Delete this record?'): string
{
    return '<a href="' . e($href) . '" class="btn-icon btn-icon-delete" title="Delete" aria-label="Delete" data-confirm="' . e($confirm) . '">' . icon_svg('delete') . '</a>';
}

/** Submit button: Approve (check icon). */
function action_approve_btn(string $confirm = 'Approve this request?', string $name = 'action', string $value = 'approve'): string
{
    return '<button type="submit" name="' . e($name) . '" value="' . e($value) . '" class="btn-icon btn-icon-approve" title="Approve" aria-label="Approve" data-confirm="' . e($confirm) . '">'
        . icon_svg('check') . '</button>';
}

/** Submit button: Reject (X icon). */
function action_reject_btn(string $confirm = 'Reject this request?', string $name = 'action', string $value = 'reject'): string
{
    return '<button type="submit" name="' . e($name) . '" value="' . e($value) . '" class="btn-icon btn-icon-reject" title="Reject" aria-label="Reject" data-confirm="' . e($confirm) . '">'
        . icon_svg('x') . '</button>';
}

/** Submit button: Mark paid. */
function action_paid_btn(string $confirm = 'Mark as paid?', string $name = 'action', string $value = 'paid'): string
{
    return '<button type="submit" name="' . e($name) . '" value="' . e($value) . '" class="btn-icon btn-icon-paid" title="Mark Paid" aria-label="Mark Paid" data-confirm="' . e($confirm) . '">'
        . icon_svg('paid') . '</button>';
}

function action_buttons(int $id, string $deleteConfirm = 'Delete this record?', string $extraQuery = '', string $status = 'active'): string
{
    $q = $extraQuery !== '' ? '&' . ltrim($extraQuery, '&') : '';
    return '<div class="action-icons">'
        . action_edit('?edit=' . $id)
        . action_toggle('?toggle=' . $id . $q, $status)
        . action_delete('?delete=' . $id, $deleteConfirm)
        . '</div>';
}

/** Ensure bank_accounts has upi_id + qr_code columns. */
function bank_accounts_ensure_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    foreach (['upi_id' => 'VARCHAR(100) NULL', 'qr_code' => 'VARCHAR(255) NULL'] as $col => $def) {
        try {
            $exists = $pdo->query("SHOW COLUMNS FROM bank_accounts LIKE " . $pdo->quote($col))->fetch();
            if (!$exists) {
                $pdo->exec("ALTER TABLE bank_accounts ADD COLUMN `$col` $def");
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    $done = true;
}

/** Ensure products has bv column. */
function products_ensure_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $exists = $pdo->query("SHOW COLUMNS FROM products LIKE 'bv'")->fetch();
        if (!$exists) {
            $pdo->exec('ALTER TABLE products ADD COLUMN bv DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER price');
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $pkgCol = $pdo->query("SHOW COLUMNS FROM products LIKE 'package_id'")->fetch();
        if (!$pkgCol) {
            $pdo->exec('ALTER TABLE products ADD COLUMN package_id INT NULL DEFAULT NULL AFTER bv');
        }
    } catch (Throwable $e) {
        // ignore
    }
    $done = true;
}

/**
 * Store bank QR image. Returns ['ok'=>bool,'error'=>?string,'path'=>?string]
 */
function bank_store_qr(array $file, int $accountId = 0): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'error' => null, 'path' => null];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'QR upload failed. Try again.', 'path' => null];
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'QR image must be under 2MB.', 'path' => null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'QR must be JPG, PNG or WebP.', 'path' => null];
    }
    if (@getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'Invalid QR image file.', 'path' => null];
    }

    $uploadDir = BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bank-qr';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Could not create QR upload folder.', 'path' => null];
    }

    $name = 'qr_' . ($accountId > 0 ? $accountId . '_' : '') . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $allowed[$mime];
    $dest = $uploadDir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save QR image.', 'path' => null];
    }

    return ['ok' => true, 'error' => null, 'path' => 'uploads/bank-qr/' . $name];
}

function bank_delete_qr_file(?string $path): void
{
    if ($path === null || $path === '') {
        return;
    }
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (!str_starts_with($path, 'uploads' . DIRECTORY_SEPARATOR . 'bank-qr')) {
        return;
    }
    $full = BASE_PATH . DIRECTORY_SEPARATOR . $path;
    if (is_file($full)) {
        @unlink($full);
    }
}

/** Relative URL from admin/ or user/ pages. */
function bank_qr_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }
    $path = str_replace('\\', '/', $path);
    if (str_starts_with($path, 'uploads/')) {
        return '../' . $path;
    }
    return $path;
}
