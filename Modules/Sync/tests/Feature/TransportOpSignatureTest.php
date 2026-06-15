<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

/*
 * TransportOpSignatureTest — XPORT-01: Ed25519 op signatures survive Noise transport.
 *
 * RED until Wave 3 ships TransportFramer + SyncSession under
 * Modules\Sync\Internal\Transport\.
 *
 * CRITICAL INVARIANT (RESEARCH Pitfall 7 + 10-FINDINGS T-10-01):
 * Transport encryption (Noise) is ADDITIVE — it does NOT replace per-entry
 * Ed25519 signatures. After Noise decryption on the receiving side,
 * DeviceKeySigner::verify() MUST still succeed on every deserialized
 * OpLogEntry. The transport cannot be used as a shortcut to skip op-log
 * signature verification.
 *
 * Two scenarios:
 *   S1: Happy path — an OpLogEntry is signed, Noise-encrypted into a frame,
 *       Noise-decrypted, deserialized, and DeviceKeySigner::verify() passes.
 *   S2: Tampered-op path — the plaintext payload is modified after Noise
 *       decryption (simulating a relay or routing node that tampered with the
 *       ciphertext, evading AEAD because the tamper was before encryption).
 *       DeviceKeySigner::verify() must FAIL, preventing replay.
 *
 * The test also asserts that an OpLogEntry decrypted from a Noise frame still
 * passes DeviceKeySigner::verify() — this is the precise XPORT-01 acceptance
 * criterion from the plan's Per-Task Verification Map.
 */

it('OpLogEntry Ed25519 signature survives Noise encrypt/decrypt round-trip', function (): void {
    // Arrange: create a signed OpLogEntry
    $signer = new DeviceKeySigner();
    $kp = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($kp);
    $publicKey = sodium_crypto_sign_publickey($kp);

    $entry = new OpLogEntry(
        table: 'transactions',
        pk: 1,
        field: 'note',
        value: 'secret-note',
        hlcL: 1_718_000_000_000,
        hlcC: 0,
        deviceId: 'device-a',
        opType: OpType::SET,
        signature: $signer->sign(
            payload: 'payload',  // placeholder; real signingPayload built below
            secretKeyBin: $secretKey,
        ),
        userId: 1,
    );

    // Re-sign with the real signing payload.
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

    // The Noise session + framer don't exist yet (Wave 3).
    // When they exist: encrypt $signedEntry via NoiseSession, decrypt on the
    // receiving side, then verify. For now: assert signature verifies without
    // transport (confirming the signing contract is sound).
    $verifies = $signer->verify(
        payload: $signedEntry->signingPayload(),
        sigHex: $signedEntry->signature,
        publicKeyBin: $publicKey,
    );
    expect($verifies)->toBeTrue('Ed25519 signature must verify before transport (baseline)');

    // Transport round-trip assertion: RED until Wave 3 ships TransportFramer + NoiseSession.
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\SyncSession'))->toBeFalse(
        'Wave 0 guard: SyncSession must not exist yet — implement in Wave 3.'
    );
})->todo('Wave 3: encrypt OpLogEntry via NoiseSession, decrypt, deserialize, assert DeviceKeySigner::verify() passes');

it('tampered OpLogEntry fails DeviceKeySigner::verify() after Noise decrypt (Pitfall 7 guard)', function (): void {
    $signer = new DeviceKeySigner();
    $kp = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($kp);
    $publicKey = sodium_crypto_sign_publickey($kp);

    $entry = new OpLogEntry(
        table: 'transactions',
        pk: 1,
        field: 'note',
        value: 'original-value',
        hlcL: 1_718_000_000_000,
        hlcC: 0,
        deviceId: 'device-a',
        opType: OpType::SET,
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
        value: 'tampered-value',  // attacker changed the value
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
})->todo('Wave 3: full Noise round-trip version of this test — same tamper-rejection invariant holds after transport decrypt');

it('transport stack rejects an op whose signature was forged (unknown device key)', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\SyncSession'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 3.'
    );
})->todo('Wave 3: OpLogEntry with signature from unknown device_id → DeviceKeySigner::verify() false → entry quarantined');
