<?php
/**
 * Password reset helpers
 */

function ensure_password_resets_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_pr_member (member_id),
                KEY idx_pr_token (token_hash),
                KEY idx_pr_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // ignore
    }
    $done = true;
}

function pw_reset_find_member(PDO $pdo, string $login): ?array
{
    $login = trim($login);
    if ($login === '') {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT id, member_id, username, email, full_name, status
        FROM members
        WHERE username = ? OR email = ? OR member_id = ?
        LIMIT 1
    ');
    $stmt->execute([$login, $login, $login]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (($row['status'] ?? '') === 'blocked') {
        return null;
    }
    return $row;
}

function pw_reset_create_token(PDO $pdo, int $memberId): string
{
    ensure_password_resets_table($pdo);
    // Invalidate previous unused tokens
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE member_id = ? AND used_at IS NULL')
        ->execute([$memberId]);

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $pdo->prepare('INSERT INTO password_resets (member_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
        ->execute([$memberId, $hash]);

    return $token;
}

function pw_reset_find_valid(PDO $pdo, string $token): ?array
{
    ensure_password_resets_table($pdo);
    if (strlen($token) < 32) {
        return null;
    }
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare('
        SELECT r.id AS reset_id, r.member_id AS mid, r.expires_at, r.used_at,
               m.member_id, m.username, m.email, m.full_name, m.status
        FROM password_resets r
        INNER JOIN members m ON m.id = r.member_id
        WHERE r.token_hash = ?
        LIMIT 1
    ');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (!empty($row['used_at'])) {
        return null;
    }
    if (strtotime($row['expires_at']) < time()) {
        return null;
    }
    if (($row['status'] ?? '') === 'blocked') {
        return null;
    }
    return $row;
}

function pw_reset_mark_used(PDO $pdo, int $resetId): void
{
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$resetId]);
}

function pw_reset_url(string $token): string
{
    $base = rtrim(APP_URL, '/');
    return $base . '/user/reset-password.php?token=' . rawurlencode($token);
}

function pw_reset_send_mail(array $member, string $resetUrl): bool
{
    $to = trim((string) ($member['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $company = setting('company_name', 'Binary MLM');
    $from = setting('support_email', setting('contact_email', 'noreply@localhost'));
    $name = $member['full_name'] ?? 'Member';

    $subject = "{$company} — Reset your password";
    $body = "Hello {$name},\n\n"
        . "We received a request to reset your password for {$company}.\n\n"
        . "Username: " . ($member['username'] ?? '') . "\n"
        . "Member ID: " . ($member['member_id'] ?? '') . "\n\n"
        . "Reset your password using this link (valid for 1 hour):\n"
        . "{$resetUrl}\n\n"
        . "If you did not request this, you can ignore this email.\n\n"
        . "— {$company}\n";

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $company . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: PHP/' . PHP_VERSION,
    ];

    try {
        return @mail($to, $subject, $body, implode("\r\n", $headers));
    } catch (Throwable $e) {
        return false;
    }
}

function pw_reset_is_local(): bool
{
    $host = parse_url(APP_URL, PHP_URL_HOST) ?: '';
    return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with(strtolower($host), '.local');
}
