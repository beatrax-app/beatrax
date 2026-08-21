<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\Strategies\GCounterStrategy;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

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

function memoryCountWriter(int $userId, string $deviceId): OpLogWriter
{
    $keypair = sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    return $writer;
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

it('sums both devices increments instead of letting the larger one win', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = memoryCountUser($db);

    memoryCountWriter($userId, 'device-a')->writeIncrement('merchant_memories', 7, 'occurrence_count', 1);
    memoryCountWriter($userId, 'device-b')->writeIncrement('merchant_memories', 7, 'occurrence_count', 1);
    memoryCountWriter($userId, 'device-b')->writeIncrement('merchant_memories', 7, 'occurrence_count', 1);

    $rows = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'merchant_memories')
        ->where('field', 'occurrence_count')
        ->orderBy('id')
        ->get();

    $candidates = [];
    foreach ($rows as $row) {
        $candidates[] = new OpLogEntry(
            table: (string) $row->table_name,
            pk: (string) $row->pk,
            field: (string) $row->field,
            value: (string) $row->value,
            hlcL: (int) $row->hlc_l,
            hlcC: (int) $row->hlc_c,
            deviceId: (string) $row->device_id,
            opType: OpType::Set,
            signature: (string) $row->signature,
            userId: (int) $row->user_id,
        );
    }

    // Three sightings across two devices sum to three. Read as last-writer-wins
    // the second device would simply overwrite the first.
    expect((new GCounterStrategy)->resolve($candidates))->toBe(3);
});
