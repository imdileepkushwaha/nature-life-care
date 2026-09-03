<?php
/**
 * Messaging channel settings (SMTP / WhatsApp API / SMS API).
 * Stored in settings table; used by future send helpers.
 */

function messaging_setting_defaults(): array
{
    return [
        'smtp_enabled' => '0',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_from_email' => '',
        'smtp_from_name' => '',

        'wa_api_enabled' => '0',
        'wa_api_provider' => 'meta',
        'wa_api_key' => '',
        'wa_api_token' => '',
        'wa_api_number' => '',
        'wa_api_endpoint' => '',

        'sms_api_enabled' => '0',
        'sms_api_provider' => 'msg91',
        'sms_api_key' => '',
        'sms_api_auth_token' => '',
        'sms_api_sender_id' => '',
        'sms_api_endpoint' => '',
    ];
}

function messaging_ensure_defaults(PDO $pdo): void
{
    foreach (messaging_setting_defaults() as $key => $val) {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$key]);
            if (!$stmt->fetchColumn()) {
                $ins = $pdo->prepare(
                    'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
                );
                $ins->execute([$key, $val]);
            }
        } catch (Throwable $e) {
            // ignore during setup
        }
    }
}

/** @return array<string,string> */
function messaging_get_all(): array
{
    $out = messaging_setting_defaults();
    foreach ($out as $key => $_) {
        $out[$key] = (string) setting($key, $out[$key]);
    }
    return $out;
}

function messaging_save_key(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
    if (function_exists('clear_setting_cache')) {
        clear_setting_cache($key);
    }
}

/**
 * @param array<string,mixed> $post
 */
function messaging_save_smtp(PDO $pdo, array $post): void
{
    messaging_save_key($pdo, 'smtp_enabled', isset($post['smtp_enabled']) ? '1' : '0');
    messaging_save_key($pdo, 'smtp_host', trim((string) ($post['smtp_host'] ?? '')));
    $port = (int) ($post['smtp_port'] ?? 587);
    if ($port < 1 || $port > 65535) {
        $port = 587;
    }
    messaging_save_key($pdo, 'smtp_port', (string) $port);
    $enc = strtolower(trim((string) ($post['smtp_encryption'] ?? 'tls')));
    if (!in_array($enc, ['none', 'tls', 'ssl'], true)) {
        $enc = 'tls';
    }
    messaging_save_key($pdo, 'smtp_encryption', $enc);
    messaging_save_key($pdo, 'smtp_username', trim((string) ($post['smtp_username'] ?? '')));
    $pass = (string) ($post['smtp_password'] ?? '');
    if ($pass !== '') {
        messaging_save_key($pdo, 'smtp_password', $pass);
    }
    messaging_save_key($pdo, 'smtp_from_email', trim((string) ($post['smtp_from_email'] ?? '')));
    messaging_save_key($pdo, 'smtp_from_name', trim((string) ($post['smtp_from_name'] ?? '')));
}

/**
 * @param array<string,mixed> $post
 */
function messaging_save_whatsapp(PDO $pdo, array $post): void
{
    messaging_save_key($pdo, 'wa_api_enabled', isset($post['wa_api_enabled']) ? '1' : '0');
    $provider = strtolower(trim((string) ($post['wa_api_provider'] ?? 'meta')));
    if (!in_array($provider, ['meta', 'twilio', 'gupshup', 'custom'], true)) {
        $provider = 'meta';
    }
    messaging_save_key($pdo, 'wa_api_provider', $provider);
    messaging_save_key($pdo, 'wa_api_key', trim((string) ($post['wa_api_key'] ?? '')));
    $token = (string) ($post['wa_api_token'] ?? '');
    if ($token !== '') {
        messaging_save_key($pdo, 'wa_api_token', $token);
    }
    messaging_save_key($pdo, 'wa_api_number', preg_replace('/\D+/', '', (string) ($post['wa_api_number'] ?? '')) ?? '');
    messaging_save_key($pdo, 'wa_api_endpoint', trim((string) ($post['wa_api_endpoint'] ?? '')));
}

/**
 * @param array<string,mixed> $post
 */
