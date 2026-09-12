<?php
/**
 * Operations cron — daily midnight closing + week snapshot.
 *
 * Daily mode (default): closes all pending open pairs once per calendar day (IST).
 * Schedule for 12:00 AM (00:00) Asia/Kolkata every night.
 * Weekly mode: only closes on the configured weekday.
 *
 * HTTP (cPanel / live):
 *   https://YOUR-DOMAIN/cron/weekly-closing.php?key=SECRET
 *
 * CLI (Windows Task Scheduler, every day 00:00 IST):
 *   C:\xampp\php\php.exe "C:\Data\Work\Deepak\Bharay Seva\cron\weekly-closing.php"
 *
 * Live CLI (non-localhost DB credentials):
 *   php cron/weekly-closing.php --live
 *
 * Force closing off-schedule / extra run:
 *   php cron/weekly-closing.php --force
 */
if (PHP_SAPI === 'cli') {
    $live = false;
    foreach ($argv ?? [] as $arg) {
        if ($arg === '--live') {
            $live = true;
        }
        if (str_starts_with((string) $arg, '--host=')) {
            $_SERVER['HTTP_HOST'] = substr((string) $arg, 7);
            $live = false;
        }
    }
    if (empty($_SERVER['HTTP_HOST'])) {
        $_SERVER['HTTP_HOST'] = $live ? 'cron.bharatseva.local' : 'localhost';
    }
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/ops_cycle.php';

ops_ensure_tables($pdo);

$key = '';
$force = false;
if (PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $arg) {
        if ($arg === '--force') {
            $force = true;
        }
        if (str_starts_with((string) $arg, '--key=')) {
            $key = substr((string) $arg, 6);
        }
    }
} else {
    $key = (string) ($_GET['key'] ?? ($_POST['key'] ?? ''));
    $force = ((string) ($_GET['force'] ?? '') === '1');
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
}

$secret = ops_cron_secret();
if ($secret === '' || !hash_equals($secret, $key)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
    // CLI on the app server is trusted; HTTP always needs the key.
}

$result = ops_run_weekly_jobs($pdo, $force);
$line = ($result['ok'] ? 'OK' : 'ERR') . ' ' . ($result['skipped'] ? '[skip-close] ' : '') . $result['message'];
echo $line . "\n";
if (!empty($result['week']['label'])) {
    echo 'Week: ' . $result['week']['label'] . "\n";
}
echo 'Schedule: ' . ops_closing_schedule_label() . ' · Payout: ' . ops_payout_days_label() . "\n";
echo 'Next closing: ' . ops_next_closing_day()->format('Y-m-d H:i:s T') . "\n";
exit($result['ok'] ? 0 : 1);
