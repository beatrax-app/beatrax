<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// The strategy resolves to a PHP array, and the replayer wrote it straight
// into the column: "Array to string conversion", thrown outside the strategy's
// own catch, so it rolled back the entire merge batch. Calling resolve()
// directly — which was all the strategy's own test did — never reaches that.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{0: int, 1: string, 2: string} [userId, secretKeyHex unused, pubKeyHex]
 */
function orSetKeys(): array
{
    $keypair = sodium_crypto_sign_keypair();

    return [
        0,
        bin2hex(sodium_crypto_sign_secretkey($keypair)),
        bin2hex(sodium_crypto_sign_publickey($keypair)),
    ];
}

it('persists an OR-Set merge result as JSON into the merged_from column via replay()', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'orset-replay-u',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $aliasId = $db->connection()->table('merchant_aliases')->insertGetId([
        'user_id' => $userId,
        'pattern' => 'ALBERT HEIJN*',
        'generalized_pattern' => 'albert heijn',
        'friendly_name' => 'Albert Heijn',
        'merged_from' => null,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pkHex = bin2hex(sodium_crypto_sign_publickey($keypair));
    $signer = new DeviceKeySigner;

    // One op adds X, the other adds Y and removes X's tag: only Y survives.
    $makeSetOp = function (string $value, int $hlcL) use ($signer, $sk, $userId, $aliasId): OpLogEntry {
        $stub = new OpLogEntry(
            table: 'merchant_aliases',
            pk: $aliasId,
            field: 'merged_from',
            value: $value,
            hlcL: $hlcL,
            hlcC: 0,
            deviceId: 'device-orset',
            opType: OpType::Set,
            signature: '',
            userId: $userId,
        );
        $sig = $signer->sign($stub->signingPayload(), $sk);

        return new OpLogEntry(
            table: 'merchant_aliases',
            pk: $aliasId,
            field: 'merged_from',
            value: $value,
            hlcL: $hlcL,
            hlcC: 0,
            deviceId: 'device-orset',
            opType: OpType::Set,
            signature: $sig,
            userId: $userId,
        );
    };

    $op1 = $makeSetOp(json_encode([
        'added' => [['v' => 'merchant-x', 'tag' => 't1']],
        'removed' => [],
    ], JSON_THROW_ON_ERROR), 1000);

    $op2 = $makeSetOp(json_encode([
        'added' => [['v' => 'merchant-y', 'tag' => 't2']],
        'removed' => ['t1'],
    ], JSON_THROW_ON_ERROR), 1001);

    $replayer = new OpLogReplayer($db, ['device-orset' => $pkHex]);

    // This is the call that used to throw and roll the batch back.
    $replayer->replay([$op1, $op2], $userId);

    $stored = $db->connection()->table('merchant_aliases')
        ->where('id', $aliasId)
        ->value('merged_from');

    expect($stored)->toBeString();
    $decoded = json_decode((string) $stored, true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray()->toHaveCount(1);
    expect($decoded[0]['v'])->toBe('merchant-y');
    expect($decoded[0]['tag'])->toBe('t2');
});

it('a bad OR-Set value is quarantined without rolling back a sibling LWW edit in the same batch', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'orset-isolation-u',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // A malformed op on one field, alongside a valid edit on a sibling field of
    // the same row that must still land.
    $aliasId = $db->connection()->table('merchant_aliases')->insertGetId([
        'user_id' => $userId,
        'pattern' => 'JUMBO*',
        'generalized_pattern' => 'jumbo',
        'friendly_name' => 'Jumbo (old)',
        'merged_from' => null,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pkHex = bin2hex(sodium_crypto_sign_publickey($keypair));
    $signer = new DeviceKeySigner;

    $make = function (string $field, string $value, int $hlcL) use ($signer, $sk, $userId, $aliasId): OpLogEntry {
        $stub = new OpLogEntry(
            table: 'merchant_aliases',
            pk: $aliasId,
            field: $field,
            value: $value,
            hlcL: $hlcL,
            hlcC: 0,
            deviceId: 'device-orset',
            opType: OpType::Set,
            signature: '',
            userId: $userId,
        );
        $sig = $signer->sign($stub->signingPayload(), $sk);

        return new OpLogEntry(
            table: 'merchant_aliases',
            pk: $aliasId,
            field: $field,
            value: $value,
            hlcL: $hlcL,
            hlcC: 0,
            deviceId: 'device-orset',
            opType: OpType::Set,
            signature: $sig,
            userId: $userId,
        );
    };

    // Valid JSON, wrong shape.
    $badOrSet = $make('merged_from', json_encode('not-an-or-set', JSON_THROW_ON_ERROR), 1000);
    $goodLww = $make('friendly_name', json_encode('Jumbo (new)', JSON_THROW_ON_ERROR), 1001);

    $replayer = new OpLogReplayer($db, ['device-orset' => $pkHex]);
    $replayer->replay([$badOrSet, $goodLww], $userId);

    // The sibling edit survived: one bad op must not roll back the batch.
    $friendly = $db->connection()->table('merchant_aliases')
        ->where('id', $aliasId)
        ->value('friendly_name');
    expect($friendly)->toBe('Jumbo (new)');

    $quarantined = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('table_name', 'merchant_aliases')
        ->where('reason', 'strategy_error')
        ->count();
    expect($quarantined)->toBeGreaterThanOrEqual(1);
});
