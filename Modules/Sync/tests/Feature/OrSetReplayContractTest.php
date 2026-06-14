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

/*
 * CR-02 / IN-05: OR-Set merge results must survive the round trip THROUGH
 * replay() into a real SQLite column.
 *
 * The OrSetStrategy resolves to a `list<array{v,tag}>` (a PHP array). The
 * previous replayer wrote that array straight into the column via the query
 * builder, which threw "Array to string conversion" — and because the
 * ->update() ran OUTSIDE the strategy try/catch, the exception propagated out
 * of replay() and rolled back the WHOLE merge batch. OrSetStrategyTest only
 * ever called resolve() directly, so this was invisible.
 *
 * These tests drive an `or_set` field (merchant_aliases.merged_from) all the
 * way through replay() and assert the persisted column value, plus assert that
 * one bad value does not roll back unrelated edits in the same batch.
 */

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

    // Seed an existing merchant_aliases row to SET merged_from on.
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

    // Two concurrent OR-Set ops: op1 adds element X (tag t1), op2 adds Y (tag t2)
    // and removes X's tag t1. Convergent result = {Y} only.
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

    // RED before CR-02: this threw "Array to string conversion" and rolled back.
    $replayer->replay([$op1, $op2], $userId);

    // The merged set persisted as JSON. Decode and assert the convergent set.
    $stored = $db->connection()->table('merchant_aliases')
        ->where('id', $aliasId)
        ->value('merged_from');

    expect($stored)->toBeString();
    $decoded = json_decode((string) $stored, true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray()->toHaveCount(1);
    expect($decoded[0]['v'])->toBe('merchant-y');
    expect($decoded[0]['tag'])->toBe('t2');
});

it('a bad OR-Set value is quarantined without rolling back a sibling LWW edit in the same batch (CR-02)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'orset-isolation-u',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // A merchant_aliases row whose merged_from will get a MALFORMED op, plus a
    // friendly_name LWW edit on the same row that must still land.
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

    // Malformed OR-Set payload: valid JSON, wrong shape (a bare string).
    $badOrSet = $make('merged_from', json_encode('not-an-or-set', JSON_THROW_ON_ERROR), 1000);
    // A perfectly valid LWW edit on a sibling field.
    $goodLww = $make('friendly_name', json_encode('Jumbo (new)', JSON_THROW_ON_ERROR), 1001);

    $replayer = new OpLogReplayer($db, ['device-orset' => $pkHex]);
    $replayer->replay([$badOrSet, $goodLww], $userId);

    // The sibling LWW edit survived — the bad OR-Set op did NOT roll back the batch.
    $friendly = $db->connection()->table('merchant_aliases')
        ->where('id', $aliasId)
        ->value('friendly_name');
    expect($friendly)->toBe('Jumbo (new)');

    // The bad OR-Set op was quarantined.
    $quarantined = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('table_name', 'merchant_aliases')
        ->where('reason', 'strategy_error')
        ->count();
    expect($quarantined)->toBeGreaterThanOrEqual(1);
});
