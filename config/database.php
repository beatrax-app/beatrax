<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\SqliteDatabase;

// Fallback host for a Redis connection whose REDIS_* var is unset. The
// database has no host: SQLite is the only engine, in every shape.
$loopbackHost = '127.0.0.1';

return [

    'default' => env('DB_CONNECTION', 'sqlite'),

    // WAL with `synchronous=NORMAL` lets a background worker read during a
    // foreground write. `sqlite_testing` omits it: WAL is moot in `:memory:`.

    // A local, not config(): this runs during config load.
    'connections' => (static function (): array {
        $sqlite = [
            'driver' => SqliteDatabase::DRIVER,
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', UserDataPathService::databaseFile()),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            // A sync catch-up holds a write transaction past the old 5s, so
            // job reservation failed with "database is locked" instead of
            // waiting its turn.
            'busy_timeout' => 30000,
            // And busy_timeout alone does not cover the case that lost a
            // forecast job: a DEFERRED transaction that reads and then writes
            // is refused OUTRIGHT if anyone wrote in between, because retrying
            // it could deadlock. Taking the write lock at BEGIN makes the wait
            // a wait. See .docs/architecture/sqlite-write-locks.md.
            'transaction_mode' => 'IMMEDIATE',
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ];

        return [
            'sqlite' => $sqlite,

            'sqlite_testing' => [
                'driver' => SqliteDatabase::DRIVER,
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],

            /**
             * @link ../.docs/features/dev-mode/architecture.md#livewire-page-notes
             */
            // This entry only carves out the named slot. The read-only part is
            // ReadOnlySqliteConnection's per-PDO `PRAGMA query_only = 1`,
            // which rejects DDL/DML in SQLite rather than in application code.
            'readonly_select' => array_merge($sqlite, [
                'name' => 'readonly_select',
                // Never IMMEDIATE here: this connection is query_only, and
                // asking for the write lock it can never use would queue it
                // behind every writer to read a table.
                'transaction_mode' => 'DEFERRED',
            ]),
        ];
    })(),

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    // Pure-PHP predis is pinned so no PECL `phpredis` build dependency enters
    // the tree. The password is null: the listener is loopback-only.

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
