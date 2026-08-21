<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\Strategies\OrSetStrategy;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// Every add tags its element with a unique (device, hlc) tag, and a removal
// names tags rather than values. That is what lets an add and a remove of the
// same value from different devices both be honoured: an element is gone only
// once every tag that added it has been named as removed.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
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

    $entryA = orSetEntry(
        deviceId: 'device-a',
        hlcL: 1000,
        hlcC: 0,
        added: [['v' => 'alias-foo', 'tag' => 'device-a:1000:0']],
        removedTags: [],
        userId: $userId,
    );

    $entryB = orSetEntry(
        deviceId: 'device-b',
        hlcL: 1001,
        hlcC: 0,
        added: [['v' => 'alias-bar', 'tag' => 'device-b:1001:0']],
        removedTags: [],
        userId: $userId,
    );

    $strategy = new OrSetStrategy;
    $result = $strategy->resolve([$entryA, $entryB]);

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

    $entryAdd = orSetEntry(
        deviceId: 'device-a',
        hlcL: 1000,
        hlcC: 0,
        added: [['v' => 'alias-foo', 'tag' => 'device-a:1000:0']],
        removedTags: [],
        userId: $userId,
    );

    // The same device removes it again by naming the tag it added under.
    $entryRemove = orSetEntry(
        deviceId: 'device-a',
        hlcL: 1001,
        hlcC: 0,
        added: [],
        removedTags: ['device-a:1000:0'],  // the tag of the add op is removed
        userId: $userId,
    );

    $strategy = new OrSetStrategy;
    $result = $strategy->resolve([$entryAdd, $entryRemove]);

    expect($result)->toBeArray();
    $values = array_column($result, 'v');
    expect(in_array('alias-foo', $values, true))->toBeFalse();
});
