<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
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

/*
 * MobileImportInstallAndDecryptTest — Task 4 (Phase 15 import-join, G3 +
 * O1): an empty-keyring phone installs a delivered desktop epoch and
 * decrypts previously-quarantined history. 6d per 15-import-join-PLAN.md's
 * test-plan.
 *
 * Mirrors GdkEpochDeliveryTest's Direction-2 (drain own inbound mailbox)
 * pattern for the install step, and OpLogFieldEncryptionTest's
 * read-entry-back-and-replay shape for the decrypt/quarantine assertions.
 */

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

    // A prior, unrelated test process may have left a keyring file on disk
    // for this same reused numeric user id (SQLite rowids are reused
    // across RefreshDatabase transaction rollbacks — GdkEpochDeliveryTest's
    // own documented cross-test filesystem-isolation gap). Start from a
    // genuinely empty on-disk state.
    @unlink(UserDataPathService::appPath('sync/gdk/'.$userId.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);
    (new LockStateManager)->unlock($session, str_repeat('k', 32));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // PHONE: a real device identity, deliberately EMPTY keyring (B2 posture
    // — no GdkKeyringService::generateAndPersist() call anywhere here).
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $phone = $identityService->generateAndPersist($userId, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    expect($keyring->loadKeyring($userId, $session)->epochs())->toBe([]);

    // DESKTOP: a confirmed non-self device with a REAL Ed25519 signing
    // keypair (needed to sign the entry below) and X25519 keypair (the
    // recipient of the eventual epoch wrap is the PHONE, not the desktop —
    // the desktop's own X25519 key here is only used for the
    // device_registry row / deviceKeys() trust map, mirroring
    // LanDirectSessionTest's fixture shape).
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

    // A pre-existing "import-created" counterparties row (mirrors how a
    // real fresh phone would have this row from catch-up's CreateRow ops —
    // simplified here to a direct insert, matching MobileEncryptedCopyTest's
    // established fixture idiom). rebuild() PRESERVES rows without a
    // CreateRow op in the log, so the SET op below replays on top of it.
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

    // The desktop's epoch 1 raw key — this is what will be DELIVERED later.
    $rawDesktopEpochKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

    /** @var OpLogFieldCrypto $fieldCrypto */
    $fieldCrypto = app(OpLogFieldCrypto::class);
    $ad = "counterparties:{$cpId}:display_name:1";
    // The op-log 'value' column is always JSON-encoded BEFORE encryption
    // (mirrors the real OpLogWriter::writeSet() encrypt-on-write hook) —
    // decryptForStrategy()'s decrypted output flows into the SAME
    // json_decode()-based strategy/resolve pipeline every plaintext op-log
    // value uses (SE-07: unconditional json_decode, no raw-string fallback).
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

    // --- First replay: the phone's keyring is genuinely empty. ---
    $replayer = new OpLogReplayer(db: $db, deviceKeys: ['desktop-peer' => $desktopEdPublicHex]);
    $replayer->replay([$signedEntry], $userId);

    // The entry is durably persisted (ciphertext) even though it could not
    // be applied to the projection.
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

    // The projection is untouched — no garbage, no plaintext leak.
    $displayNameBefore = $db->connection()->table('counterparties')->where('id', $cpId)->value('display_name');
    expect($displayNameBefore)->toBe('placeholder-ciphertext-stand-in');

    // --- Deliver the desktop's epoch via the SAME receive-side boundary
    // LanSyncClient's post-catch-up step now drives (GdkEpochDeliveryGateway
    // -> GdkEpochControlHandler::handle()). ---
    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $recipientPub = sodium_hex2bin($phone->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(1, $rawDesktopEpochKey, $recipientPub, $phone->deviceId);

    /** @var GdkEpochDeliveryGateway $delivery */
    $delivery = app(GdkEpochDeliveryGateway::class);
    $delivery->receiveEpochWrap(json_encode($wrap, JSON_THROW_ON_ERROR), $userId, $session);

    $loaded = $keyring->loadKeyring($userId, $session);
    expect($loaded->keyFor(1))->toBe(sodium_bin2hex($rawDesktopEpochKey), 'the delivered epoch must now be present in the phone keyring');

    // --- Re-project via the SAME primitive InitialSyncPuller drives
    // (HistoryReprojector -> OpLogRebuilder::rebuild()). ---
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

    // No NEW quarantine row was added for this entry during the successful
    // rebuild pass (the original pre-install quarantine row remains as a
    // historical record — rebuild never deletes quarantine history).
    $quarantinedAfter = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('reason', 'gdk_decrypt_failed')
        ->count();
    expect($quarantinedAfter)->toBe($quarantinedBefore, 'a successful rebuild must not quarantine the now-decryptable entry again');
});
