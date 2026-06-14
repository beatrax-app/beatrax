<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\Strategies\OrSetStrategy;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

/*
 * SYNC-03 / D-06: OR-Set strategy convergence.
 *
 * merchant_aliases.merged_from uses an OR-Set CRDT (per 10-FINDINGS.md
 * per-table conflict-resolution rules: "or_set" strategy).
 *
 * OR-Set semantics: each add operation tags an element with a unique
 * (device_id, hlc_l, hlc_c) tag. Remove is effected by a subsequent op
 * that includes the element in its "removed" set. The merge is the union
 * of all added elements minus all elements that have been definitively
 * removed (all tags for that element appear in the removed set).
 *
 * Test scenarios:
 *   1. Two devices add disjoint elements → union of both sets.
 *   2. Device A adds element X (tag T1), device A then removes X (tag T1 in
 *      removed set) → X absent from the merged result.
 *
 * The value column in op_log_entries carries the always-JSON encoded
 * OR-Set state as a JSON object:
 *   {"added": [{"v": "alias-foo", "tag": "device-a:1000:0"}], "removed": []}
 *
 * RED: OrSetStrategy does not exist yet (Wave 1 creates it). Fails with
 * "Class not found" until Plan 11-02 implements the strategy set.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * Builds an OR-Set entry with added and removed element lists.
 *
 * @param  list<array{v: string, tag: string}>  $added
 * @param  list<string>  $removedTags
 */
function orSetEntry(
    string $deviceId,
    int $hlcL,
    int $hlcC,
    array $added,
    array $removedTags,
    int $userId,
): OpLogEntry {
    $value = json_encode([
        'added' => $added,
        'removed' => $removedTags,
    ], JSON_THROW_ON_ERROR);

    return new OpLogEntry(
        table: 'merchant_aliases',
        pk: '1',
        field: 'merged_from',
        value: $value,
        hlcL: $hlcL,
        hlcC: $hlcC,
        deviceId: $deviceId,
        opType: OpType::Set,
        signature: str_repeat('aa', 32),
        userId: $userId,
    );
}

it('two devices add disjoint elements — union contains both', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'orset-u1',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // device-a adds "alias-foo" with tag "device-a:1000:0".
    $entryA = orSetEntry(
        deviceId: 'device-a',
        hlcL: 1000,
        hlcC: 0,
        added: [['v' => 'alias-foo', 'tag' => 'device-a:1000:0']],
        removedTags: [],
        userId: $userId,
    );

    // device-b adds "alias-bar" with tag "device-b:1001:0".
    $entryB = orSetEntry(
        deviceId: 'device-b',
        hlcL: 1001,
        hlcC: 0,
        added: [['v' => 'alias-bar', 'tag' => 'device-b:1001:0']],
        removedTags: [],
        userId: $userId,
    );

    // RED: OrSetStrategy does not exist yet.
    $strategy = new OrSetStrategy;
    $result = $strategy->resolve([$entryA, $entryB]);

    // Result should be the union: both aliases present.
    expect($result)->toBeArray();
    $values = array_column($result, 'v');
    sort($values);
    expect($values)->toBe(['alias-bar', 'alias-foo']);
});

it('add-then-remove: element removed by its tag is absent from the union', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'orset-u2',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // device-a adds "alias-foo" at HLC 1000.
    $entryAdd = orSetEntry(
        deviceId: 'device-a',
        hlcL: 1000,
        hlcC: 0,
        added: [['v' => 'alias-foo', 'tag' => 'device-a:1000:0']],
        removedTags: [],
        userId: $userId,
    );

    // device-a removes "alias-foo" at HLC 1001 by listing its tag in removed.
    $entryRemove = orSetEntry(
        deviceId: 'device-a',
        hlcL: 1001,
        hlcC: 0,
        added: [],
        removedTags: ['device-a:1000:0'],  // the tag of the add op is removed
        userId: $userId,
    );

    // RED: OrSetStrategy does not exist yet.
    $strategy = new OrSetStrategy;
    $result = $strategy->resolve([$entryAdd, $entryRemove]);

    // "alias-foo" was added then removed — it must be absent.
    expect($result)->toBeArray();
    $values = array_column($result, 'v');
    expect(in_array('alias-foo', $values, true))->toBeFalse();
});
