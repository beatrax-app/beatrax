<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\OpLogReplayer;

uses(RefreshDatabase::class);

// The decoder that preceded this one guessed at the type and silently
// corrupted "null", "007", "1e3" and "false". Everything on the wire is
// JSON, and SQL NULL in the value column is reserved for the tombstone
// sentinel — never the JSON literal null.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  mixed  $phpValue  The PHP value to round-trip through always-JSON.
 */
function insertOpLogEntry(DatabaseManager $db, int $userId, mixed $phpValue): ?object
{
    $jsonValue = $phpValue !== null ? json_encode($phpValue, JSON_THROW_ON_ERROR) : null;

    $id = $db->connection()->table('op_log_entries')->insertGetId([
        'user_id' => $userId,
        'device_id' => 'device-wire',
        'table_name' => 'transactions',
        'pk' => '1',
        'field' => 'note',
        'op_type' => 'set',
        'value' => $jsonValue,
        'hlc_l' => 1000,
        'hlc_c' => 0,
        'signature' => str_repeat('aa', 32),
        'recorded_at' => '2026-06-15 10:00:00',
    ]);

    return $db->connection()
        ->table('op_log_entries')
        ->where('id', $id)
        ->first();
}

it('round-trip: string "null" stays string "null" (not PHP null)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'wire-contract-u1',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $row = insertOpLogEntry($db, $userId, 'null');

    expect($row->value)->toBe('"null"');

    $replayer = new OpLogReplayer($db, []);
    expect($replayer->decodeValue($row->value))->toBe('null');
});

it('round-trip: string "007" stays string "007" (not int 7)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'wire-contract-u2',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $row = insertOpLogEntry($db, $userId, '007');

    expect($row->value)->toBe('"007"');

    $replayer = new OpLogReplayer($db, []);
    expect($replayer->decodeValue($row->value))->toBe('007');
});

it('round-trip: string "1e3" stays string "1e3" (not float 1000.0)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'wire-contract-u3',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $row = insertOpLogEntry($db, $userId, '1e3');

    expect($row->value)->toBe('"1e3"');

    $replayer = new OpLogReplayer($db, []);
    expect($replayer->decodeValue($row->value))->toBe('1e3');
});

it('round-trip: string "false" stays string "false" (not PHP false)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'wire-contract-u4',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $row = insertOpLogEntry($db, $userId, 'false');

    expect($row->value)->toBe('"false"');

    $replayer = new OpLogReplayer($db, []);
    expect($replayer->decodeValue($row->value))->toBe('false');
});

it('round-trip: empty string stays empty string', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'wire-contract-u5',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $row = insertOpLogEntry($db, $userId, '');

    expect($row->value)->toBe('""');

    $replayer = new OpLogReplayer($db, []);
    expect($replayer->decodeValue($row->value))->toBe('');
});

it('round-trip: integer 42 stays int 42', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'wire-contract-u6',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $row = insertOpLogEntry($db, $userId, 42);

    expect($row->value)->toBe('42');

    $replayer = new OpLogReplayer($db, []);
    expect($replayer->decodeValue($row->value))->toBe(42);
});

it('PHP null in value column is the clear/tombstone sentinel — not the JSON literal "null"', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'wire-contract-u7',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $id = $db->connection()->table('op_log_entries')->insertGetId([
        'user_id' => $userId,
        'device_id' => 'device-wire',
        'table_name' => 'transactions',
        'pk' => '1',
        'field' => 'note',
        'op_type' => 'set',
        'value' => null,
        'hlc_l' => 1000,
        'hlc_c' => 0,
        'signature' => str_repeat('aa', 32),
        'recorded_at' => '2026-06-15 10:00:00',
    ]);

    $row = $db->connection()->table('op_log_entries')->where('id', $id)->first();
    expect($row->value)->toBeNull();

    $replayer = new OpLogReplayer($db, []);
    expect($replayer->decodeValue(null))->toBeNull();
});
