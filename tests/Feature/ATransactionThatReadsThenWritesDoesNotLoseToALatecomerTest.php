<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/**
 * @link ../../.docs/architecture/sqlite-write-locks.md
 */

// Two connections onto one file, which is the whole point: `:memory:` gives
// each connection a database of its own, so no lock is ever contended and the
// hazard cannot be reproduced there.
function lockProbeFile(): string
{
    $path = sys_get_temp_dir().'/beatrax-lock-probe-'.bin2hex(random_bytes(6)).'.sqlite';
    touch($path);

    return $path;
}

/** @return array{0: string, 1: string} */
function lockProbeConnections(string $file, string $transactionMode): array
{
    $shared = [
        'driver' => 'sqlite',
        'database' => $file,
        'prefix' => '',
        'foreign_key_constraints' => false,
        'journal_mode' => 'WAL',
        'synchronous' => 'NORMAL',
        // Zero, so the test measures which transaction is REFUSED rather than
        // how long the other one is prepared to wait. With the real thirty
        // seconds the losing side blocks and the run takes thirty seconds.
        'busy_timeout' => 0,
        'transaction_mode' => $transactionMode,
    ];

    $first = 'lock_probe_first_'.bin2hex(random_bytes(4));
    $second = 'lock_probe_second_'.bin2hex(random_bytes(4));

    config(["database.connections.{$first}" => $shared]);
    config(["database.connections.{$second}" => $shared]);

    return [$first, $second];
}

// Runs the shape that lost a queued job: one transaction reads, a second
// connection commits a write, and then the first transaction writes.
/**
 * @return string|null the connection that was refused, or null if both got through
 */
function whoLostTheRace(DatabaseManager $db, string $transactionMode): ?string
{
    $file = lockProbeFile();
    [$firstName, $secondName] = lockProbeConnections($file, $transactionMode);

    $first = $db->connection($firstName);
    $second = $db->connection($secondName);

    $first->statement('CREATE TABLE probe (id INTEGER PRIMARY KEY, note TEXT)');

    $loser = null;

    $first->beginTransaction();

    try {
        $first->select('SELECT count(*) AS n FROM probe');

        try {
            $second->statement("INSERT INTO probe (note) VALUES ('latecomer')");
        } catch (QueryException) {
            $loser = 'second';
        }

        try {
            $first->statement("INSERT INTO probe (note) VALUES ('reader-then-writer')");
        } catch (QueryException) {
            $loser = 'first';
        }

        $first->commit();
    } catch (Throwable) {
        $first->rollBack();
    } finally {
        $db->purge($firstName);
        $db->purge($secondName);
        @unlink($file);
        @unlink($file.'-wal');
        @unlink($file.'-shm');
    }

    return $loser;
}

// The failure this is the guard for: a forecast job was lost to
// "database is locked" raised inside markJobAsReserved, on an idle queue, with
// attempts:1 against $tries = 3. DatabaseQueue::pop() reads the next available
// job and then updates it inside one transaction, which is exactly the shape
// below — and under DEFERRED the transaction that started FIRST is the one
// SQLite refuses, immediately, whatever busy_timeout says.
it('refuses the transaction that started first when transactions are deferred', function (): void {
    expect(whoLostTheRace($this->app->make(DatabaseManager::class), 'DEFERRED'))->toBe('first');
});

it('lets the transaction that started first finish, under the mode the app configures', function (): void {
    expect(whoLostTheRace($this->app->make(DatabaseManager::class), 'IMMEDIATE'))->toBe('second');
});

it('configures the writable connection for the mode that keeps the reservation', function (): void {
    expect(config('database.connections.sqlite.transaction_mode'))->toBe('IMMEDIATE')
        ->and(config('database.connections.readonly_select.transaction_mode'))->toBe('DEFERRED');
});