function messaging_save_sms(PDO $pdo, array $post): void
{
    messaging_save_key($pdo, 'sms_api_enabled', isset($post['sms_api_enabled']) ? '1' : '0');
    $provider = strtolower(trim((string) ($post['sms_api_provider'] ?? 'msg91')));
    if (!in_array($provider, ['msg91', 'twilio', 'textlocal', 'fast2sms', 'custom'], true)) {
        $provider = 'msg91';
    }
    messaging_save_key($pdo, 'sms_api_provider', $provider);
    $key = (string) ($post['sms_api_key'] ?? '');
    if ($key !== '') {
        messaging_save_key($pdo, 'sms_api_key', trim($key));
    }
    $auth = (string) ($post['sms_api_auth_token'] ?? '');
    if ($auth !== '') {
        messaging_save_key($pdo, 'sms_api_auth_token', trim($auth));
    }
    messaging_save_key($pdo, 'sms_api_sender_id', trim((string) ($post['sms_api_sender_id'] ?? '')));
    messaging_save_key($pdo, 'sms_api_endpoint', trim((string) ($post['sms_api_endpoint'] ?? '')));
}

function messaging_mask_secret(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $len = strlen($value);
    if ($len <= 4) {
        return str_repeat('•', $len);
    }
    return str_repeat('•', max(0, $len - 4)) . substr($value, -4);
}

/**
 * @return array{ok:bool,message:string}
 */
function messaging_http_probe(string $url, int $timeout = 8, ?string $user = null, ?string $pass = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'cURL extension is not available on this server.'];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'message' => 'Could not start HTTP probe.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'MLM-Messaging-Test/1.0',
        CURLOPT_HTTPGET => true,
    ]);
    if ($user !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . ($pass ?? ''));
    }
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno) {
        return ['ok' => false, 'message' => 'HTTP probe failed: ' . ($err !== '' ? $err : 'error ' . $errno)];
    }
    return ['ok' => true, 'message' => 'HTTP ' . $code, 'http_code' => $code, 'body' => is_string($body) ? $body : ''];
}

/**
 * TCP connect + SMTP banner check (does not send mail).
 * @return array{ok:bool,message:string}
 */
function messaging_test_smtp(?array $cfg = null): array
{
    $cfg = $cfg ?? messaging_get_all();
    $host = trim((string) ($cfg['smtp_host'] ?? ''));
    $port = (int) ($cfg['smtp_port'] ?? 587);
    if ($host === '') {
        return ['ok' => false, 'message' => 'SMTP host is empty. Save a host first.'];
    }
    if ($port < 1 || $port > 65535) {
        $port = 587;
    }
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 8);
    if (!$fp) {
        return ['ok' => false, 'message' => 'Cannot reach ' . $host . ':' . $port . ' — ' . ($errstr !== '' ? $errstr : 'timeout')];
    }
    stream_set_timeout($fp, 8);
    $banner = (string) fgets($fp, 512);
    fclose($fp);
    $banner = trim($banner);
    if ($banner !== '' && preg_match('/^2\d\d/', $banner)) {
        return ['ok' => true, 'message' => 'Connected to ' . $host . ':' . $port . '. Banner: ' . substr($banner, 0, 80)];
    }
    if ($banner !== '') {
        return ['ok' => true, 'message' => 'TCP OK on ' . $host . ':' . $port . '. Response: ' . substr($banner, 0, 80)];
    }
    return ['ok' => true, 'message' => 'TCP connected to ' . $host . ':' . $port . ' (no SMTP banner — check encryption/port).'];
}

/**
 * @return array{ok:bool,message:string}
 */
