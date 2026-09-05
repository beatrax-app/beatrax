<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogRebuilder;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpCursors;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// A rebuild deletes every row that carries a CreateRow op and replays the log
// to put them back. Refusing the removed device's ops on the way back meant the
// delete stood and the recreate never happened: goals count 0, quarantine full
// of missing_device_key, and the transaction committing without a word.

const REMOVED_PHONE_DEVICE_ID = 'phone-since-removed';

/**
 * @return array{0: int, 1: string}
 */
function seedUserWithRetiredPhone(DatabaseManager $db, string $suffix): array
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'retired-phone-'.$suffix,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $publicHex = bin2hex(sodium_crypto_sign_publickey($keypair));

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => REMOVED_PHONE_DEVICE_ID,
        'name' => 'Old phone',
        'ed25519_public_key_hex' => $publicHex,
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-06-01 00:00:00',
        'confirmed_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return [$userId, bin2hex(sodium_crypto_sign_secretkey($keypair))];
}

/**
 * @param  array<string, ?string>  $fields
 * @return list<OpLogEntry>
 */
function retiredPhoneCreateOps(DeviceKeySigner $signer, string $secretKeyHex, int $userId, array $fields): array
{
    $secretKey = sodium_hex2bin($secretKeyHex);
    $entries = [];
    $hlcL = 1_780_000_000_000;

    foreach ($fields as $field => $value) {
        $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
            table: 'goals',
            pk: 777,
            field: $field,
            value: $value,
            hlcL: $hlcL,
            hlcC: 0,
            deviceId: REMOVED_PHONE_DEVICE_ID,
            opType: OpType::CreateRow,
            signature: $signature,
            userId: $userId,
        );

        $entries[] = $make($signer->sign($make('')->signingPayload(), $secretKey));
        $hlcL++;
    }

    return $entries;
}

/**
 * @return array<string, ?string>
 */
function retiredPhoneGoalFields(): array
{
    return [
        'name' => json_encode('New roof', JSON_THROW_ON_ERROR),
        'target_minor' => json_encode(1500000, JSON_THROW_ON_ERROR),
        'target_currency' => json_encode('EUR', JSON_THROW_ON_ERROR),
        'start_date' => json_encode('2026-06-01', JSON_THROW_ON_ERROR),
        'target_date' => json_encode('2027-06-01', JSON_THROW_ON_ERROR),
    ];
}

it('keeps the rows a removed device created when the op log is rebuilt', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$userId, $secretKeyHex] = seedUserWithRetiredPhone($db, 'rebuild');

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);

    $confirmedKeys = [REMOVED_PHONE_DEVICE_ID => (string) $db->connection()
        ->table('device_registry')->where('user_id', $userId)->value('ed25519_public_key_hex')];

    $replayer = new OpLogReplayer(db: $db, deviceKeys: $confirmedKeys, rules: new MergeRulesRegistry);
    $replayer->replay(retiredPhoneCreateOps($signer, $secretKeyHex, $userId, retiredPhoneGoalFields()), $userId);

    expect($db->connection()->table('goals')->where('user_id', $userId)->count())->toBe(1);

    // Removal, exactly as DevicesAndSyncSettingsSection performs it: revoke
    // first (GdkRotationService), then purge everything keyed to the device.
    $db->connection()->table('device_registry')
        ->where('user_id', $userId)
        ->where('device_id', REMOVED_PHONE_DEVICE_ID)
        ->update(['confirmed_at' => null]);

    $registryId = (int) $db->connection()->table('device_registry')
        ->where('user_id', $userId)->where('device_id', REMOVED_PHONE_DEVICE_ID)->value('id');

    app(DeviceRegistryService::class)->purge($userId, $registryId);

    // The rebuild runs with the CONFIRMED-only map the provider hands it,
    // which no longer names the removed phone.
    $rebuilder = new OpLogRebuilder(
        $db,
        new OpLogReplayer(db: $db, deviceKeys: [], rules: new MergeRulesRegistry),
        new MergeRulesRegistry,
        ['goals'],
    );
    $rebuilder->rebuild($userId);

    expect($db->connection()->table('goals')->where('user_id', $userId)->count())->toBe(1)
        ->and($db->connection()->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->where('reason', QuarantineReason::MissingDeviceKey->value)
            ->count())->toBe(0);
});

