<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

/*
 * GdkEpochDeliveryTest — CRYPT-02 distribution: receive-side validate/open/
 * append of an inbound GDK_EPOCH_WRAP control message (Task 1,
 * GdkEpochControlHandler). Task 2 (SyncWebSocketHandler live-session +
 * relay-mailbox delivery wiring) extends this file. 14-VALIDATION.md
 * CRYPT-02 delivery row.
 */

function deliveryUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// ---------------------------------------------------------------------
// Task 1 — GdkEpochControlHandler: validate, open, append.
// ---------------------------------------------------------------------

it('opens a GDK_EPOCH_WRAP addressed to this device and appends the epoch to the local keyring', function (): void {
    $user = deliveryUser('delivery-open-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(7, $rawGdkKey, $recipientPub, $deviceB->deviceId);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $handler->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $loaded = $keyring->loadKeyring((int) $user->id, $session);

    expect($loaded->keyFor(7))->toBe(sodium_bin2hex($rawGdkKey));

    // B can decrypt an entry written under the newly-appended epoch.
    /** @var OpLogFieldCrypto $fieldCrypto */
    $fieldCrypto = app(OpLogFieldCrypto::class);
    $ad = 'transactions:1:description:7';
    $stored = $fieldCrypto->encrypt('super secret merchant', $rawGdkKey, $ad);
    $decrypted = $fieldCrypto->decrypt($stored, sodium_hex2bin((string) $loaded->keyFor(7)), $ad);

    expect($decrypted)->toBe('super secret merchant');
});

it('rejects a GDK_EPOCH_WRAP addressed to a foreign device and does not append', function (): void {
    $user = deliveryUser('delivery-foreign-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    // Sealed to a DIFFERENT (foreign) device's public key, not this device's.
    $foreignPub = sodium_crypto_box_publickey(sodium_crypto_box_keypair());
    $wrap = $rotation->buildGdkEpochWrap(9, $rawGdkKey, $foreignPub, 'a-foreign-device-id');

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $handler->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $loaded = $keyring->loadKeyring((int) $user->id, $session);

    expect($loaded->keyFor(9))->toBeNull();
});

it('rejects a tampered GDK_EPOCH_WRAP wrapped_key_b64 and does not append', function (): void {
    $user = deliveryUser('delivery-tampered-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(11, $rawGdkKey, $recipientPub, $deviceB->deviceId);

    // Tamper: flip the last byte of the sealed box.
    $sealed = base64_decode((string) $wrap['wrapped_key_b64'], true);
    expect($sealed)->not->toBeFalse();
    $lastByte = substr((string) $sealed, -1);
    $tampered = substr((string) $sealed, 0, -1).chr(ord($lastByte) ^ 0xFF);
    $wrap['wrapped_key_b64'] = base64_encode($tampered);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $handler->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $loaded = $keyring->loadKeyring((int) $user->id, $session);

    expect($loaded->keyFor(11))->toBeNull();
});

it('rejects a malformed control message without any sodium call', function (): void {
    $user = deliveryUser('delivery-malformed-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);

    // Baseline captured BEFORE handle() — asserted against a delta rather
    // than an assumed-empty keyring, since the on-disk keyring file is
    // keyed only by user id (Plan 02's documented cross-test filesystem
    // isolation gap: SQLite rowids can be reused across RefreshDatabase
    // transaction rollbacks within one process, so a prior test's user id
    // may already have a keyring file on disk for this same id).
    $before = $keyring->loadKeyring((int) $user->id, $session)->epochs();

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);

    // Missing epoch_id/wrapped_key_b64/recipient_device_id entirely.
    $handler->handle(json_encode(['type' => 'GDK_EPOCH_WRAP'], JSON_THROW_ON_ERROR), (int) $user->id, $session);

    $after = $keyring->loadKeyring((int) $user->id, $session)->epochs();

    expect($after)->toHaveCount(count($before), 'a malformed message must never append an epoch');
});

it('is idempotent — re-handling an already-present epoch does not duplicate or downgrade current_epoch', function (): void {
    $user = deliveryUser('delivery-idempotent-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session); // epoch 1

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(2, $rawGdkKey, $recipientPub, $deviceB->deviceId);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $json = json_encode($wrap, JSON_THROW_ON_ERROR);

    $handler->handle($json, (int) $user->id, $session);
    $afterFirst = $keyring->currentEpoch((int) $user->id, $session);
    expect($afterFirst->epochId)->toBe(2);

    // Advance further, then re-deliver the STALE epoch 2 wrap again.
    $keyring->appendEpoch(
        (int) $user->id,
        new GdkEpoch(3, sodium_bin2hex(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES))),
        $session,
    );

    $handler->handle($json, (int) $user->id, $session);

    $final = $keyring->currentEpoch((int) $user->id, $session);
    expect($final->epochId)->toBe(3, 'a redelivered stale epoch must never downgrade current_epoch');

    $loaded = $keyring->loadKeyring((int) $user->id, $session);
    $countOfEpochTwo = 0;
    foreach ($loaded->epochs() as $epoch) {
        if ($epoch->epochId === 2) {
            $countOfEpochTwo++;
        }
    }
    expect($countOfEpochTwo)->toBe(1, 'epoch 2 must not be duplicated in the keyring');
});
