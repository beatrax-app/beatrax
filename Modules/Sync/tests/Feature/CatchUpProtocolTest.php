<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;

uses(RefreshDatabase::class);

// The exchange is symmetric and watermark-driven: each peer sends every entry
// ordered after the watermark the other reported. Frames are capped at 1024 ops
// or 64KB, so anything asserting a single frame would pass by accident.

/**
 * @param  array<string, mixed>  $attrs
 */
function insertOpLogRow(array $attrs): void
{
    $defaults = [
        'user_id' => 1,
        'device_id' => 'device-a',
        'table_name' => 'transactions',
        'pk' => '1',
        'field' => 'note',
        'op_type' => 'set',
        'value' => '"test-value"',
        'hlc_l' => 1_718_000_000_000,
        'hlc_c' => 0,
        'signature' => str_repeat('aa', 64),
        'recorded_at' => now()->toDateTimeString(),
    ];

    $row = array_merge($defaults, $attrs);

    // Register the author the way production does. A catch-up only ships
    // entries whose device is still confirmed, because one signed by a device
    // the registry has forgotten cannot be verified by anybody — an
    // unregistered writer here would simply never be sent.
    registerOpLogAuthor((int) $row['user_id'], (string) $row['device_id']);

    DB::table('op_log_entries')->insert($row);
}

function registerOpLogAuthor(int $userId, string $deviceId): void
{
    $exists = DB::table('device_registry')
        ->where('user_id', $userId)
        ->where('device_id', $deviceId)
        ->exists();

    if ($exists) {
        return;
    }

    DB::table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Catch-up fixture',
        'ed25519_public_key_hex' => str_repeat('11', 32),
        'x25519_public_key_hex' => str_repeat('22', 32),
        'safety_number_words' => '',
        'is_self' => 0,
        'paired_at' => '2026-06-14T00:00:00+00:00',
        'confirmed_at' => '2026-06-14T00:00:00+00:00',
        'last_seen_at' => null,
        'created_at' => '2026-06-14T00:00:00+00:00',
        'updated_at' => '2026-06-14T00:00:00+00:00',
    ]);
}

it('catch-up request correctly identifies ops missing from the peer (HLC watermark comparison)', function (): void {
    insertOpLogRow(['hlc_l' => 1_718_000_000_100, 'hlc_c' => 0, 'field' => 'note', 'value' => '"op1"']);
    insertOpLogRow(['hlc_l' => 1_718_000_000_200, 'hlc_c' => 0, 'field' => 'note', 'value' => '"op2"']);
    insertOpLogRow(['hlc_l' => 1_718_000_000_300, 'hlc_c' => 0, 'field' => 'note', 'value' => '"op3"']);

    $framer = new TransportFramer;
    $exchanger = new PeerCatchUpExchanger(
        db: app(DatabaseManager::class),
        framer: $framer,
    );

    // The watermark deliberately sits between op1 and op2.
    $frames = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 1_718_000_000_150, peerHlcC: 0);

    expect($frames)->not->toBeEmpty('ops newer than watermark must be returned');

    $entries = [];
    foreach ($frames as $frame) {
        $entries = array_merge($entries, $framer->decode($frame));
    }

    expect($entries)->toHaveCount(2, 'exactly op2 and op3 must be above the watermark (hlc_l=150 is ≤ watermark)');
    expect($entries[0]->hlcL)->toBe(1_718_000_000_200, 'first returned entry must be op2');
    expect($entries[1]->hlcL)->toBe(1_718_000_000_300, 'second returned entry must be op3');
});

it('catch-up delivers missing ops to the peer and both ends converge', function (): void {
    insertOpLogRow(['hlc_l' => 1_718_000_000_100, 'hlc_c' => 0, 'field' => 'note', 'value' => '"first"']);
    insertOpLogRow(['hlc_l' => 1_718_000_000_200, 'hlc_c' => 5, 'field' => 'note', 'value' => '"second"']);

    $framer = new TransportFramer;
    $exchanger = new PeerCatchUpExchanger(
        db: app(DatabaseManager::class),
        framer: $framer,
    );

    $frames = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 0, peerHlcC: 0);

    $entries = [];
    foreach ($frames as $frame) {
        $entries = array_merge($entries, $framer->decode($frame));
    }

    expect($entries)->toHaveCount(2, 'peer at watermark (0,0) must receive all 2 ops');

    // A peer that absorbed both ops reports the max as its watermark, and the
    // exchange must then have nothing left to send.
    $framesAfterSync = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 1_718_000_000_200, peerHlcC: 5);
    expect($framesAfterSync)->toBeEmpty('after sync, watermark at max hlc → no gap → empty response');
});

