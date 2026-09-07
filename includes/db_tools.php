<?php
/**
 * Super Admin database info + SQL backup/restore helpers.
 */

function db_tools_backup_dir(): string
{
    $dir = BASE_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    $idx = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($idx)) {
        @file_put_contents($idx, '');
    }
    return $dir;
}

function db_tools_set_meta(PDO $pdo, string $key, string $value): void
{
    try {
        $pdo->prepare('
            INSERT INTO settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ')->execute([$key, $value]);
        if (function_exists('clear_setting_cache')) {
            clear_setting_cache($key);
        }
    } catch (Throwable $e) {
        // ignore if settings table missing mid-setup
    }
}

function db_tools_meta(string $key, string $default = ''): string
{
    return function_exists('setting') ? setting($key, $default) : $default;
}

/** @return array{ok:bool,host:string,port:string,name:string,user:string,pass:string,version:string,tables:int,size_mb:float,env:string,slot:string,mode:string,slot_label:string,error?:string} */
function db_tools_info(PDO $pdo): array
{
    $cfg = app_db_config_for_current();
    $slot = app_db_active_slot();
    $info = [
        'ok' => true,
        'host' => (string) ($cfg['host'] ?? ''),
        'port' => (string) ($cfg['port'] ?? '3306'),
        'name' => (string) ($cfg['name'] ?? ''),
        'user' => (string) ($cfg['user'] ?? ''),
        'pass' => (string) ($cfg['pass'] ?? ''),
        'version' => '',
        'tables' => 0,
        'size_mb' => 0.0,
        'env' => app_env_name(),
        'slot' => $slot,
        'mode' => app_db_connection_mode(),
        'slot_label' => $slot === 'live' ? 'Online' : 'Offline',
    ];
    try {
        $info['version'] = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $dbName = (string) ($cfg['name'] ?? '');
        $st = $pdo->prepare("
            SELECT COUNT(*) AS tbls,
                   ROUND(COALESCE(SUM(data_length + index_length), 0) / 1024 / 1024, 2) AS size_mb
            FROM information_schema.TABLES
            WHERE table_schema = ?
        ");
        $st->execute([$dbName]);
        $row = $st->fetch() ?: [];
        $info['tables'] = (int) ($row['tbls'] ?? 0);
        $info['size_mb'] = (float) ($row['size_mb'] ?? 0);
    } catch (Throwable $e) {
        $info['ok'] = false;
        $info['error'] = $e->getMessage();
    }
    return $info;
}

function db_tools_mask_pass(string $pass): string
{
    if ($pass === '') {
        return '(empty)';
    }
    $len = strlen($pass);
    if ($len <= 4) {
        return str_repeat('*', $len);
    }
    return substr($pass, 0, 2) . str_repeat('*', max(4, $len - 4)) . substr($pass, -1);
}

function db_tools_format_meta_time(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return 'Never';
    }
    $ts = strtotime($raw);
    return $ts ? date('d M Y H:i', $ts) : 'Never';
}

/**
 * @return array{ok:bool,message:string,version?:string}
 */
function db_tools_test_connection(string $host, string $name, string $user, string $pass, string $port = '3306'): array
{
    $port = trim($port) !== '' ? trim($port) : '3306';
    try {
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
        $test = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $ver = (string) $test->query('SELECT VERSION()')->fetchColumn();
        return ['ok' => true, 'message' => 'Connection OK (MySQL ' . $ver . ').', 'version' => $ver];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
    }
}

/**
 * Parse one credential block from POST (online|offline).
 * @return array{ok:bool,message?:string,cfg?:array{host:string,port:string,name:string,user:string,pass:string}}
 */
function db_tools_parse_slot_post(array $post, string $prefix, array $current): array
{
    $host = trim((string) ($post[$prefix . '_host'] ?? ''));
    $port = trim((string) ($post[$prefix . '_port'] ?? '3306'));
    $name = trim((string) ($post[$prefix . '_name'] ?? ''));
    $user = trim((string) ($post[$prefix . '_user'] ?? ''));
    $passPosted = (string) ($post[$prefix . '_pass'] ?? '');

    if ($host === '' || $name === '' || $user === '') {
        return ['ok' => false, 'message' => ucfirst($prefix) . ': host, database and username are required.'];
    }
    if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $host)) {
        return ['ok' => false, 'message' => ucfirst($prefix) . ': invalid host.'];
    }
    if ($port === '' || !preg_match('/^\d{1,5}$/', $port) || (int) $port > 65535) {
        return ['ok' => false, 'message' => ucfirst($prefix) . ': invalid port.'];
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $name) || !preg_match('/^[a-zA-Z0-9_]+$/', $user)) {
        return ['ok' => false, 'message' => ucfirst($prefix) . ': database/username may only use letters, numbers, underscore.'];
    }

    $pass = trim($passPosted) === '' ? (string) ($current['pass'] ?? '') : $passPosted;

    return [
        'ok' => true,
        'cfg' => [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
        ],
    ];
}

