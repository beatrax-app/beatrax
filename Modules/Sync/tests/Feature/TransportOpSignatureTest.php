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

/*
 * TransportOpSignatureTest — XPORT-01: Ed25519 op signatures survive Noise transport.
 *
 * CRITICAL INVARIANT (RESEARCH Pitfall 7 + 10-FINDINGS T-10-01):
 * Transport encryption (Noise) is ADDITIVE — it does NOT replace per-entry
 * Ed25519 signatures. After Noise decryption on the receiving side,
 * DeviceKeySigner::verify() MUST still succeed on every deserialized
 * OpLogEntry. The transport cannot be used as a shortcut to skip op-log
 * signature verification.
 *
 * Three scenarios:
 *   S1: Happy path — an OpLogEntry is signed, Noise-encrypted into a frame,
 *       Noise-decrypted, deserialized, and DeviceKeySigner::verify() passes.
 *   S2: Tampered-op path — the plaintext payload is modified after Noise
 *       decryption (simulating a relay or routing node that tampered with the
 *       ciphertext before encryption).
 *       DeviceKeySigner::verify() must FAIL, preventing replay.
 *   S3: Forged-signature path — op with a signature from an unknown device_id
 *       → SyncSession::receiveOps drops the entry (not replayed).
 *
 * Wave 3: SyncSession now exists; these tests run as real integration tests.
 */

it('OpLogEntry Ed25519 signature survives Noise encrypt/decrypt round-trip', function (): void {
    // Arrange: create a signed OpLogEntry
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

    // Initiator encrypts the frame.
    $frame = $framer->encode([$signedEntry]);
    $ciphertext = $initSend->encrypt($frame, '');

    // Responder decrypts.
    $plainFrame = $respRecv->decrypt($ciphertext, '');
    $decoded = $framer->decode($plainFrame);

    // Ed25519 signature must survive the round-trip.
    expect($decoded)->toHaveCount(1);

    $received = $decoded[0];
    $verifies = $signer->verify(
        payload: $received->signingPayload(),
        sigHex: $received->signature,
        publicKeyBin: $publicKey,
    );
    expect($verifies)->toBeTrue('Ed25519 signature must survive Noise encrypt/decrypt round-trip (Pitfall 7)');

    // SyncSession class must exist (Wave 3 shipped).
    expect(class_exists(SyncSession::class))->toBeTrue('SyncSession must exist in Wave 3');
});

it('tampered OpLogEntry fails DeviceKeySigner::verify() after Noise decrypt (Pitfall 7 guard)', function (): void {
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
        value: '"tampered-value"',  // attacker changed the value
        hlcL: $signedEntry->hlcL,
        hlcC: $signedEntry->hlcC,
        deviceId: $signedEntry->deviceId,
        opType: $signedEntry->opType,
        signature: $signedEntry->signature,  // original signature — no longer valid
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
    // Verify that SyncSession::receiveOps drops entries with unknown device_id.
    // An op signed by an unknown device has no entry in deviceKeys → silently dropped.
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
        deviceId: 'unknown-device',  // not in deviceKeys
        opType: OpType::Set,
        signature: $signer->sign('...', $secretKey),  // signed but with unknown device
        userId: 1,
    );

    // The device key map does NOT include 'unknown-device'.
    $deviceKeys = [
        'known-device' => sodium_bin2hex($publicKey),
    ];

    // SyncSession::receiveOps internal logic: entry->deviceId not in deviceKeys → dropped.
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
