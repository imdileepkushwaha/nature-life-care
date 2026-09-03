<?php
/**
 * Local vs live detection + DB credentials store.
 * install.php writes only the environment it is running on.
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

/** @return array{local: array{host:string,name:string,user:string,pass:string}, live: array{host:string,name:string,user:string,pass:string}} */
function app_db_defaults(): array
{
    return [
        'local' => [
            'host' => 'localhost',
            'name' => 'bharatseva_db',
            'user' => 'root',
            'pass' => '',
        ],
        'live' => [
            'host' => 'localhost',
            'name' => 'bharatseva_db',
            'user' => 'bharatseva_db',
            'pass' => '&dT1v!tq4QgdHrc6',
        ],
    ];
}

function app_db_credentials_path(): string
{
    return __DIR__ . '/db-credentials.php';
}

/** @return array{local: array{host:string,name:string,user:string,pass:string}, live: array{host:string,name:string,user:string,pass:string}} */
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

    foreach (['local', 'live'] as $env) {
        if (empty($loaded[$env]) || !is_array($loaded[$env])) {
            continue;
        }
        $defaults[$env] = [
            'host' => (string) ($loaded[$env]['host'] ?? $defaults[$env]['host']),
            'name' => (string) ($loaded[$env]['name'] ?? $defaults[$env]['name']),
            'user' => (string) ($loaded[$env]['user'] ?? $defaults[$env]['user']),
            'pass' => (string) ($loaded[$env]['pass'] ?? $defaults[$env]['pass']),
        ];
    }

    return $defaults;
}

/** @return array{host:string,name:string,user:string,pass:string} */
function app_db_config_for_current(): array
{
    $all = app_db_credentials();
    return $all[app_env_name()];
}

/**
 * Update one environment only. The other side is left unchanged.
 *
 * @param array{host:string,name:string,user:string,pass:string} $cfg
 */
function app_write_db_credentials(string $env, array $cfg): void
{
    if (!in_array($env, ['local', 'live'], true)) {
        throw new InvalidArgumentException('Invalid environment.');
    }

    $all = app_db_credentials();
    $all[$env] = [
        'host' => (string) $cfg['host'],
        'name' => (string) $cfg['name'],
        'user' => (string) $cfg['user'],
        'pass' => (string) $cfg['pass'],
    ];

    $export = var_export($all, true);
    $php = "<?php\n/** Auto-written by install.php. Local and live stay separate. */\nreturn {$export};\n";
    $path = app_db_credentials_path();
    if (file_put_contents($path, $php) === false) {
        throw new RuntimeException('Could not write config/db-credentials.php. Allow write permission on the config folder.');
    }
}
