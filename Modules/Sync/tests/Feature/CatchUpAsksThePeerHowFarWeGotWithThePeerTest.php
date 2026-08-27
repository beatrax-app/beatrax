<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\PeerCatchUpWatermarks;

uses(RefreshDatabase::class);

// Device A writes at 10:00 and 10:10, device B writes at 10:01, and they meet.
// A asked for "everything after my own last write" — 10:10 — so B's 10:01 op
// was below the watermark and never sent. The watermark has to say how far
// A has consumed of B's stream, which for a first meeting is: nothing.

function catchUpWatermarkUser(DatabaseManager $db): int
{
    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'catchup-watermark-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function registerCatchUpDevice(DatabaseManager $db, int $userId, string $deviceId, int $isSelf): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $deviceId,
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'one two three four five six',
        'is_self' => $isSelf,
        'paired_at' => '2026-06-01 00:00:00',
        'confirmed_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function insertCatchUpOp(DatabaseManager $db, int $userId, string $deviceId, int $hlcL, string $pk): void
{
    $db->connection()->table('op_log_entries')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'table_name' => 'merchants',
        'pk' => $pk,
        'field' => 'name',
        'op_type' => OpType::Set->value,
        'value' => json_encode('name '.$pk, JSON_THROW_ON_ERROR),
        'hlc_l' => $hlcL,
        'hlc_c' => 0,
        'signature' => str_repeat('a', 128),
        'recorded_at' => '2026-06-14 10:00:00',
    ]);
}

it('asks a peer for everything on a first meeting instead of only what postdates this device\'s own last write', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = catchUpWatermarkUser($db);

    registerCatchUpDevice($db, $userId, 'device-a', 1);

    // What device A's own writes leave behind: the local clock at 10:10.
    $db->connection()->table('hlc_clock_state')->insert([
        'user_id' => $userId,
        'device_id' => 'device-a',
        'last_l' => 1_000_600_000,
        'last_c' => 0,
        'updated_at' => '2026-06-14 10:10:00',
    ]);

    $request = (new PeerCatchUpExchanger($db, new TransportFramer))
        ->buildRequest($userId, 'device-a', 'device-b');

    expect($request['hlc_l'])->toBe(0)
        ->and($request['hlc_c'])->toBe(0)
        ->and($request['device_id'])->toBe('device-a');
});

it('asks only for what postdates the last op this peer actually delivered', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = catchUpWatermarkUser($db);

    registerCatchUpDevice($db, $userId, 'device-a', 1);
    registerCatchUpDevice($db, $userId, 'device-b', 0);

    (new PeerCatchUpWatermarks($db))->advance($userId, 'device-b', [
        new OpLogEntry(
            table: 'merchants', pk: 1, field: 'name', value: null,
            hlcL: 1_000_060_000, hlcC: 4, deviceId: 'device-b',
            opType: OpType::Set, signature: 'sig', userId: $userId,
        ),
    ], '2026-06-14 10:01:00');

    $request = (new PeerCatchUpExchanger($db, new TransportFramer))
        ->buildRequest($userId, 'device-a', 'device-b');

    expect($request['hlc_l'])->toBe(1_000_060_000)
        ->and($request['hlc_c'])->toBe(4);
});

it('sends the op a peer wrote before this device\'s own last write once the watermark is per peer', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = catchUpWatermarkUser($db);

    registerCatchUpDevice($db, $userId, 'device-a', 0);
    registerCatchUpDevice($db, $userId, 'device-b', 1);

    insertCatchUpOp($db, $userId, 'device-a', 1_000_000_000, '1');
    insertCatchUpOp($db, $userId, 'device-a', 1_000_600_000, '2');
    insertCatchUpOp($db, $userId, 'device-b', 1_000_060_000, '3');

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer);
    $framer = new TransportFramer;

    $request = $exchanger->buildRequest($userId, 'device-b', 'device-a');
    $frames = $exchanger->opsAfterWatermark($userId, $request['hlc_l'], $request['hlc_c']);

    $delivered = [];
    foreach ($frames as $frame) {
        foreach ($framer->decode($frame) as $entry) {
            $delivered[] = $entry->hlcL;
        }
    }

    expect($delivered)->toHaveCount(3)
        ->and($delivered)->toContain(1_000_000_000);
});

it('never walks a peer watermark backwards when an older frame arrives late', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = catchUpWatermarkUser($db);

    $watermarks = new PeerCatchUpWatermarks($db);

    $entryAt = static fn (int $hlcL): OpLogEntry => new OpLogEntry(
        table: 'merchants', pk: 1, field: 'name', value: null,
        hlcL: $hlcL, hlcC: 0, deviceId: 'device-b',
        opType: OpType::Set, signature: 'sig', userId: $userId,
    );

    $watermarks->advance($userId, 'device-b', [$entryAt(500)], '2026-06-14 10:00:00');
    $watermarks->advance($userId, 'device-b', [$entryAt(100)], '2026-06-14 10:00:01');

    expect($watermarks->for($userId, 'device-b'))->toBe([500, 0]);
});
