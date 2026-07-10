<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Noise\NoiseCipherState;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;

/*
 * NoiseSessionTest — XPORT-01: Noise session encrypt/decrypt round-trip.
 *
 * Validates that after a completed Noise IK handshake:
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

/**
 * Creates a pair of NoiseSession objects via an in-memory IK handshake
 * between a freshly generated initiator and responder keypair.
 *
 * @return array{0: NoiseSession, 1: NoiseSession} [initiatorSession, responderSession]
 */
function makeIkSessionPair(): array
{
    // Generate fresh X25519 keypairs for both parties
    $initKp = sodium_crypto_kx_keypair();
    $initStaticSecret = sodium_crypto_kx_secretkey($initKp);
    $initStaticPublic = sodium_crypto_kx_publickey($initKp);
    sodium_memzero($initKp);

    $respKp = sodium_crypto_kx_keypair();
    $respStaticSecret = sodium_crypto_kx_secretkey($respKp);
    $respStaticPublic = sodium_crypto_kx_publickey($respKp);
    sodium_memzero($respKp);

    // IK initiator: knows responder's static public key (from device registry)
    $initHs = NoiseHandshakeState::initIkInitiator(
        $initStaticSecret,
        $initStaticPublic,
        $respStaticPublic,
    );

    // IK responder
    $respHs = NoiseHandshakeState::initIkResponder(
        $respStaticSecret,
        $respStaticPublic,
    );

    // Message 1: initiator writes, responder reads
    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);

    // Message 2: responder writes, initiator reads
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    [$initSend, $initRecv, $peerStaticI] = $initHs->split();
    $initSession = new NoiseSession($initSend, $initRecv, $peerStaticI);

    [$respSend, $respRecv, $peerStaticR] = $respHs->split();
    $respSession = new NoiseSession($respSend, $respRecv, $peerStaticR);

    return [$initSession, $respSession];
}

it('established Noise session encrypts and decrypts a round-trip message', function (): void {
    [$initSession, $respSession] = makeIkSessionPair();

    $plaintext = 'Hello, Noise transport!';
    $ciphertext = $initSession->encrypt($plaintext);

    expect($ciphertext)->not->toBe($plaintext)
        ->and(strlen($ciphertext))->toBeGreaterThan(strlen($plaintext));

    $decrypted = $respSession->decrypt($ciphertext);
    expect($decrypted)->toBe($plaintext);
});

it('each encrypt call produces unique ciphertext for the same plaintext (nonce counter increment)', function (): void {
    [$initSession] = makeIkSessionPair();

    $plaintext = 'same plaintext';
    $ct1 = $initSession->encrypt($plaintext);
    $ct2 = $initSession->encrypt($plaintext);

    // Different nonces → different ciphertexts even for identical plaintext
    expect($ct1)->not->toBe($ct2);
});

it('tampered ciphertext throws RuntimeException on decrypt (AEAD authentication failure)', function (): void {
    [$initSession, $respSession] = makeIkSessionPair();

    $ciphertext = $initSession->encrypt('sensitive data');

    // Flip a byte in the ciphertext (bit-flip attack)
    $tampered = $ciphertext;
    $tampered[5] = chr(ord($tampered[5]) ^ 0xFF);

    expect(fn () => $respSession->decrypt($tampered))
        ->toThrow(RuntimeException::class);
});

it('session is bidirectional: responder can also encrypt and initiator decrypts', function (): void {
    [$initSession, $respSession] = makeIkSessionPair();

    $plaintext = 'From responder to initiator';
    $ciphertext = $respSession->encrypt($plaintext);
    $decrypted = $initSession->decrypt($ciphertext);

    expect($decrypted)->toBe($plaintext);
});

it('nonce overflow guard rekeys before reaching PHP_INT_MAX (Pitfall 1 protection)', function (): void {
    // Directly test NoiseCipherState nonce overflow guard.
    // We cannot actually reach 2^63 messages, so we test the guard mechanism by
    // using reflection to set nonceHi close to the overflow boundary.
    $key = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_KEYBYTES);
    $cipher = new NoiseCipherState($key);

    // Verify normal encrypt/decrypt works
    $ct = $cipher->encrypt('test', '');
    $cipher2 = new NoiseCipherState($key);
    $plain = $cipher2->decrypt($ct, '');
    expect($plain)->toBe('test');

    // The overflow guard exists: set nonceLo and nonceHi to near-max values via reflection.
    $ref = new ReflectionClass($cipher);
    $lo = $ref->getProperty('nonceLo');
    $hi = $ref->getProperty('nonceHi');
    $lo->setValue($cipher, 0xFFFFFFFF);
    $hi->setValue($cipher, 0x7FFFFFFF);

    // The next encrypt call should throw because the nonce is at the overflow boundary.
    expect(fn () => $cipher->encrypt('overflow test', ''))
        ->toThrow(RuntimeException::class, 'nonce overflow');
});