/**
 * Save online + offline credentials and connection mode.
 * @return array{ok:bool,message:string}
 */
function db_tools_save_dual_credentials(array $post): array
{
    $mode = strtolower(trim((string) ($post['db_mode'] ?? 'auto')));
    if (!in_array($mode, ['auto', 'online', 'offline'], true)) {
        return ['ok' => false, 'message' => 'Invalid connection mode.'];
    }

    $all = app_db_credentials();
    $online = db_tools_parse_slot_post($post, 'online', $all['live']);
    if (!$online['ok']) {
        return $online;
    }
    $offline = db_tools_parse_slot_post($post, 'offline', $all['local']);
    if (!$offline['ok']) {
        return $offline;
    }

    $testOnline = db_tools_test_connection(
        $online['cfg']['host'],
        $online['cfg']['name'],
        $online['cfg']['user'],
        $online['cfg']['pass'],
        $online['cfg']['port']
    );
    $testOffline = db_tools_test_connection(
        $offline['cfg']['host'],
        $offline['cfg']['name'],
        $offline['cfg']['user'],
        $offline['cfg']['pass'],
        $offline['cfg']['port']
    );

    // Require the slot that will become active to connect; warn-only for the other if it fails.
    $activeWillBe = $mode === 'online' ? 'live' : ($mode === 'offline' ? 'local' : (app_is_local() ? 'local' : 'live'));
    if ($activeWillBe === 'live' && !$testOnline['ok']) {
        return ['ok' => false, 'message' => 'Online DB (active): ' . $testOnline['message']];
    }
    if ($activeWillBe === 'local' && !$testOffline['ok']) {
        return ['ok' => false, 'message' => 'Offline DB (active): ' . $testOffline['message']];
    }

    try {
        app_write_db_credentials_all([
            'mode' => $mode,
            'live' => $online['cfg'],
            'local' => $offline['cfg'],
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    $notes = [];
    if (!$testOnline['ok']) {
        $notes[] = 'Online test failed (saved anyway; not active now).';
    }
    if (!$testOffline['ok']) {
        $notes[] = 'Offline test failed (saved anyway; not active now).';
    }

    $msg = 'Database settings saved (mode: ' . $mode . ').';
    if ($notes) {
        $msg .= ' ' . implode(' ', $notes);
    }
    $msg .= ' Reload the page if the active connection changed.';
    return ['ok' => true, 'message' => $msg];
}

/**
 * @return array{ok:bool,message:string}
 */
function db_tools_run_setup(PDO $pdo): array
{
    try {
        require_once __DIR__ . '/schema_setup.php';
        $setup = mlm_run_schema_setup($pdo);
        return [
            'ok' => true,
            'message' => (string) ($setup['message'] ?? 'Database setup complete.'),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Setup failed: ' . $e->getMessage()];
    }
}

function db_tools_safe_backup_name(string $name): ?string
{
    $name = basename(str_replace(['\\', '/'], '', $name));
    if (preg_match('/^[a-zA-Z0-9_\-]+-backup-\d{8}-\d{6}\.sql$/', $name)) {
        return $name;
    }
    if (preg_match('/^backup_[a-zA-Z0-9_\-]+\.sql$/', $name)) {
        return $name;
    }
    return null;
}

function db_tools_backup_path(string $name): ?string
{
    $safe = db_tools_safe_backup_name($name);
    if ($safe === null) {
        return null;
    }
    $dir = db_tools_backup_dir();
    $path = $dir . DIRECTORY_SEPARATOR . $safe;
    $realDir = realpath($dir);
    if ($realDir === false) {
        return null;
    }
    $realFile = realpath($path);
    if ($realFile !== false && str_starts_with($realFile, $realDir) && is_file($realFile)) {
        return $realFile;
    }
    if ($realFile === false) {
        return $realDir . DIRECTORY_SEPARATOR . $safe;
    }
    return null;
}

/**
 * @return array{ok:bool,message:string,file?:string,path?:string}
 */
function db_tools_create_backup(PDO $pdo): array
{
    @set_time_limit(300);
    $dbName = DB_NAME;
    $stamp = date('Ymd-His');
    $safeDb = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $dbName) ?: 'db';
    $fileName = $safeDb . '-backup-' . $stamp . '.sql';
    $path = db_tools_backup_dir() . DIRECTORY_SEPARATOR . $fileName;

    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            return ['ok' => false, 'message' => 'Could not create backup file. Check storage/backups write permission.'];
        }

        $header = "-- Bharay Seva SQL backup\n"
            . '-- Generated: ' . date('c') . "\n"
            . '-- Database: ' . $dbName . "\n"
            . '-- Env: ' . app_env_name() . "\n\n"
            . "SET NAMES utf8mb4;\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n"
            . "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
        fwrite($fh, $header);

        foreach ($tables as $table) {
            $table = (string) $table;
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM);
            if (!$create) {
                continue;
            }
            fwrite($fh, "-- Table `{$table}`\n");
            fwrite($fh, 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . "`;\n");
            fwrite($fh, $create[1] . ";\n\n");

            $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
            $batch = [];
            $cols = null;
            $colSql = '';
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) {
                    $cols = array_keys($row);
                    $colSql = implode(',', array_map(
                        static fn ($c) => '`' . str_replace('`', '``', (string) $c) . '`',
                        $cols
                    ));
                }
                $vals = [];
                foreach ($cols as $c) {
                    $v = $row[$c];
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } else {
                        $vals[] = $pdo->quote((string) $v);
                    }
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 80) {
                    fwrite($fh, 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . $colSql . ') VALUES' . "\n"
                        . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch && $cols !== null) {
                fwrite($fh, 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . $colSql . ') VALUES' . "\n"
                    . implode(",\n", $batch) . ";\n");
            }
            fwrite($fh, "\n");
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        $size = @filesize($path) ?: 0;
        return [
            'ok' => true,
            'message' => 'Backup ready: ' . $fileName . ' (' . round($size / 1024, 1) . ' KB).',
            'file' => $fileName,
            'path' => $path,
        ];
    } catch (Throwable $e) {
        if (isset($fh) && is_resource($fh)) {
            fclose($fh);
        }
        if (is_file($path)) {
            @unlink($path);
        }
        return ['ok' => false, 'message' => 'Backup failed: ' . $e->getMessage()];
    }
}

