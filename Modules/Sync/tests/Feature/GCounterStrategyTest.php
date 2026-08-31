<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\Strategies\GCounterStrategy;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// Each device carries its own running total, and the merge sums the per-device
// maxima rather than the ops. Summing the ops would double-count the moment the
// same history is replayed twice, which a rebuild does by construction — and
// calling resolve() directly, which was all this file used to do, reaches
// neither the rebuild nor the frame boundary that broke the sum in production.

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

it('re-replaying the same GCounter ops through replay() still yields 8, with no double-count', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$userId, $memoryId, $deviceKeys, $sk] = gCounterFixture($db, 'gcounter-u2');

    $ops = [
        gCounterOp($sk, $userId, $memoryId, 'device-a', 1001, 3),
        gCounterOp($sk, $userId, $memoryId, 'device-b', 1002, 5),
    ];

    $replayer = new OpLogReplayer($db, $deviceKeys);
    $replayer->replay($ops, $userId);

    expect(gCounterColumn($db, $memoryId))->toBe(8);

    // The same ops again: a rebuild replays them, and so does any later frame
    // that touches this row, so neither may accumulate.
    $replayer->replay($ops, $userId);

    expect(gCounterColumn($db, $memoryId))->toBe(8);
});

it('sums the per-device maxima when the two devices land in separate frames', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$userId, $memoryId, $deviceKeys, $sk] = gCounterFixture($db, 'gcounter-u3');

    $replayer = new OpLogReplayer($db, $deviceKeys);
    $replayer->replay([gCounterOp($sk, $userId, $memoryId, 'device-a', 1001, 3)], $userId);
    $replayer->replay([gCounterOp($sk, $userId, $memoryId, 'device-b', 1002, 5)], $userId);

    expect(gCounterColumn($db, $memoryId))->toBe(8);
});

// The whole fixture a G-Counter needs: a user, a merchant, a category and the
// merchant_memories row whose occurrence_count the ops move.
/**
 * @return array{0: int, 1: int, 2: array<string, string>, 3: string}
 */
function gCounterFixture(DatabaseManager $db, string $username): array
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $merchantId = (int) $db->connection()->table('merchants')->insertGetId([
        'user_id' => $userId,
        'name' => 'Esso',
        'normalized_name' => 'esso',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    $categoryId = (int) $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Fuel',
        'slug' => 'fuel-'.$username,
        'kind' => 'expense',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    $memoryId = (int) $db->connection()->table('merchant_memories')->insertGetId([
        'user_id' => $userId,
        'merchant_id' => $merchantId,
        'category_id' => $categoryId,
        'occurrence_count' => 0,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $pkHex = bin2hex(sodium_crypto_sign_publickey($keypair));

    return [
        $userId,
        $memoryId,
        ['device-a' => $pkHex, 'device-b' => $pkHex],
        sodium_crypto_sign_secretkey($keypair),
    ];
}

function gCounterOp(string $sk, int $userId, int $memoryId, string $deviceId, int $hlcL, int $count): OpLogEntry
{
    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: 'merchant_memories',
        pk: $memoryId,
        field: 'occurrence_count',
        value: json_encode($count, JSON_THROW_ON_ERROR),
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: $deviceId,
        opType: OpType::Set,
        signature: $signature,
        userId: $userId,
    );

    return $make((new DeviceKeySigner)->sign($make('')->signingPayload(), $sk));
}

function gCounterColumn(DatabaseManager $db, int $memoryId): int
{
    return (int) $db->connection()->table('merchant_memories')->where('id', $memoryId)->value('occurrence_count');
}