function messaging_test_whatsapp(?array $cfg = null): array
{
    $cfg = $cfg ?? messaging_get_all();
    $provider = strtolower((string) ($cfg['wa_api_provider'] ?? 'meta'));
    $key = trim((string) ($cfg['wa_api_key'] ?? ''));
    $token = trim((string) ($cfg['wa_api_token'] ?? ''));
    $number = preg_replace('/\D+/', '', (string) ($cfg['wa_api_number'] ?? '')) ?? '';
    $endpoint = trim((string) ($cfg['wa_api_endpoint'] ?? ''));

    if ($token === '' && $key === '') {
        return ['ok' => false, 'message' => 'Save an API key or access token before testing.'];
    }
    if ($number === '' && $provider !== 'custom') {
        return ['ok' => false, 'message' => 'WhatsApp number is empty.'];
    }

    if ($endpoint !== '') {
        $probe = messaging_http_probe($endpoint);
        if (!$probe['ok']) {
            return $probe;
        }
        $code = (int) ($probe['http_code'] ?? 0);
        if ($code >= 200 && $code < 500) {
            return ['ok' => true, 'message' => 'Custom endpoint reachable (HTTP ' . $code . '). Credentials not fully verified.'];
        }
        return ['ok' => false, 'message' => 'Custom endpoint returned HTTP ' . $code . '.'];
    }

    if ($provider === 'meta' && $token !== '') {
        $url = 'https://graph.facebook.com/v19.0/me?access_token=' . rawurlencode($token);
        $probe = messaging_http_probe($url);
        if (!$probe['ok']) {
            return $probe;
        }
        $code = (int) ($probe['http_code'] ?? 0);
        $body = (string) ($probe['body'] ?? '');
        if ($code === 200) {
            return ['ok' => true, 'message' => 'Meta token accepted (graph /me OK). Number: ' . $number];
        }
        $snippet = substr(preg_replace('/\s+/', ' ', $body) ?? '', 0, 120);
        return ['ok' => false, 'message' => 'Meta API HTTP ' . $code . ($snippet !== '' ? ': ' . $snippet : '')];
    }

    if ($provider === 'twilio' && $key !== '' && $token !== '') {
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($key) . '.json';
        $probe = messaging_http_probe($url, 8, $key, $token);
        if (!$probe['ok']) {
            return $probe;
        }
        $code = (int) ($probe['http_code'] ?? 0);
        if ($code === 200) {
            return ['ok' => true, 'message' => 'Twilio account credentials OK.'];
        }
        return ['ok' => false, 'message' => 'Twilio auth failed (HTTP ' . $code . ').'];
    }

    return [
        'ok' => true,
        'message' => 'Config looks complete for ' . $provider . ' (number ' . ($number !== '' ? $number : 'n/a') . '). Live send not tested — set a custom endpoint for a deeper probe.',
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function messaging_test_sms(?array $cfg = null): array
{
    $cfg = $cfg ?? messaging_get_all();
    $provider = strtolower((string) ($cfg['sms_api_provider'] ?? 'msg91'));
    $key = trim((string) ($cfg['sms_api_key'] ?? ''));
    $auth = trim((string) ($cfg['sms_api_auth_token'] ?? ''));
    $sender = trim((string) ($cfg['sms_api_sender_id'] ?? ''));
    $endpoint = trim((string) ($cfg['sms_api_endpoint'] ?? ''));

    if ($key === '' && $auth === '') {
        return ['ok' => false, 'message' => 'Save an API key / Account SID before testing.'];
    }

    if ($endpoint !== '') {
        $probe = messaging_http_probe($endpoint);
        if (!$probe['ok']) {
            return $probe;
        }
        $code = (int) ($probe['http_code'] ?? 0);
        if ($code >= 200 && $code < 500) {
            return ['ok' => true, 'message' => 'SMS endpoint reachable (HTTP ' . $code . '). Sender: ' . ($sender !== '' ? $sender : 'n/a')];
        }
        return ['ok' => false, 'message' => 'SMS endpoint returned HTTP ' . $code . '.'];
    }

    if ($provider === 'twilio' && $key !== '' && $auth !== '') {
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($key) . '.json';
        $probe = messaging_http_probe($url, 8, $key, $auth);
        if (!$probe['ok']) {
            return $probe;
        }
        $code = (int) ($probe['http_code'] ?? 0);
        if ($code === 200) {
            return ['ok' => true, 'message' => 'Twilio SMS credentials OK. Sender: ' . ($sender !== '' ? $sender : 'n/a')];
        }
        return ['ok' => false, 'message' => 'Twilio auth failed (HTTP ' . $code . ').'];
    }

    if ($sender === '' && $provider !== 'twilio') {
        return ['ok' => false, 'message' => 'Sender ID is empty. Save sender ID, then test again.'];
    }

    return [
        'ok' => true,
        'message' => 'Config looks complete for ' . strtoupper($provider) . ' (sender ' . ($sender !== '' ? $sender : 'n/a') . '). Live SMS not sent — add a custom endpoint for a reachability probe.',
    ];
}
