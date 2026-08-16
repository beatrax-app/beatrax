<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

// Loopback default shared by every server-connection host that falls
// back when its DB_* / REDIS_* env var is unset — a self-hosted server
// overrides these explicitly.
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
    | The on-disk `sqlite` connection is the production-shaped local store
    | and enables WAL with `synchronous=NORMAL` so a long-running background
    | worker can read while the foreground request writes. The
    | `sqlite_testing` connection runs against an in-memory database for
    | parallel test runs; WAL is not applicable to `:memory:` so the pragma
    | keys are omitted.
    */

    /*
     * The on-disk `sqlite` shape is reused for the `readonly_select`
     * sibling — pulled into a local variable so both entries below
     * point at the same database file and PRAGMA defaults without a
     * config() lookup during config-load time.
     */
    'connections' => (static function () use ($loopbackHost): array {
        $sqlite = [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', UserDataPathService::databaseFile()),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            // A catch-up applies thousands of ops inside one write
            // transaction while the queue worker and both sync daemons write
            // to the same file. Five seconds was shorter than that
            // transaction, so job reservation failed with "database is
            // locked" instead of simply waiting its turn.
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

            /*
             * Read-only SELECT-only sibling connection for the Dev
             * Console SQL panel. Cloned from the on-disk `sqlite`
             * connection so the database file, foreign-key
             * behaviour, and busy-timeout match; the consumer is
             * responsible for opening the underlying PDO with
             * PRAGMA query_only = 1 so any DDL/DML attempt is
             * rejected at the SQLite layer rather than relying on
             * application-level filtering alone. The
             * ReadOnlySqliteConnection service enforces that PRAGMA
             * per-PDO; this entry only carves out the named slot.
             * Under the testing environment
             * (DB_CONNECTION=sqlite_testing + in-memory :memory:
             * database) the service routes the SELECT through the
             * default in-memory connection (separate connections to
             * :memory: are isolated); the PRAGMA is armed + reset
             * per-execute so writes on the same PDO proceed after
             * the read.
             */
            'readonly_select' => array_merge($sqlite, [
                'name' => 'readonly_select',
            ]),

            /*
             * Server-deployment connections. SQLite stays the default for the
             * single-user desktop build; a self-hosted server sets
             * DB_CONNECTION=pgsql (or mysql / mariadb) plus the DB_* vars to
             * point at a real database. See the deployment docs.
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
