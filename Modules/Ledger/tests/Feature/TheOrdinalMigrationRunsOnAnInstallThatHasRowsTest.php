<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// RefreshDatabase migrates an empty database, so the backfill and the index
// rebuild in the occurrence-ordinal migration run over nothing in every other
// test in the tree. Every install that upgrades runs them over rows.

function ordinalMigrationFixture(int $i, ?int $userId, int $accountId, int $runId, array $over = []): array
{
    return array_merge([
        'user_id' => $userId, 'account_id' => $accountId, 'type' => 'expense',
        'posted_at' => '2026-02-03', 'booked_at' => '2026-02-03 00:00:00', 'value_date' => '2026-02-03',
        'amount_minor' => -350, 'currency' => 'EUR',
        'settled_amount_minor' => -350, 'settled_currency' => 'EUR',
        'counterparty_name' => 'Koffiehuis', 'counterparty_normalized' => 'koffiehuis',
        'normalization_version' => 4, 'source_format' => 'asn-csv',
        'import_run_id' => $runId, 'source_row_index' => $i,
        'fingerprint' => str_pad((string) $i, 64, '0', STR_PAD_LEFT), 'fingerprint_version' => 4,
    ], $over);
}

function ordinalMigrationSeed(): array
{
    $db = app('db')->connection();

    $userId = $db->table('users')->insertGetId([
        'username' => 'ordinal-upgrade', 'password' => 'a-genuinely-long-password',
        'period_start_day' => 1, 'default_currency_view' => 'eur_only',
    ]);
    $accountId = $db->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'A', 'slug' => 'ordinal-a', 'kind' => 'bank',
        'default_currency' => 'EUR', 'iban' => 'NL00ORDINAL000001',
    ]);
    $runId = $db->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'status' => 'previewed',
        'raw_file_path' => '/tmp/fixture.csv', 'sha256' => str_repeat('a', 64),
        'uploaded_at' => '2026-02-03 12:00:00',
        'inserted_count' => 0, 'duplicate_count' => 0, 'error_count' => 0,
    ]);

    return [$userId, $accountId, $runId];
}

// Puts the schema back the way an install that has not upgraded holds it.
function ordinalMigrationRewind(): void
{
    $db = app('db')->connection();
    $db->statement('DROP INDEX IF EXISTS transactions_fingerprint_uq');
    $db->statement('ALTER TABLE transactions DROP COLUMN occurrence_ordinal');
    $db->statement(
        'CREATE UNIQUE INDEX transactions_fingerprint_uq ON transactions('
        .'user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized)'
    );
}

function ordinalMigrationRun(): void
{
    $migration = require base_path(
        'Modules/Ledger/Database/Migrations/2026_09_10_000010_count_the_purchases_a_statement_books_twice.php'
    );

    $migration->up();
}

it('numbers what an upgrading install already holds, and loses none of it', function (): void {
    [$userId, $accountId, $runId] = ordinalMigrationSeed();
    $db = app('db')->connection();

    ordinalMigrationRewind();

    // user_id is nullable and SQLite counts NULLs as distinct in a UNIQUE
    // index, so these two sit in one tuple group and the narrow index took
    // both. They are the only rows an upgrade can find already doubled.
    $db->table('transactions')->insert(ordinalMigrationFixture(1, null, $accountId, $runId));
    $db->table('transactions')->insert(ordinalMigrationFixture(2, null, $accountId, $runId));

    // A row whose tuple is its own, and one differing only by amount: both are
    // the first occurrence of themselves and must be numbered 0.
    $db->table('transactions')->insert(ordinalMigrationFixture(3, $userId, $accountId, $runId));
    $db->table('transactions')->insert(ordinalMigrationFixture(4, $userId, $accountId, $runId, ['amount_minor' => -700]));

    ordinalMigrationRun();

    expect($db->table('transactions')->count())->toBe(4, 'the rebuild dropped or merged a row it was handed');

    $ordinals = $db->table('transactions')->orderBy('id')->pluck('occurrence_ordinal')->all();

    expect(array_map('intval', $ordinals))->toBe([0, 1, 0, 0]);
});

it('leaves the widened index in place and still refusing a real repeat', function (): void {
    [$userId, $accountId, $runId] = ordinalMigrationSeed();
    $db = app('db')->connection();

    ordinalMigrationRewind();
    $db->table('transactions')->insert(ordinalMigrationFixture(1, $userId, $accountId, $runId));
    ordinalMigrationRun();

    $sql = (string) $db->selectOne(
        "SELECT sql FROM sqlite_master WHERE type='index' AND name='transactions_fingerprint_uq'"
    )->sql;

    expect($sql)->toContain('occurrence_ordinal');

    // The whole point survives: the same booking twice is still one row, and
    // only a different occurrence number gets past.
    $repeat = ordinalMigrationFixture(9, $userId, $accountId, $runId, ['fingerprint' => str_repeat('c', 64)]);

    expect(fn () => $db->table('transactions')->insert($repeat))
        ->toThrow(QueryException::class);

    $second = $repeat;
    $second['occurrence_ordinal'] = 1;
    $db->table('transactions')->insert($second);

    expect($db->table('transactions')->count())->toBe(2);
});

// The column's default is what a create from a peer still on the previous build
// arrives without. SQLite compares across storage classes, so a default stored
// as text would never equal a locally written integer 0 and the two devices
// would each keep their own copy of one purchase.
it('defaults the ordinal to an integer, not the text an unquoted default would store', function (): void {
    [$userId, $accountId, $runId] = ordinalMigrationSeed();
    $db = app('db')->connection();

    $db->table('transactions')->insert(ordinalMigrationFixture(1, $userId, $accountId, $runId));

    $stored = $db->selectOne('SELECT typeof(occurrence_ordinal) AS class FROM transactions');

    expect($stored->class)->toBe('integer');

    $named = ordinalMigrationFixture(2, $userId, $accountId, $runId, [
        'occurrence_ordinal' => 0,
        'fingerprint' => str_repeat('c', 64),
    ]);

    expect(fn () => $db->table('transactions')->insert($named))
        ->toThrow(QueryException::class, '', 'a named 0 has to be the same value as a defaulted one');
});