it('catch-up with no gap (peer already up to date) sends an empty CATCH_UP_RESPONSE', function (): void {
    insertOpLogRow(['hlc_l' => 1_718_000_000_100, 'hlc_c' => 0]);

    $exchanger = new PeerCatchUpExchanger(
        db: app(DatabaseManager::class),
        framer: new TransportFramer,
    );

    $frames = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 1_718_000_000_100, peerHlcC: 0);
    expect($frames)->toBeEmpty('equal watermark means peer is up to date → empty response');

    $frames2 = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 1_718_000_999_999, peerHlcC: 0);
    expect($frames2)->toBeEmpty('watermark above all ops → still empty response');
});

it('catch-up batches large op sets into ≤ 64KB frames (backpressure guard)', function (): void {
    // Past the 1024-op frame cap, so the exchange has to span several frames.
    $rows = [];
    for ($i = 0; $i < 2100; $i++) {
        $rows[] = [
            'user_id' => 1,
            'device_id' => 'device-a',
            'table_name' => 'transactions',
            'pk' => (string) ($i + 1),
            'field' => 'note',
            'op_type' => 'set',
            'value' => json_encode("value-$i"),
            'hlc_l' => 1_718_000_000_000 + $i,
            'hlc_c' => 0,
            'signature' => str_repeat('bb', 64),
            'recorded_at' => now()->toDateTimeString(),
        ];
    }
    // Bulk insert bypasses insertOpLogRow(), so the author is registered here:
    // a catch-up only ships entries whose device is still confirmed.
    registerOpLogAuthor(1, 'device-a');

    // Chunked to stay under SQLite's bound-parameter limit.
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('op_log_entries')->insert($chunk);
    }

    $framer = new TransportFramer;
    $exchanger = new PeerCatchUpExchanger(
        db: app(DatabaseManager::class),
        framer: $framer,
    );

    $frames = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 0, peerHlcC: 0);

    expect(count($frames))->toBeGreaterThanOrEqual(3, '2100 ops / 1024 max per frame → at least 3 frames');

    $totalEntries = 0;
    foreach ($frames as $frame) {
        $decoded = $framer->decode($frame);
        expect(count($decoded))->toBeLessThanOrEqual(1024, 'each frame must hold ≤ 1024 ops (RESEARCH Pattern 6)');
        $totalEntries += count($decoded);
    }

    expect($totalEntries)->toBe(2100, 'all 2100 ops must survive the batch/frame round-trip');
});

it('catch-up is user-scoped: ops from other users are never included in the CATCH_UP_RESPONSE', function (): void {
    insertOpLogRow(['user_id' => 1, 'hlc_l' => 1_718_000_000_100, 'field' => 'note', 'value' => '"for-user-1"']);
    insertOpLogRow(['user_id' => 2, 'hlc_l' => 1_718_000_000_200, 'field' => 'note', 'value' => '"for-user-2"']);

    $framer = new TransportFramer;
    $exchanger = new PeerCatchUpExchanger(
        db: app(DatabaseManager::class),
        framer: $framer,
    );

    $frames = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 0, peerHlcC: 0);
    $entries = [];
    foreach ($frames as $frame) {
        $entries = array_merge($entries, $framer->decode($frame));
    }

    expect($entries)->toHaveCount(1, 'user-scoped catch-up must return only user 1 ops (T-13-11, I2 guard)');
    expect($entries[0]->userId)->toBe(1, 'returned entry must belong to user 1');

    $frames2 = $exchanger->opsAfterWatermark(userId: 2, peerHlcL: 0, peerHlcC: 0);
    $entries2 = [];
    foreach ($frames2 as $frame) {
        $entries2 = array_merge($entries2, $framer->decode($frame));
    }

    expect($entries2)->toHaveCount(1, 'catch-up for user 2 must not bleed user 1 ops (I2 isolation)');
    expect($entries2[0]->userId)->toBe(2, 'returned entry must belong to user 2');
});

it('never ships an entry signed by a device the registry has forgotten', function (): void {
    // An entry signed by a device the registry has forgotten cannot be verified
    // by anyone, so sending it only fills the peer's log with drops. One import
    // shipped 12,948 entries, the phone refused 12,476, and because progress is
    // counted from survivors the screen reported the device as synced.
    insertOpLogRow(['device_id' => 'device-live', 'pk' => '1', 'hlc_l' => 1_718_000_000_100]);
    insertOpLogRow(['device_id' => 'device-retired', 'pk' => '2', 'hlc_l' => 1_718_000_000_200]);

    // The identity is retired the way a removal retires it: the row goes.
    DB::table('device_registry')->where('device_id', 'device-retired')->delete();

    $framer = new TransportFramer;
    $exchanger = new PeerCatchUpExchanger(db: app(DatabaseManager::class), framer: $framer);

    $entries = [];
    foreach ($exchanger->opsAfterWatermark(userId: 1, peerHlcL: 0, peerHlcC: 0) as $frame) {
        foreach ($framer->decode($frame) as $entry) {
            $entries[] = $entry->deviceId;
        }
    }

    expect($entries)->toContain('device-live');
    expect(in_array('device-retired', $entries, true))
        ->toBeFalse('an entry no peer can verify was put on the wire anyway');
});