/** Create backup, stamp last download, stream file (exits). */
function db_tools_download_now(PDO $pdo): void
{
    $result = db_tools_create_backup($pdo);
    if (!$result['ok'] || empty($result['file'])) {
        flash('error', $result['message'] ?? 'Backup failed.');
        header('Location: settings.php?tab=backup');
        exit;
    }
    db_tools_set_meta($pdo, 'db_backup_last_download', date('Y-m-d H:i:s'));
    db_tools_download_backup((string) $result['file']);
}

function db_tools_download_backup(string $name): void
{
    $path = db_tools_backup_path($name);
    if ($path === null || !is_file($path)) {
        flash('error', 'Backup file not found.');
        header('Location: settings.php?tab=backup');
        exit;
    }
    $file = basename($path);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/**
 * @return array{ok:bool,message:string}
 */
function db_tools_restore_from_path(PDO $pdo, string $path): array
{
    @set_time_limit(600);
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        return ['ok' => false, 'message' => 'Backup file is empty or unreadable.'];
    }

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $statements = db_tools_split_sql($sql);
        $ran = 0;
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
            $ran++;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        db_tools_set_meta($pdo, 'db_backup_last_restore', date('Y-m-d H:i:s'));
        return [
            'ok' => true,
            'message' => 'Database restored (' . $ran . ' statements). You may need to sign in again.',
        ];
    } catch (Throwable $e) {
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $ignored) {
        }
        return ['ok' => false, 'message' => 'Restore failed: ' . $e->getMessage()];
    }
}

/**
 * Restore from uploaded .sql (max 32 MB).
 * @param array<string,mixed> $file
 * @return array{ok:bool,message:string}
 */
function db_tools_restore_upload(PDO $pdo, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'Choose a .sql backup file to restore.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Upload failed. Try again.'];
    }
    $max = 32 * 1024 * 1024;
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > $max) {
        return ['ok' => false, 'message' => 'Backup must be a .sql file up to 32 MB.'];
    }
    $name = (string) ($file['name'] ?? '');
    if (!preg_match('/\.sql$/i', $name)) {
        return ['ok' => false, 'message' => 'Only .sql backup files are allowed.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Invalid upload.'];
    }

    $dest = db_tools_backup_dir() . DIRECTORY_SEPARATOR . 'upload-restore-' . date('Ymd-His') . '.sql';
    if (!@move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'message' => 'Could not store uploaded backup.'];
    }

    $result = db_tools_restore_from_path($pdo, $dest);
    @unlink($dest);
    return $result;
}

/**
 * @return list<string>
 */
function db_tools_split_sql(string $sql): array
{
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $lines = explode("\n", $sql);
    $buffer = '';
    $out = [];
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) {
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
            $buffer = '';
        }
    }
    $tail = trim($buffer);
    if ($tail !== '') {
        $out[] = $tail;
    }
    return $out;
}