it('keeps the rows of an author whose registry row was already deleted by an older removal', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$userId, $secretKeyHex] = seedUserWithRetiredPhone($db, 'legacy');

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);

    $confirmedKeys = [REMOVED_PHONE_DEVICE_ID => (string) $db->connection()
        ->table('device_registry')->where('user_id', $userId)->value('ed25519_public_key_hex')];

    $replayer = new OpLogReplayer(db: $db, deviceKeys: $confirmedKeys, rules: new MergeRulesRegistry);
    $replayer->replay(retiredPhoneCreateOps($signer, $secretKeyHex, $userId, retiredPhoneGoalFields()), $userId);

    // The state an install left in by the version of purge() that deleted the
    // row: the key is gone for good, and only the durable log remains.
    $db->connection()->table('device_registry')
        ->where('user_id', $userId)
        ->where('device_id', REMOVED_PHONE_DEVICE_ID)
        ->delete();

    $rebuilder = new OpLogRebuilder(
        $db,
        new OpLogReplayer(db: $db, deviceKeys: [], rules: new MergeRulesRegistry),
        new MergeRulesRegistry,
        ['goals'],
    );
    $rebuilder->rebuild($userId);

    expect($db->connection()->table('goals')->where('user_id', $userId)->count())->toBe(1);
});

it('still refuses an op signed by a device this user never paired with', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$userId, $secretKeyHex] = seedUserWithRetiredPhone($db, 'stranger');

    $db->connection()->table('device_registry')->where('user_id', $userId)->delete();

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);

    $replayer = new OpLogReplayer(db: $db, deviceKeys: [], rules: new MergeRulesRegistry);
    $replayer->replay(retiredPhoneCreateOps($signer, $secretKeyHex, $userId, retiredPhoneGoalFields()), $userId);

    expect($db->connection()->table('goals')->where('user_id', $userId)->count())->toBe(0)
        ->and($db->connection()->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->where('reason', QuarantineReason::MissingDeviceKey->value)
            ->count())->toBeGreaterThan(0);
});

it('refuses a NEW op signed by a device whose confirmation is gone, key retained and all', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$userId, $secretKeyHex] = seedUserWithRetiredPhone($db, 'unconfirmed');

    // Removal as the settings section performs it: the row and its Ed25519 key
    // stay, so the history this device already accepted can still be read back.
    $db->connection()->table('device_registry')
        ->where('user_id', $userId)
        ->where('device_id', REMOVED_PHONE_DEVICE_ID)
        ->update(['confirmed_at' => null]);

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);

    // Ops this device has never held: nothing in op_log_entries names them, so
    // the retained key is the only thing that could ever admit them.
    $entries = retiredPhoneCreateOps($signer, $secretKeyHex, $userId, retiredPhoneGoalFields());

    $replayer = new OpLogReplayer(
        db: $db,
        deviceKeys: app(DeviceRegistryService::class)->deviceKeys($userId),
        rules: new MergeRulesRegistry,
    );
    $replayer->replay($entries, $userId);

    expect($db->connection()->table('goals')->where('user_id', $userId)->count())->toBe(0)
        ->and($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())->toBe(0)
        ->and($db->connection()->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->where('reason', QuarantineReason::UnconfirmedDevice->value)
            ->count())->toBe(count($entries))
        // The registry row is right there with its key in it, so an audit line
        // blaming a missing key would name a cause the reader can disprove.
        ->and($db->connection()->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->where('reason', QuarantineReason::MissingDeviceKey->value)
            ->count())->toBe(0);
});

it('offers a removed device\'s ops to the peer catching up instead of withholding them', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$userId, $secretKeyHex] = seedUserWithRetiredPhone($db, 'catchup');

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);

    $confirmedKeys = [REMOVED_PHONE_DEVICE_ID => (string) $db->connection()
        ->table('device_registry')->where('user_id', $userId)->value('ed25519_public_key_hex')];

    $replayer = new OpLogReplayer(db: $db, deviceKeys: $confirmedKeys, rules: new MergeRulesRegistry);
    $replayer->replay(retiredPhoneCreateOps($signer, $secretKeyHex, $userId, retiredPhoneGoalFields()), $userId);

    $db->connection()->table('device_registry')
        ->where('user_id', $userId)
        ->where('device_id', REMOVED_PHONE_DEVICE_ID)
        ->update(['confirmed_at' => null]);

    $exchanger = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger);

    expect($exchanger->opsAfterWatermark($userId, PeerCatchUpCursors::none()))->not->toHaveCount(0);
});
