<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

/*
 * SYNC-03 / SE-07: Always-JSON wire contract.
 *
 * Proves that round-trip through json_encode → op_log_entries.value →
 * productionized OpLogReplayer::decodeValue yields the SAME PHP type and
 * value for each ambiguous input — fixing the SE-07 spike heuristic that
 * silently corrupted "null", "007", "1e3", and "false".
 *
 * Also asserts that PHP null (SQL NULL in value column) is treated
 * exclusively as the clear/tombstone sentinel — it is never the JSON
 * literal "null".
 *
 * RED: Modules\Sync\Internal\Merge\OpLogReplayer (production namespace)
 * does not exist yet. Fails with "Class not found" until Plan 11-02
 * implements the productionized replayer.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * Inserts a single op_log_entries row with the given value and returns the row.
 *
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

// Verify PHP type and value survive the always-JSON round-trip.
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

    // stored value must be the JSON string '"null"' (quoted), not SQL NULL
    expect($row->value)->toBe('"null"');

    // decodeValue must return the PHP string "null", not PHP null
    // RED: OpLogReplayer in production namespace does not exist yet.
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

    // RED: production replayer does not exist.
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

    // RED: production replayer does not exist.
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

    // RED: production replayer does not exist.
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

    // RED: production replayer does not exist.
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

    // RED: production replayer does not exist.
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

    // Directly insert SQL NULL in the value column (tombstone/clear sentinel).
    $id = $db->connection()->table('op_log_entries')->insertGetId([
        'user_id' => $userId,
        'device_id' => 'device-wire',
        'table_name' => 'transactions',
        'pk' => '1',
        'field' => 'note',
        'op_type' => 'set',
        'value' => null,     // SQL NULL = clear sentinel
        'hlc_l' => 1000,
        'hlc_c' => 0,
        'signature' => str_repeat('aa', 32),
        'recorded_at' => '2026-06-15 10:00:00',
    ]);

    $row = $db->connection()->table('op_log_entries')->where('id', $id)->first();
    expect($row->value)->toBeNull();

    // decodeValue(null) must return PHP null (clear sentinel, write SQL NULL to column).
    // RED: production replayer does not exist.
    $replayer = new OpLogReplayer($db, []);
    expect($replayer->decodeValue(null))->toBeNull();
});
