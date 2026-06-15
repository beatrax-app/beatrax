<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;

uses(RefreshDatabase::class);

/*
 * CatchUpProtocolTest — XPORT-02: catch-up exchange delivers missing ops.
 *
 * Wave 3: PeerCatchUpExchanger now exists; these run as real integration tests.
 *
 * Validates the catch-up protocol (RESEARCH Pattern 6):
 *   On session establishment, both peers exchange their HLC watermarks.
 *   Each peer then sends all op_log_entries with (hlc_l, hlc_c) greater
 *   than the other's watermark. After the exchange both peers are up to date.
 *
 * Protocol summary:
 *   Initiator → Responder: CATCH_UP_REQUEST { my_hlc_l, my_hlc_c, device_id, user_id }
 *   Responder → Initiator: CATCH_UP_RESPONSE { ops: [...] where hlc > request watermark }
 *   Initiator → Responder: CATCH_UP_REQUEST (symmetric)
 *   Responder → Initiator: CATCH_UP_RESPONSE (symmetric)
 *   Both: CATCH_UP_COMPLETE → switch to LIVE_STREAM mode
 *
 * Gap detection: ops with (hlc_l, hlc_c) lexicographically > watermark.
 * Op delivery: batched at ≤ 1024 ops or ≤ 64KB per frame.
 *
 * Database: uses op_log_entries table (from Phase 11) for HLC state queries.
 */

