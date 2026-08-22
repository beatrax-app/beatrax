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
use Modules\Sync\Internal\OpLog\OpLogRebuilder;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

function importInstallUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('an empty-keyring phone quarantines a sensitive entry, then installs the delivered epoch and decrypts it on rebuild', function (): void {
    $user = importInstallUser('import-install-decrypt');
    $userId = (int) $user->id;
    test()->actingAs($user);

    // A prior test process can leave a keyring file on disk for this same reused
    // numeric user id: SQLite rowids come back after a RefreshDatabase rollback,
    // and the keyring file lives outside the transaction.
    @unlink(UserDataPathService::appPath('sync/gdk/'.$userId.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // The phone gets a real device identity but deliberately no keyring: nothing
    // here calls GdkKeyringService::generateAndPersist().
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $phone = $identityService->generateAndPersist($userId, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    expect($keyring->loadKeyring($userId, $session)->epochs())->toBe([]);

    // The desktop needs a real Ed25519 keypair to sign the entry below. Its X25519
    // key only fills the device_registry row and the trust map, since the
    // recipient of the epoch wrap is the phone.
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
        'paired_at' => '2026-07-14T10:00:00Z',
        'confirmed_at' => '2026-07-14T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-14T10:00:00Z',
        'updated_at' => '2026-07-14T10:00:00Z',
    ]);

    // A pre-existing import-created row, as catch-up's CreateRow ops would leave
    // it. rebuild() preserves rows with no CreateRow op in the log, so the SET op
    // below replays on top of this one.
    $cpId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => 'import-merchant-'.bin2hex(random_bytes(4)),
        'display_name' => 'placeholder-ciphertext-stand-in',
        'iban' => null,
        'merchant_name' => null,
        'created_at' => '2026-07-14T09:00:00Z',
        'updated_at' => '2026-07-14T09:00:00Z',
    ]);

    // The desktop's epoch 1 raw key, delivered further down.
    $rawDesktopEpochKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

    /** @var OpLogFieldCrypto $fieldCrypto */
    $fieldCrypto = app(OpLogFieldCrypto::class);
    $ad = "counterparties:{$cpId}:display_name:1";
    // The op-log 'value' column is JSON-encoded before encryption, the way
    // OpLogWriter::writeSet() does it, because the decrypted output flows into the
    // same unconditional json_decode() every plaintext value takes. There is no
    // raw-string fallback to land in.
    $ciphertext = $fieldCrypto->encrypt(json_encode('Albert Heijn Import', JSON_THROW_ON_ERROR), $rawDesktopEpochKey, $ad);

    $entry = new OpLogEntry(
        table: 'counterparties',
        pk: $cpId,
        field: 'display_name',
        value: $ciphertext,
        hlcL: 1_726_000_000_000,
        hlcC: 1,
        deviceId: 'desktop-peer',
        opType: OpType::Set,
        signature: '',
        userId: $userId,
        gdkEpoch: 1,
    );

    $signer = new DeviceKeySigner;
    $signedEntry = new OpLogEntry(
        table: $entry->table,
        pk: $entry->pk,
        field: $entry->field,
        value: $entry->value,
        hlcL: $entry->hlcL,
        hlcC: $entry->hlcC,
        deviceId: $entry->deviceId,
        opType: $entry->opType,
        signature: $signer->sign($entry->signingPayload(), $desktopEdSecret),
        userId: $entry->userId,
        gdkEpoch: $entry->gdkEpoch,
    );

    // First replay, with the phone's keyring genuinely empty.
    $replayer = new OpLogReplayer(db: $db, deviceKeys: ['desktop-peer' => $desktopEdPublicHex]);
    $replayer->replay([$signedEntry], $userId);

    // The entry persists as ciphertext even though it could not be applied to the
    // projection.
    $persisted = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'counterparties')
        ->where('field', 'display_name')
        ->where('pk', (string) $cpId)
        ->first();
    expect($persisted)->not->toBeNull();
    expect($persisted->value)->toBe($ciphertext);

    $quarantinedBefore = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('reason', 'gdk_decrypt_failed')
        ->count();
    expect($quarantinedBefore)->toBe(1, 'the sensitive entry must quarantine gdk_decrypt_failed while the keyring is empty');

    // The projection is untouched: no garbage, no plaintext leak.
    $displayNameBefore = $db->connection()->table('counterparties')->where('id', $cpId)->value('display_name');
    expect($displayNameBefore)->toBe('placeholder-ciphertext-stand-in');

    // Delivered through the same receive-side boundary LanSyncClient's
    // post-catch-up step drives.
    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $recipientPub = sodium_hex2bin($phone->x25519PublicKeyHex);
    // The desktop-peer is a confirmed device in the phone's registry, so its
    // Ed25519 secret is the authentic signer for the sender-authenticity gate.
    $wrap = $rotation->buildGdkEpochWrap(1, $rawDesktopEpochKey, new GdkWrapRecipient($phone->deviceId, $recipientPub), 'desktop-peer', sodium_bin2hex($desktopEdSecret));

    /** @var GdkEpochDeliveryGateway $delivery */
    $delivery = app(GdkEpochDeliveryGateway::class);
    $delivery->receiveEpochWrap(json_encode($wrap, JSON_THROW_ON_ERROR), $userId, 'desktop-peer', $phone->deviceId, $session);

    $loaded = $keyring->loadKeyring($userId, $session);
    expect($loaded->keyFor(1))->toBe(sodium_bin2hex($rawDesktopEpochKey), 'the delivered epoch must now be present in the phone keyring');

    // Re-projected through the same primitive InitialSyncPuller drives.
    /** @var OpLogRebuilder $rebuilder */
    $rebuilder = app(OpLogRebuilder::class);
    $rebuilder->rebuild($userId);

    $displayNameAfter = $db->connection()->table('counterparties')->where('id', $cpId)->value('display_name');
    expect($displayNameAfter)->not->toBe('Albert Heijn Import', 'the projection column stays ciphertext at rest');
    expect($displayNameAfter)->not->toBe('placeholder-ciphertext-stand-in');

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    $decryptedRow = $codec->decryptRow('counterparties', ['display_name' => $displayNameAfter], $userId, $session);
    expect($decryptedRow['display_name'])->toBe('Albert Heijn Import', 'the entry now decrypts once the keyring holds the desktop epoch');

    // rebuild() never deletes quarantine history, so the original pre-install row
    // is expected to still be there.
    $quarantinedAfter = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('reason', 'gdk_decrypt_failed')
        ->count();
    expect($quarantinedAfter)->toBe($quarantinedBefore, 'a successful rebuild must not quarantine the now-decryptable entry again');
});
