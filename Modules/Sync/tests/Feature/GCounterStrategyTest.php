<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\Strategies\GCounterStrategy;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

/*
 * SYNC-03 / D-06: G-Counter strategy convergence.
 *
 * merchant_memories.occurrence_count uses a G-Counter CRDT (per 10-FINDINGS.md
 * per-table conflict-resolution rules: "g_counter" strategy).
 *
 * G-Counter semantics: each device maintains its own running total. Merge takes
 * the SUM of per-device maximum values — NOT the sum of all ops. This prevents
 * double-counting when the same ops are re-replayed (Pitfall 5).
 *
 * Two devices:
 *   device-a: persists a per-device count of 3 (latest value = 3)
 *   device-b: persists a per-device count of 5 (latest value = 5)
 *
 * Expected GCounterStrategy resolution: 3 + 5 = 8.
 * Re-replaying the same ops still yields 8 (no double-count).
 *
 * RED: GCounterStrategy does not exist yet (Wave 1 creates it). Fails with
 * "Class not found" until Plan 11-02 implements the strategy set.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('GCounterStrategy resolves to sum of per-device max values (3 + 5 = 8)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'gcounter-u',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // Seed some op entries simulating two devices contributing per-device counts.
    // device-a contributed count=1 at HLC 1000, then count=3 at HLC 1001 (latest=3).
    // device-b contributed count=5 at HLC 1002 (latest=5).
    // G-Counter merge: max per device → sum. max(device-a) = 3, max(device-b) = 5 → total = 8.

    $makeEntry = static function (
        string $deviceId,
        int $hlcL,
        int $hlcC,
        int $count,
        int $userId,
    ): OpLogEntry {
        return new OpLogEntry(
            table: 'merchant_memories',
            pk: '1',
            field: 'occurrence_count',
            value: json_encode($count, JSON_THROW_ON_ERROR),   // always-JSON encoded
            hlcL: $hlcL,
            hlcC: $hlcC,
            deviceId: $deviceId,
            opType: OpType::Set,
            signature: str_repeat('aa', 32),  // stub sig — strategy only looks at value+device
            userId: $userId,
        );
    };

    $entryA1 = $makeEntry('device-a', 1000, 0, 1, $userId);   // device-a early
    $entryA2 = $makeEntry('device-a', 1001, 0, 3, $userId);   // device-a latest (max=3)
    $entryB1 = $makeEntry('device-b', 1002, 0, 5, $userId);   // device-b (max=5)

    // HLC-sorted ascending (already in order: 1000 < 1001 < 1002).
    $entries = [$entryA1, $entryA2, $entryB1];

    // RED: GCounterStrategy does not exist yet.
    $strategy = new GCounterStrategy;
    $result = $strategy->resolve($entries);

    expect($result)->toBe(8);
});

it('re-replaying the same GCounter ops still yields 8 (no double-count — Pitfall 5)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'gcounter-u2',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $makeEntry = static function (
        string $deviceId,
        int $hlcL,
        int $hlcC,
        int $count,
        int $userId,
    ): OpLogEntry {
        return new OpLogEntry(
            table: 'merchant_memories',
            pk: '1',
            field: 'occurrence_count',
            value: json_encode($count, JSON_THROW_ON_ERROR),
            hlcL: $hlcL,
            hlcC: $hlcC,
            deviceId: $deviceId,
            opType: OpType::Set,
            signature: str_repeat('aa', 32),
            userId: $userId,
        );
    };

    $entryA = $makeEntry('device-a', 1001, 0, 3, $userId);
    $entryB = $makeEntry('device-b', 1002, 0, 5, $userId);

    $entries = [$entryA, $entryB];

    // RED: GCounterStrategy does not exist yet.
    $strategy = new GCounterStrategy;

    // First resolve.
    $result1 = $strategy->resolve($entries);
    expect($result1)->toBe(8);

    // Re-resolve with the same ops (idempotent — Pitfall 5 guard).
    $result2 = $strategy->resolve($entries);
    expect($result2)->toBe(8);
});
