<?php
/**
 * Local vs live detection + DB credentials store.
 * install.php / Super Admin settings write local + live separately.
 */

function app_http_host(): string
{
    return (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function app_is_local(): bool
{
    return (bool) preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', app_http_host());
}

function app_env_name(): string
{
    return app_is_local() ? 'local' : 'live';
}

/** @return array{host:string,port:string,name:string,user:string,pass:string} */
function app_db_slot_defaults(string $env): array
{
    if ($env === 'live') {
        return [
            'host' => 'localhost',
            'port' => '3306',
            'name' => 'bharatseva_db',
            'user' => 'bharatseva_db',
            'pass' => '&dT1v!tq4QgdHrc6',
        ];
    }
    return [
        'host' => 'localhost',
        'port' => '3306',
        'name' => 'bharatseva_db',
        'user' => 'root',
        'pass' => '',
    ];
}

/** @return array{mode:string,local:array,live:array} */
function app_db_defaults(): array
{
    return [
        'mode' => 'auto',
        'local' => app_db_slot_defaults('local'),
        'live' => app_db_slot_defaults('live'),
    ];
}

function app_db_credentials_path(): string
{
    return __DIR__ . '/db-credentials.php';
}

/** @return array{host:string,port:string,name:string,user:string,pass:string} */
function app_db_normalize_slot(array $slot, string $env): array
{
    $base = app_db_slot_defaults($env);
    $port = trim((string) ($slot['port'] ?? $base['port']));
    if ($port === '' || !preg_match('/^\d{1,5}$/', $port)) {
        $port = '3306';
    }
    return [
        'host' => (string) ($slot['host'] ?? $base['host']),
        'port' => $port,
        'name' => (string) ($slot['name'] ?? $base['name']),
        'user' => (string) ($slot['user'] ?? $base['user']),
        'pass' => (string) ($slot['pass'] ?? $base['pass']),
    ];
}

/** @return array{mode:string,local:array{host:string,port:string,name:string,user:string,pass:string},live:array{host:string,port:string,name:string,user:string,pass:string}} */
function app_db_credentials(): array
{
    $defaults = app_db_defaults();
    $path = app_db_credentials_path();
    if (!is_file($path)) {
        return $defaults;
    }

    $loaded = include $path;
    if (!is_array($loaded)) {
        return $defaults;
    }

    $mode = strtolower(trim((string) ($loaded['mode'] ?? 'auto')));
    if (!in_array($mode, ['auto', 'online', 'offline'], true)) {
        $mode = 'auto';
    }
    $defaults['mode'] = $mode;

    foreach (['local', 'live'] as $env) {
        if (empty($loaded[$env]) || !is_array($loaded[$env])) {
            continue;
        }
        $defaults[$env] = app_db_normalize_slot($loaded[$env], $env);
    }

    return $defaults;
}

function app_db_connection_mode(): string
{
    return (string) (app_db_credentials()['mode'] ?? 'auto');
}

/**
 * Which credential slot is active for this request.
 * auto → offline on localhost, online on live host.
 * @return 'local'|'live'
 */
function app_db_active_slot(): string
{
    $mode = app_db_connection_mode();
    if ($mode === 'online') {
        return 'live';
    }
    if ($mode === 'offline') {
        return 'local';
    }
    return app_is_local() ? 'local' : 'live';
}

/** @return array{host:string,port:string,name:string,user:string,pass:string} */
function app_db_config_for_current(): array
{
    $all = app_db_credentials();
    $slot = app_db_active_slot();
    return $all[$slot];
}

/**
 * Update one environment only. The other side / mode is left unchanged unless $mode passed.
 *
 * @param array{host?:string,port?:string,name?:string,user?:string,pass?:string} $cfg
 */
function app_write_db_credentials(string $env, array $cfg, ?string $mode = null): void
{
    if (!in_array($env, ['local', 'live'], true)) {
        throw new InvalidArgumentException('Invalid environment.');
    }

    $all = app_db_credentials();
    $merged = array_merge($all[$env], $cfg);
    $all[$env] = app_db_normalize_slot($merged, $env);

    if ($mode !== null) {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['auto', 'online', 'offline'], true)) {
            throw new InvalidArgumentException('Invalid connection mode.');
        }
        $all['mode'] = $mode;
    }

    app_write_db_credentials_all($all);
}

/**
 * @param array{mode?:string,local:array,live:array} $all
 */
function app_write_db_credentials_all(array $all): void
{
    $out = [
        'mode' => (string) ($all['mode'] ?? 'auto'),
        'local' => app_db_normalize_slot($all['local'] ?? [], 'local'),
        'live' => app_db_normalize_slot($all['live'] ?? [], 'live'),
    ];
    if (!in_array($out['mode'], ['auto', 'online', 'offline'], true)) {
        $out['mode'] = 'auto';
    }

    $export = var_export($out, true);
    $php = "<?php\n/** Auto-written by install / Super Admin. Local and live stay separate. */\nreturn {$export};\n";
    $path = app_db_credentials_path();
    if (file_put_contents($path, $php) === false) {
        throw new RuntimeException('Could not write config/db-credentials.php. Allow write permission on the config folder.');
    }
}
