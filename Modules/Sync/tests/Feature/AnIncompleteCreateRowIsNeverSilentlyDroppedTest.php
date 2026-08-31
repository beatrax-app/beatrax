<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// PeerCatchUpExchanger splits frames on op count and byte budget with no
// (table, pk) awareness, and SyncSession replays one frame at a time, so a
// row's CreateRow ops can arrive split. Both halves of what that used to cost:
// the registry never named goals.start_date, and insertOrIgnore then swallowed
// the NOT NULL violation — no row, no quarantine row, and a `true` return.

/**
 * @param  array<string, ?string>  $fields
 * @return list<OpLogEntry>
 */
function createRowOpsFor(
    DeviceKeySigner $signer,
    string $secretKey,
    int $userId,
    string $table,
    int $pk,
    array $fields,
): array {
    $entries = [];
    $hlcL = 1_700_000_000_000;

    foreach ($fields as $field => $value) {
        $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
            table: $table,
            pk: $pk,
            field: $field,
            value: $value,
            hlcL: $hlcL,
            hlcC: 0,
            deviceId: 'device-split-frame',
            opType: OpType::CreateRow,
            signature: $signature,
            userId: $userId,
        );

        $entries[] = $make($signer->sign($make('')->signingPayload(), $secretKey));
        $hlcL++;
    }

    return $entries;
}

function seedSplitFrameUser(DatabaseManager $db): int
{
    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'split-frame-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('quarantines a create whose ops arrived split across frames instead of writing no row and reporting success', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = seedSplitFrameUser($db);

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);
    $keypair = sodium_crypto_sign_keypair();

    $replayer = new OpLogReplayer(
        db: $db,
        deviceKeys: ['device-split-frame' => bin2hex(sodium_crypto_sign_publickey($keypair))],
        rules: new MergeRulesRegistry,
    );

    // The tail of the row — start_date and target_date — landed in the next
    // frame and never reached this replay.
    $replayer->replay(
        createRowOpsFor($signer, sodium_crypto_sign_secretkey($keypair), $userId, 'goals', 4242, [
            'name' => json_encode('Holiday', JSON_THROW_ON_ERROR),
            'target_minor' => json_encode(250000, JSON_THROW_ON_ERROR),
            'target_currency' => json_encode('EUR', JSON_THROW_ON_ERROR),
        ]),
        $userId,
    );

    expect($db->connection()->table('goals')->where('user_id', $userId)->count())->toBe(0);

    $reasons = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('table_name', 'goals')
        ->pluck('reason')
        ->all();

    expect($reasons)->not->toBeEmpty()
        ->and($reasons)->toContain(QuarantineReason::IncompleteCreateRow->value);
});

it('quarantines a create the database refuses instead of reporting success and writing nothing', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = seedSplitFrameUser($db);

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);
    $keypair = sodium_crypto_sign_keypair();

    $replayer = new OpLogReplayer(
        db: $db,
        deviceKeys: ['device-split-frame' => bin2hex(sodium_crypto_sign_publickey($keypair))],
        rules: new MergeRulesRegistry,
    );

    // Every required field is PRESENT, so the completeness gate passes and the
    // refusal happens at the INSERT — the case a longer `_create_required` can
    // never catch, because a null value is not a missing op.
    $replayer->replay(
        createRowOpsFor($signer, sodium_crypto_sign_secretkey($keypair), $userId, 'goals', 4343, [
            'name' => json_encode('Kitchen', JSON_THROW_ON_ERROR),
            'target_minor' => json_encode(500000, JSON_THROW_ON_ERROR),
            'target_currency' => json_encode('EUR', JSON_THROW_ON_ERROR),
            'start_date' => null,
            'target_date' => json_encode('2027-01-01', JSON_THROW_ON_ERROR),
        ]),
        $userId,
    );

    expect($db->connection()->table('goals')->where('user_id', $userId)->count())->toBe(0);

    $reasons = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('table_name', 'goals')
        ->pluck('reason')
        ->all();

    expect($reasons)->toContain(QuarantineReason::IncompleteCreateRow->value);
});

it('stays silent when a replayed create names a row the database already holds', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = seedSplitFrameUser($db);

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);
    $keypair = sodium_crypto_sign_keypair();

    $replayer = new OpLogReplayer(
        db: $db,
        deviceKeys: ['device-split-frame' => bin2hex(sodium_crypto_sign_publickey($keypair))],
        rules: new MergeRulesRegistry,
    );

    $ops = createRowOpsFor($signer, sodium_crypto_sign_secretkey($keypair), $userId, 'goals', 4444, [
        'name' => json_encode('Bike', JSON_THROW_ON_ERROR),
        'target_minor' => json_encode(90000, JSON_THROW_ON_ERROR),
        'target_currency' => json_encode('EUR', JSON_THROW_ON_ERROR),
        'start_date' => json_encode('2026-01-01', JSON_THROW_ON_ERROR),
        'target_date' => json_encode('2027-01-01', JSON_THROW_ON_ERROR),
    ]);

    $replayer->replay($ops, $userId);
    $replayer->replay($ops, $userId);

    expect($db->connection()->table('goals')->where('user_id', $userId)->count())->toBe(1)
        ->and($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);
});