/**
 * Helper: insert a row directly into op_log_entries without going through the replayer.
 *
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

    DB::table('op_log_entries')->insert(array_merge($defaults, $attrs));
}

it('catch-up request correctly identifies ops missing from the peer (HLC watermark comparison)', function (): void {
    // Arrange: insert 3 ops with ascending HLC values.
    insertOpLogRow(['hlc_l' => 1_718_000_000_100, 'hlc_c' => 0, 'field' => 'note', 'value' => '"op1"']);
    insertOpLogRow(['hlc_l' => 1_718_000_000_200, 'hlc_c' => 0, 'field' => 'note', 'value' => '"op2"']);
    insertOpLogRow(['hlc_l' => 1_718_000_000_300, 'hlc_c' => 0, 'field' => 'note', 'value' => '"op3"']);

    $framer = new TransportFramer;
    $exchanger = new PeerCatchUpExchanger(
        db: app(\Illuminate\Database\DatabaseManager::class),
        framer: $framer,
    );

    // Peer watermark is (hlc_l=1_718_000_000_150, hlc_c=0) — sits between op1 and op2.
    $frames = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 1_718_000_000_150, peerHlcC: 0);

    // Only op2 and op3 should be returned (op1 is at hlc_l ≤ watermark).
    expect($frames)->not->toBeEmpty('ops newer than watermark must be returned');

    // Decode all frames and collect entries.
    $entries = [];
    foreach ($frames as $frame) {
        $entries = array_merge($entries, $framer->decode($frame));
    }

    expect($entries)->toHaveCount(2, 'exactly op2 and op3 must be above the watermark (hlc_l=150 is ≤ watermark)');
    expect($entries[0]->hlcL)->toBe(1_718_000_000_200, 'first returned entry must be op2');
    expect($entries[1]->hlcL)->toBe(1_718_000_000_300, 'second returned entry must be op3');
});

it('catch-up delivers missing ops to the peer and both ends converge', function (): void {
    // Arrange: insert 2 ops for user 1.
    insertOpLogRow(['hlc_l' => 1_718_000_000_100, 'hlc_c' => 0, 'field' => 'note', 'value' => '"first"']);
    insertOpLogRow(['hlc_l' => 1_718_000_000_200, 'hlc_c' => 5, 'field' => 'note', 'value' => '"second"']);

    $framer = new TransportFramer;
    $exchanger = new PeerCatchUpExchanger(
        db: app(\Illuminate\Database\DatabaseManager::class),
        framer: $framer,
    );

    // Peer has seen nothing (watermark 0,0) → should get all 2 ops.
    $frames = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 0, peerHlcC: 0);

    $entries = [];
    foreach ($frames as $frame) {
        $entries = array_merge($entries, $framer->decode($frame));
    }

    expect($entries)->toHaveCount(2, 'peer at watermark (0,0) must receive all 2 ops');

    // Bilateral: after the catch-up, a peer that absorbed both ops builds a watermark
    // equal to the max (hlc_l=200, hlc_c=5). opsAfterWatermark for that watermark → empty.
    $framesAfterSync = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 1_718_000_000_200, peerHlcC: 5);
    expect($framesAfterSync)->toBeEmpty('after sync, watermark at max hlc → no gap → empty response');
});

it('catch-up with no gap (peer already up to date) sends an empty CATCH_UP_RESPONSE', function (): void {
    // Arrange: one op exists.
    insertOpLogRow(['hlc_l' => 1_718_000_000_100, 'hlc_c' => 0]);

    $exchanger = new PeerCatchUpExchanger(
        db: app(\Illuminate\Database\DatabaseManager::class),
        framer: new TransportFramer,
    );

    // Peer watermark exactly at the highest known op.
    $frames = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 1_718_000_000_100, peerHlcC: 0);
    expect($frames)->toBeEmpty('equal watermark means peer is up to date → empty response');

    // Peer watermark beyond all known ops.
    $frames2 = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 1_718_000_999_999, peerHlcC: 0);
    expect($frames2)->toBeEmpty('watermark above all ops → still empty response');
});

it('catch-up batches large op sets into ≤ 64KB frames (backpressure guard)', function (): void {
    // Arrange: insert 2100 ops — this exceeds the 1024-op-per-frame cap, forcing multiple frames.
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
    // Chunked to avoid SQLite parameter limit.
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('op_log_entries')->insert($chunk);
    }

    $framer = new TransportFramer;
    $exchanger = new PeerCatchUpExchanger(
        db: app(\Illuminate\Database\DatabaseManager::class),
        framer: $framer,
    );

    // Peer at watermark (0,0) → gets all 2100 ops.
    $frames = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 0, peerHlcC: 0);

    expect(count($frames))->toBeGreaterThanOrEqual(3, '2100 ops / 1024 max per frame → at least 3 frames');

    // Every frame must decode without exception.
    $totalEntries = 0;
    foreach ($frames as $frame) {
        $decoded = $framer->decode($frame);
        expect(count($decoded))->toBeLessThanOrEqual(1024, 'each frame must hold ≤ 1024 ops (RESEARCH Pattern 6)');
        $totalEntries += count($decoded);
    }

    expect($totalEntries)->toBe(2100, 'all 2100 ops must survive the batch/frame round-trip');
});

it('catch-up is user-scoped: ops from other users are never included in the CATCH_UP_RESPONSE', function (): void {
    // Arrange: insert ops for both user 1 and user 2.
    insertOpLogRow(['user_id' => 1, 'hlc_l' => 1_718_000_000_100, 'field' => 'note', 'value' => '"for-user-1"']);
    insertOpLogRow(['user_id' => 2, 'hlc_l' => 1_718_000_000_200, 'field' => 'note', 'value' => '"for-user-2"']);

    $framer = new TransportFramer;
    $exchanger = new PeerCatchUpExchanger(
        db: app(\Illuminate\Database\DatabaseManager::class),
        framer: $framer,
    );

    // Catch-up for user 1 — must NOT include user 2's op.
    $frames = $exchanger->opsAfterWatermark(userId: 1, peerHlcL: 0, peerHlcC: 0);
    $entries = [];
    foreach ($frames as $frame) {
        $entries = array_merge($entries, $framer->decode($frame));
    }

    expect($entries)->toHaveCount(1, 'user-scoped catch-up must return only user 1 ops (T-13-11, I2 guard)');
    expect($entries[0]->userId)->toBe(1, 'returned entry must belong to user 1');

    // Catch-up for user 2 — must NOT include user 1's op.
    $frames2 = $exchanger->opsAfterWatermark(userId: 2, peerHlcL: 0, peerHlcC: 0);
    $entries2 = [];
    foreach ($frames2 as $frame) {
        $entries2 = array_merge($entries2, $framer->decode($frame));
    }

    expect($entries2)->toHaveCount(1, 'catch-up for user 2 must not bleed user 1 ops (I2 isolation)');
    expect($entries2[0]->userId)->toBe(2, 'returned entry must belong to user 2');
});
