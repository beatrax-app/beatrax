<?php

declare(strict_types=1);

/*
 * NoiseSessionTest — XPORT-01: Noise session encrypt/decrypt round-trip.
 *
 * RED until Wave 2 ships NoiseSession under
 * Modules\Sync\Internal\Transport\Noise\NoiseSession.
 *
 * Validates that after a completed Noise handshake:
 *   1. The initiator can encrypt a plaintext and the responder can decrypt it.
 *   2. The responder can encrypt a plaintext and the initiator can decrypt it.
 *   3. The nonce counter increments correctly (each call produces different
 *      ciphertext for the same plaintext — nonce re-use detection).
 *   4. A tampered ciphertext fails decryption with a RuntimeException
 *      (AEAD authentication tag mismatch — connection must be closed).
 *
 * The nonce encoding rule (from RESEARCH Pitfall 1 + Pattern 1):
 *   Noise CipherState nonce = 64-bit LE integer in the lower 8 bytes of a
 *   12-byte buffer. PHP signed 64-bit ints require nonce tracking as two
 *   32-bit unsigned words to avoid overflow at PHP_INT_MAX.
 *
 * Using sodium_crypto_aead_chacha20poly1305_ietf_* (12-byte nonce IETF).
 * NOT the XChaCha variant (24-byte nonce) — see RESEARCH Pattern 1 / PITFALL.
 */

it('established Noise session encrypts and decrypts a round-trip message', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseSession'))->toBeFalse(
        'Wave 0 guard: NoiseSession must not exist yet — implement in Wave 2.'
    );
})->todo('Wave 2: after IK handshake, initiator->encrypt($plain) decrypted by responder->decrypt() returns $plain');

it('each encrypt call produces unique ciphertext for the same plaintext (nonce counter increment)', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseSession'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 2.'
    );
})->todo('Wave 2: two consecutive encrypt() calls on the same plaintext produce different ciphertexts (nonces differ)');

it('tampered ciphertext throws RuntimeException on decrypt (AEAD authentication failure)', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseSession'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 2.'
    );
})->todo('Wave 2: bit-flip in ciphertext triggers RuntimeException (close-connection posture per RESEARCH Pattern 1)');

it('session is bidirectional: responder can also encrypt and initiator decrypts', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseSession'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 2.'
    );
})->todo('Wave 2: after IK, responder->encrypt($plain) decrypted by initiator->decrypt() returns $plain (directional split keys)');

it('nonce overflow guard rekeys before reaching PHP_INT_MAX (Pitfall 1 protection)', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseCipherState'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 2.'
    );
})->todo('Wave 2: NoiseCipherState::encrypt() throws or rekeys when nonce approaches 2^63-1 (PHP_INT_MAX overflow)');
