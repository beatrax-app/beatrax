<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\PersistedOpLogEntries;

uses(RefreshDatabase::class);

// Only a merchant's first sighting was captured, so devices agreed on which
// merchants they had seen and disagreed on how often — and that count decides
// which remembered category wins. The obvious repair is worse: the column holds
// the merged total, so publishing it re-counts the peer's increments as ours.

function memoryCountUser(DatabaseManager $db): int
{
    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'gcount-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// One keypair per device id for the whole run, so the ops a device signs stay
// verifiable by the replayer that has to read them back.
/**
 * @return array{secret: string, publicHex: string}
 */
function memoryCountKeypair(string $deviceId): array
{
    /** @var array<string, array{secret: string, publicHex: string}> $cache */
    static $cache = [];

    if (! isset($cache[$deviceId])) {
        $keypair = sodium_crypto_sign_keypair();
        $cache[$deviceId] = [
            'secret' => sodium_crypto_sign_secretkey($keypair),
            'publicHex' => bin2hex(sodium_crypto_sign_publickey($keypair)),
        ];
    }

    return $cache[$deviceId];
}

function memoryCountWriter(int $userId, string $deviceId): OpLogWriter
{
    $keys = memoryCountKeypair($deviceId);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => $keys['secret'],
        'publicKey' => sodium_hex2bin($keys['publicHex']),
    ]);

    return $writer;
}

/**
 * @return array{0: int, 1: int} [merchantId, categoryId]
 */
function memoryCountParents(DatabaseManager $db, int $userId): array
{
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
        'slug' => 'fuel-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    return [$merchantId, $categoryId];
}

it('publishes this device running total, not the merged column value', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = memoryCountUser($db);

    $writer = memoryCountWriter($userId, 'device-a');

    $writer->writeIncrement('merchant_memories', 7, 'occurrence_count', 1);
    $writer->writeIncrement('merchant_memories', 7, 'occurrence_count', 1);
    $writer->writeIncrement('merchant_memories', 7, 'occurrence_count', 1);

    $published = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'merchant_memories')
        ->where('field', 'occurrence_count')
        ->orderBy('id')
        ->pluck('value')
        ->map(static fn (string $v): mixed => json_decode($v, true))
        ->all();

    // Each op restates this device's own running total, never the merged one.
    expect($published)->toBe([1, 2, 3]);
});

it('sums both devices increments through replay(), instead of letting the larger one win', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = memoryCountUser($db);

    [$merchantId, $categoryId] = memoryCountParents($db, $userId);

    $memoryId = (int) $db->connection()->table('merchant_memories')->insertGetId([
        'user_id' => $userId,
        'merchant_id' => $merchantId,
        'category_id' => $categoryId,
        'occurrence_count' => 0,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    memoryCountWriter($userId, 'device-a')->writeIncrement('merchant_memories', $memoryId, 'occurrence_count', 1);
    memoryCountWriter($userId, 'device-b')->writeIncrement('merchant_memories', $memoryId, 'occurrence_count', 1);
    memoryCountWriter($userId, 'device-b')->writeIncrement('merchant_memories', $memoryId, 'occurrence_count', 1);

    // Through the production replayer, not the strategy in isolation: the
    // strategy was right all along and the column was still wrong.
    $entries = (new PersistedOpLogEntries($db))->forRows($userId, [
        ['table' => 'merchant_memories', 'pk' => (string) $memoryId],
    ]);

    (new OpLogReplayer($db, [
        'device-a' => memoryCountKeypair('device-a')['publicHex'],
        'device-b' => memoryCountKeypair('device-b')['publicHex'],
    ]))->replay($entries, $userId);

    // Three sightings across two devices sum to three. Read as last-writer-wins
    // the second device would simply overwrite the first.
    $count = $db->connection()->table('merchant_memories')->where('id', $memoryId)->value('occurrence_count');

    expect((int) $count)->toBe(3);
});

it('sums both devices increments when each device lands in its own frame', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = memoryCountUser($db);

    [$merchantId, $categoryId] = memoryCountParents($db, $userId);

    $memoryId = (int) $db->connection()->table('merchant_memories')->insertGetId([
        'user_id' => $userId,
        'merchant_id' => $merchantId,
        'category_id' => $categoryId,
        'occurrence_count' => 0,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    $deviceKeys = [
        'device-a' => memoryCountKeypair('device-a')['publicHex'],
        'device-b' => memoryCountKeypair('device-b')['publicHex'],
    ];
    $replayer = new OpLogReplayer($db, $deviceKeys);
    $persisted = new PersistedOpLogEntries($db);
    $row = [['table' => 'merchant_memories', 'pk' => (string) $memoryId]];

    memoryCountWriter($userId, 'device-a')->writeIncrement('merchant_memories', $memoryId, 'occurrence_count', 1);
    $replayer->replay($persisted->forRows($userId, $row), $userId);

    memoryCountWriter($userId, 'device-b')->writeIncrement('merchant_memories', $memoryId, 'occurrence_count', 1);
    memoryCountWriter($userId, 'device-b')->writeIncrement('merchant_memories', $memoryId, 'occurrence_count', 1);

    // The second frame carries ONLY device-b's ops, exactly as a 1024-op frame
    // boundary would split them.
    $replayer->replay(array_values(array_filter(
        $persisted->forRows($userId, $row),
        static fn (OpLogEntry $entry): bool => $entry->deviceId === 'device-b',
    )), $userId);

    $count = $db->connection()->table('merchant_memories')->where('id', $memoryId)->value('occurrence_count');

    expect((int) $count)->toBe(3);
});
