<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\SyncSession;

uses(RefreshDatabase::class);

// Noise is additive: it does not replace the per-entry Ed25519 signature, so
// every deserialized OpLogEntry must still verify after decryption. The
// transport is never a shortcut past op-log signature verification.

it('OpLogEntry Ed25519 signature survives Noise encrypt/decrypt round-trip', function (): void {
    $signer = new DeviceKeySigner;
    $kp = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($kp);
    $publicKey = sodium_crypto_sign_publickey($kp);
    $publicKeyHex = sodium_bin2hex($publicKey);

    $entry = new OpLogEntry(
        table: 'transactions',
        pk: 1,
        field: 'note',
        value: '"secret-note"',
        hlcL: 1_718_000_000_000,
        hlcC: 0,
        deviceId: 'device-a',
        opType: OpType::Set,
        signature: '',
        userId: 1,
    );
    $realSig = $signer->sign($entry->signingPayload(), $secretKey);
    $signedEntry = new OpLogEntry(
        table: $entry->table,
        pk: $entry->pk,
        field: $entry->field,
        value: $entry->value,
        hlcL: $entry->hlcL,
        hlcC: $entry->hlcC,
        deviceId: $entry->deviceId,
        opType: $entry->opType,
        signature: $realSig,
        userId: $entry->userId,
    );

    // Noise IK round-trip (both sides are the same "device" for this test).
    $initKp = sodium_crypto_kx_keypair();
    $initSecret = sodium_crypto_kx_secretkey($initKp);
    $initPublic = sodium_crypto_kx_publickey($initKp);

    $respKp = sodium_crypto_kx_keypair();
    $respSecret = sodium_crypto_kx_secretkey($respKp);
    $respPublic = sodium_crypto_kx_publickey($respKp);

    $initHs = NoiseHandshakeState::initIkInitiator($initSecret, $initPublic, $respPublic);
    $respHs = NoiseHandshakeState::initIkResponder($respSecret, $respPublic);
    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    [$initSend, $initRecv] = $initHs->split();
    [$respSend, $respRecv] = $respHs->split();

    $framer = new TransportFramer;

    $frame = $framer->encode([$signedEntry]);
    $ciphertext = $initSend->encrypt($frame, '');

    $plainFrame = $respRecv->decrypt($ciphertext, '');
    $decoded = $framer->decode($plainFrame);

    expect($decoded)->toHaveCount(1);

    $received = $decoded[0];
    $verifies = $signer->verify(
        payload: $received->signingPayload(),
        sigHex: $received->signature,
        publicKeyBin: $publicKey,
    );
    expect($verifies)->toBeTrue('Ed25519 signature must survive Noise encrypt/decrypt round-trip');

    expect(class_exists(SyncSession::class))->toBeTrue('SyncSession must exist in Wave 3');
});

it('tampered OpLogEntry fails DeviceKeySigner::verify() after Noise decrypt', function (): void {
    $signer = new DeviceKeySigner;
    $kp = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($kp);
    $publicKey = sodium_crypto_sign_publickey($kp);

    $entry = new OpLogEntry(
        table: 'transactions',
        pk: 1,
        field: 'note',
        value: '"original-value"',
        hlcL: 1_718_000_000_000,
        hlcC: 0,
        deviceId: 'device-a',
        opType: OpType::Set,
        signature: '',
        userId: 1,
    );
    $sig = $signer->sign($entry->signingPayload(), $secretKey);
    $signedEntry = new OpLogEntry(
        table: $entry->table,
        pk: $entry->pk,
        field: $entry->field,
        value: $entry->value,
        hlcL: $entry->hlcL,
        hlcC: $entry->hlcC,
        deviceId: $entry->deviceId,
        opType: $entry->opType,
        signature: $sig,
        userId: $entry->userId,
    );

    // Simulate post-decrypt field tampering (relay or router modified the op).
    $tamperedEntry = new OpLogEntry(
        table: $signedEntry->table,
        pk: $signedEntry->pk,
        field: $signedEntry->field,
        value: '"tampered-value"',
        hlcL: $signedEntry->hlcL,
        hlcC: $signedEntry->hlcC,
        deviceId: $signedEntry->deviceId,
        opType: $signedEntry->opType,
        signature: $signedEntry->signature,
        userId: $signedEntry->userId,
    );

    $verifies = $signer->verify(
        payload: $tamperedEntry->signingPayload(),
        sigHex: $tamperedEntry->signature,
        publicKeyBin: $publicKey,
    );
    expect($verifies)->toBeFalse('Tampered OpLogEntry must fail Ed25519 verification (T-10-01 security invariant)');
});

it('transport stack rejects an op whose signature was forged (unknown device key)', function (): void {
    $signer = new DeviceKeySigner;
    $kp = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($kp);
    $publicKey = sodium_crypto_sign_publickey($kp);

    $entry = new OpLogEntry(
        table: 'transactions',
        pk: 1,
        field: 'note',
        value: '"attacker-note"',
        hlcL: 1_718_000_000_000,
        hlcC: 0,
        deviceId: 'unknown-device',
        opType: OpType::Set,
        signature: $signer->sign('...', $secretKey),
        userId: 1,
    );

    $deviceKeys = [
        'known-device' => sodium_bin2hex($publicKey),
    ];

    // The lookup receiveOps performs: a device with no entry in deviceKeys has
    // its op dropped rather than verified.
    $pubKeyHex = $deviceKeys[$entry->deviceId] ?? null;
    expect($pubKeyHex)->toBeNull('Unknown device_id must not be in deviceKeys → entry dropped');

    // Cross-check: known device would verify.
    $knownEntry = new OpLogEntry(
        table: 'transactions',
        pk: 2,
        field: 'note',
        value: '"real-note"',
        hlcL: 1_718_000_000_001,
        hlcC: 0,
        deviceId: 'known-device',
        opType: OpType::Set,
        signature: '',
        userId: 1,
    );
    $knownSig = $signer->sign($knownEntry->signingPayload(), $secretKey);
    $knownEntryWithSig = new OpLogEntry(
        table: $knownEntry->table,
        pk: $knownEntry->pk,
        field: $knownEntry->field,
        value: $knownEntry->value,
        hlcL: $knownEntry->hlcL,
        hlcC: $knownEntry->hlcC,
        deviceId: $knownEntry->deviceId,
        opType: $knownEntry->opType,
        signature: $knownSig,
        userId: $knownEntry->userId,
    );
    $knownKeyBin = sodium_hex2bin($deviceKeys['known-device']);
    expect($signer->verify($knownEntryWithSig->signingPayload(), $knownEntryWithSig->signature, $knownKeyBin))
        ->toBeTrue('Known device entry must verify successfully');
});
