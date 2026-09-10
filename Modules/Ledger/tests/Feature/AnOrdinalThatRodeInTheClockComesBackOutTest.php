<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Services\FingerprintComposer;

// Rows the migration importer promoted before the ordinal had a column carry it
// as seconds added to booked_at. That offset wrapped at 86,400 rows of one kind
// and put a time of day on every row its export never stated.

const CLOCK_ORDINAL_MIGRATION = 'Modules/Ledger/Database/Migrations/2026_09_10_000012_take_the_ordinal_back_out_of_the_clock.php';

function clockOrdinalDb(): Connection
{
    return app(DatabaseManager::class)->connection();
}

function clockOrdinalUser(string $username): int
{
    return (int) User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ])->id;
}

/**
 * Writes a row the way the promoter used to: the ordinal added to booked_at as
 * seconds, and occurrence_ordinal left at its default of 0.
 */
function clockOrdinalRow(int $userId, int $offsetSeconds, string $sourceFormat = 'migration_ynab'): int
{
    $db = clockOrdinalDb();

    $accountId = (int) $db->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Promoted account',
        'slug' => 'promoted-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = (int) $db->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => $sourceFormat,
        'raw_file_path' => '/tmp/clock-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'clock-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (int) $db->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'stale-'.$offsetSeconds.'-'.bin2hex(random_bytes(6))),
        'fingerprint_version' => 4,
        'posted_at' => '2026-01-15',
        'booked_at' => '2026-01-15 00:00:'.str_pad((string) $offsetSeconds, 2, '0', STR_PAD_LEFT),
        'value_date' => '2026-01-15',
        'type' => 'expense',
        'amount_minor' => -4990,
        'currency' => 'EUR',
        'settled_amount_minor' => -4990,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 4,
        'description' => 'Groceries',
        'source_format' => $sourceFormat,
        'source_row_index' => $offsetSeconds,
        'occurrence_ordinal' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function runClockOrdinalMigration(): void
{
    $migration = require base_path(CLOCK_ORDINAL_MIGRATION);
    $migration->up();
}

it('moves the offset out of booked_at and into the ordinal column', function (): void {
    $userId = clockOrdinalUser('clock-ordinal-'.bin2hex(random_bytes(3)));
    $second = clockOrdinalRow($userId, 1);

    // The denominator: this is what the old promoter wrote.
    $before = clockOrdinalDb()->table('transactions')->where('id', $second)->first();
    expect($before->booked_at)->toBe('2026-01-15 00:00:01');
    expect((int) $before->occurrence_ordinal)->toBe(0);

    runClockOrdinalMigration();

    $after = clockOrdinalDb()->table('transactions')->where('id', $second)->first();
    expect($after->booked_at)->toBe('2026-01-15 00:00:00');
    expect((int) $after->occurrence_ordinal)->toBe(1);
})->group('ClockOrdinal');

it('rewrites the fingerprint to the tuple the row now has', function (): void {
    $userId = clockOrdinalUser('clock-fp-'.bin2hex(random_bytes(3)));
    $id = clockOrdinalRow($userId, 2);
    $stale = clockOrdinalDb()->table('transactions')->where('id', $id)->value('fingerprint');

    runClockOrdinalMigration();

    $row = clockOrdinalDb()->table('transactions')->where('id', $id)->first();
    $expected = app(FingerprintComposer::class)->composeTuple(new FingerprintTuple(
        $userId,
        (int) $row->account_id,
        '2026-01-15',
        '2026-01-15 00:00:00',
        -4990,
        'EUR',
        'albert heijn',
        2,
    ));

    expect($row->fingerprint)->toBe($expected);
    expect($row->fingerprint)->not->toBe($stale);
})->group('ClockOrdinal');

it('leaves a row no promoter wrote exactly as it found it', function (): void {
    // The control. An offset booked_at on a bank import is a real time of day
    // the statement gave, not an ordinal in disguise.
    $userId = clockOrdinalUser('clock-control-'.bin2hex(random_bytes(3)));
    $id = clockOrdinalRow($userId, 7, 'asn-csv');
    $before = clockOrdinalDb()->table('transactions')->where('id', $id)->first();

    runClockOrdinalMigration();

    $after = clockOrdinalDb()->table('transactions')->where('id', $id)->first();
    expect($after->booked_at)->toBe($before->booked_at);
    expect((int) $after->occurrence_ordinal)->toBe(0);
    expect($after->fingerprint)->toBe($before->fingerprint);
})->group('ClockOrdinal');

it('runs a second time without moving anything again', function (): void {
    $userId = clockOrdinalUser('clock-twice-'.bin2hex(random_bytes(3)));
    $id = clockOrdinalRow($userId, 3);

    runClockOrdinalMigration();
    $once = clockOrdinalDb()->table('transactions')->where('id', $id)->first();

    runClockOrdinalMigration();
    $twice = clockOrdinalDb()->table('transactions')->where('id', $id)->first();

    expect($twice->booked_at)->toBe($once->booked_at);
    expect((int) $twice->occurrence_ordinal)->toBe(3);
    expect($twice->fingerprint)->toBe($once->fingerprint);
})->group('ClockOrdinal');
