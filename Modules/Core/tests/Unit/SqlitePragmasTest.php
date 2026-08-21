<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;

it('applies WAL + synchronous=NORMAL + foreign_keys + busy_timeout pragmas via the connection config keys', function (): void {
    // Use a temp on-disk SQLite file — `:memory:` does not support WAL.
    $tempStub = (string) tempnam(sys_get_temp_dir(), 'beatrax-pragma-');
    $tempDb = $tempStub.'.sqlite';
    @unlink($tempStub);
    touch($tempDb);

    try {
        /** @var Repository $config */
        $config = $this->app->make(Repository::class);
        $config->set('database.connections.sqlite_pragma_test', [
            'driver' => 'sqlite',
            'database' => $tempDb,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'busy_timeout' => 5000,
        ]);

        /** @var DatabaseManager $db */
        $db = $this->app->make(DatabaseManager::class);
        $connection = $db->connection('sqlite_pragma_test');

        // Trigger a connection — pragmas should run on ConnectionEstablished.
        $connection->select('select 1');

        expect(strtolower((string) $connection->scalar('PRAGMA journal_mode')))->toBe('wal');
        expect((int) $connection->scalar('PRAGMA synchronous'))->toBe(1);
        expect((int) $connection->scalar('PRAGMA busy_timeout'))->toBe(5000);
        expect((int) $connection->scalar('PRAGMA foreign_keys'))->toBe(1);
    } finally {
        @unlink($tempDb);
        @unlink($tempDb.'-shm');
        @unlink($tempDb.'-wal');
    }
});

it('applies the same pragmas via the ConnectionEstablished listener even when config keys are absent', function (): void {
    // A connection definition without `journal_mode`/`synchronous`/`busy_timeout`
    // keys proves the SqliteOptimizationsProvider listener fires regardless.
    // Its fallback is thirty seconds: the desktop runs four processes against
    // one file, and five lost whole pairing frames to "database is locked".
    $tempStub = (string) tempnam(sys_get_temp_dir(), 'beatrax-pragma-listener-');
    $tempDb = $tempStub.'.sqlite';
    @unlink($tempStub);
    touch($tempDb);

    try {
        /** @var Repository $config */
        $config = $this->app->make(Repository::class);
        $config->set('database.connections.sqlite_listener_test', [
            'driver' => 'sqlite',
            'database' => $tempDb,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        /** @var DatabaseManager $db */
        $db = $this->app->make(DatabaseManager::class);
        $connection = $db->connection('sqlite_listener_test');
        $connection->select('select 1');

        expect(strtolower((string) $connection->scalar('PRAGMA journal_mode')))->toBe('wal');
        expect((int) $connection->scalar('PRAGMA synchronous'))->toBe(1);
        expect((int) $connection->scalar('PRAGMA busy_timeout'))->toBe(30000);
        expect((int) $connection->scalar('PRAGMA foreign_keys'))->toBe(1);
    } finally {
        @unlink($tempDb);
        @unlink($tempDb.'-shm');
        @unlink($tempDb.'-wal');
    }
});
