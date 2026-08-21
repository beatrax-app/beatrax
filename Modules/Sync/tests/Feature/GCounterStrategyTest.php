<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\Strategies\GCounterStrategy;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// Each device carries its own running total, and the merge sums the per-device
// maxima rather than the ops. Summing the ops would double-count the moment the
// same history is replayed twice, which a rebuild does by construction.

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

    // One device reaches 3 over two ops, the other reaches 5 in one. The merge
    // must read 8 — the sum of the maxima, not of the three op values.

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

    $entries = [$entryA1, $entryA2, $entryB1];

    $strategy = new GCounterStrategy;
    $result = $strategy->resolve($entries);

    expect($result)->toBe(8);
});

it('re-replaying the same GCounter ops still yields 8, with no double-count', function (): void {
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

    $strategy = new GCounterStrategy;

    $result1 = $strategy->resolve($entries);
    expect($result1)->toBe(8);

    // The same ops again: a rebuild replays them, so this must not accumulate.
    $result2 = $strategy->resolve($entries);
    expect($result2)->toBe(8);
});
