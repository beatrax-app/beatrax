<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

// Fallback host for every server connection whose DB_* / REDIS_* var is
// unset; a self-hosted server overrides them explicitly.
$loopbackHost = '127.0.0.1';

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | `sqlite` runs WAL with `synchronous=NORMAL` so a background worker can
    | read while the foreground request writes. `sqlite_testing` omits the
    | pragma keys because WAL does not apply to `:memory:`.
    */

    // A local variable, not a config() lookup, because this is evaluated
    // during config load — `readonly_select` below clones it.
    'connections' => (static function () use ($loopbackHost): array {
        $sqlite = [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', UserDataPathService::databaseFile()),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            // A sync catch-up holds one write transaction for longer than
            // the old 5s allowed, so job reservation failed outright with
            // "database is locked" rather than waiting its turn.
            'busy_timeout' => 30000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ];

        return [
            'sqlite' => $sqlite,

            'sqlite_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],

            /**
             * @link ../.docs/features/dev-mode/architecture.md#livewire-page-notes
             */
            // Nothing here makes it read-only — this entry only carves out
            // the named slot. ReadOnlySqliteConnection applies
            // `PRAGMA query_only = 1` per-PDO, which is what rejects DDL/DML
            // at the SQLite layer instead of by application filtering.
            'readonly_select' => array_merge($sqlite, [
                'name' => 'readonly_select',
            ]),

            /**
             * @link ../.docs/deployment.md
             */
            'pgsql' => [
                'driver' => 'pgsql',
                'url' => env('DB_URL'),
                'host' => env('DB_HOST', $loopbackHost),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'beatrax'),
                'username' => env('DB_USERNAME', 'beatrax'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => env('DB_CHARSET', 'utf8'),
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => env('DB_SEARCH_PATH', 'public'),
                'sslmode' => env('DB_SSLMODE', 'prefer'),
            ],

            'mysql' => [
                'driver' => 'mysql',
                'url' => env('DB_URL'),
                'host' => env('DB_HOST', $loopbackHost),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'beatrax'),
                'username' => env('DB_USERNAME', 'beatrax'),
                'password' => env('DB_PASSWORD', ''),
                'unix_socket' => env('DB_SOCKET', ''),
                'charset' => env('DB_CHARSET', 'utf8mb4'),
                'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
            ],

            'mariadb' => [
                'driver' => 'mariadb',
                'url' => env('DB_URL'),
                'host' => env('DB_HOST', $loopbackHost),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'beatrax'),
                'username' => env('DB_USERNAME', 'beatrax'),
                'password' => env('DB_PASSWORD', ''),
                'unix_socket' => env('DB_SOCKET', ''),
                'charset' => env('DB_CHARSET', 'utf8mb4'),
                'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
            ],
        ];
    })(),

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis backs the queue and cache stores. The project pins the pure-PHP
    | `predis/predis` client — no PECL `phpredis` build dependency. The
    | container runs locally and is bound to `127.0.0.1` only; the
    | password stays null because the listener is not reachable beyond
    | loopback.
    */

    'redis' => [
        'client' => env('REDIS_CLIENT', 'predis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', 'beatrax_database_'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', $loopbackHost),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', $loopbackHost),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],

];
