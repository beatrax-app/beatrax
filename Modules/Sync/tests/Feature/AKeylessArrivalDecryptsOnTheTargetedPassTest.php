<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Crypto\GdkWrapRecipient;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;
use Modules\Sync\Public\Services\HistoryReprojector;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

// MobileImportInstallAndDecryptTest proves this recovery through
// OpLogRebuilder::rebuild(), calling it "the same primitive InitialSyncPuller
// drives". It is not, any more: the whole-log rebuild cost 645 bytes per entry
// and exhausted the phone's 128 MB ceiling, so the puller was moved to
// HistoryReprojector's targeted pass — and the guard stayed on the primitive
// nothing calls. A paired iPhone held 385 rows quarantined at 01:34, was handed
// its epoch at 01:58, and applied none of them.
/**
 * @link ../../../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */
const TARGETED_EPOCH = 1;

function targetedUser(): User
{
    return User::query()->create([
        'username' => 'targeted-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('applies a keyless arrival once the epoch lands, through the pass the phone runs', function (): void {
    $user = targetedUser();
    $userId = (int) $user->id;
    test()->actingAs($user);

    @unlink(UserDataPathService::appPath('sync/gdk/'.$userId.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $phone = app(DeviceIdentityService::class)->generateAndPersist($userId, $session);
    expect(app(GdkKeyringService::class)->loadKeyring($userId, $session)->epochs())->toBe([]);

    $desktopSigKp = sodium_crypto_sign_keypair();
    $desktopEdSecret = sodium_crypto_sign_secretkey($desktopSigKp);
    $desktopEdPublicHex = sodium_bin2hex(sodium_crypto_sign_publickey($desktopSigKp));

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'desktop-peer',
        'name' => 'Desktop',
        'ed25519_public_key_hex' => $desktopEdPublicHex,
        'x25519_public_key_hex' => bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-09-01T23:34:20Z',
        'confirmed_at' => '2026-09-01T23:34:30Z',
        'last_seen_at' => null,
        'created_at' => '2026-09-01T23:34:20Z',
        'updated_at' => '2026-09-01T23:34:20Z',
    ]);

    $cpId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => 'targeted-merchant-'.bin2hex(random_bytes(4)),
        'display_name' => 'placeholder-ciphertext-stand-in',
        'iban' => null,
        'merchant_name' => null,
        'created_at' => '2026-09-01T23:00:00Z',
        'updated_at' => '2026-09-01T23:00:00Z',
    ]);

    $rawEpochKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $ad = "counterparties:{$cpId}:display_name:".TARGETED_EPOCH;
    $ciphertext = app(OpLogFieldCrypto::class)->encrypt(
        json_encode('Albert Heijn Import', JSON_THROW_ON_ERROR),
        $rawEpochKey,
        $ad,
    );

    $entry = new OpLogEntry(
        table: 'counterparties', pk: $cpId, field: 'display_name', value: $ciphertext,
        hlcL: 1_726_000_000_000, hlcC: 1, deviceId: 'desktop-peer', opType: OpType::Set,
        signature: '', userId: $userId, gdkEpoch: TARGETED_EPOCH,
    );
    $signed = new OpLogEntry(
        table: $entry->table, pk: $entry->pk, field: $entry->field, value: $entry->value,
        hlcL: $entry->hlcL, hlcC: $entry->hlcC, deviceId: $entry->deviceId, opType: $entry->opType,
        signature: (new DeviceKeySigner)->sign($entry->signingPayload(), $desktopEdSecret),
        userId: $entry->userId, gdkEpoch: $entry->gdkEpoch,
    );

    // Arrives before the key, exactly as the phone's catch-up did.
    (new OpLogReplayer(db: $db, deviceKeys: ['desktop-peer' => $desktopEdPublicHex]))
        ->replay([$signed], $userId);

    expect($db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)->where('reason', 'gdk_decrypt_failed')->count())
        ->toBe(1, 'the entry must be held while the keyring is empty');

    // The key, delivered through the same boundary the live push drives.
    $wrap = app(GdkRotationService::class)->buildGdkEpochWrap(
        TARGETED_EPOCH,
        $rawEpochKey,
        new GdkWrapRecipient($phone->deviceId, sodium_hex2bin($phone->x25519PublicKeyHex)),
        'desktop-peer',
        sodium_bin2hex($desktopEdSecret),
    );
    app(GdkEpochDeliveryGateway::class)->receiveEpochWrap(
        json_encode($wrap, JSON_THROW_ON_ERROR), $userId, 'desktop-peer', $phone->deviceId, $session,
    );
    expect(app(GdkKeyringService::class)->loadKeyring($userId, $session)->keyFor(TARGETED_EPOCH))
        ->toBe(sodium_bin2hex($rawEpochKey));

    // THE pass the phone actually runs — InitialSyncPuller::reproject() and
    // DevicesScreenOpening::recoverDeferred() both come through here.
    app(HistoryReprojector::class)->replayQuarantined($userId, $session, null, null);

    $stored = $db->connection()->table('counterparties')->where('id', $cpId)->value('display_name');

    expect(app(SensitiveColumnCodec::class)
        ->decryptRow('counterparties', ['display_name' => $stored], $userId, $session)['display_name'])
        ->toBe('Albert Heijn Import', 'the held entry never reached the projection');
});
