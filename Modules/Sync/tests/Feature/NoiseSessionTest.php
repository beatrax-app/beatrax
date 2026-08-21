<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Noise\NoiseCipherState;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;

// The nonce is a 64-bit counter in the lower 8 bytes of a 12-byte buffer, and
// PHP's signed integers cannot hold it, so it is tracked as two 32-bit words.
// Reuse is the failure that matters here: the same nonce twice under one key
// gives away the keystream, so identical plaintexts must never encrypt alike.

/**
 * @return array{0: NoiseSession, 1: NoiseSession} [initiatorSession, responderSession]
 */
function makeIkSessionPair(): array
{
    $initKp = sodium_crypto_kx_keypair();
    $initStaticSecret = sodium_crypto_kx_secretkey($initKp);
    $initStaticPublic = sodium_crypto_kx_publickey($initKp);
    sodium_memzero($initKp);

    $respKp = sodium_crypto_kx_keypair();
    $respStaticSecret = sodium_crypto_kx_secretkey($respKp);
    $respStaticPublic = sodium_crypto_kx_publickey($respKp);
    sodium_memzero($respKp);

    $initHs = NoiseHandshakeState::initIkInitiator(
        $initStaticSecret,
        $initStaticPublic,
        $respStaticPublic,
    );

    $respHs = NoiseHandshakeState::initIkResponder(
        $respStaticSecret,
        $respStaticPublic,
    );

    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);

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

    expect($ct1)->not->toBe($ct2);
});

it('tampered ciphertext throws RuntimeException on decrypt (AEAD authentication failure)', function (): void {
    [$initSession, $respSession] = makeIkSessionPair();

    $ciphertext = $initSession->encrypt('sensitive data');

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

it('nonce overflow guard rekeys before reaching PHP_INT_MAX', function (): void {
    // Reaching the boundary honestly would take 2^63 messages, so the counter
    // is driven to it by reflection instead.
    $key = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_KEYBYTES);
    $cipher = new NoiseCipherState($key);

    $ct = $cipher->encrypt('test', '');
    $cipher2 = new NoiseCipherState($key);
    $plain = $cipher2->decrypt($ct, '');
    expect($plain)->toBe('test');

    $ref = new ReflectionClass($cipher);
    $lo = $ref->getProperty('nonceLo');
    $hi = $ref->getProperty('nonceHi');
    $lo->setValue($cipher, 0xFFFFFFFF);
    $hi->setValue($cipher, 0x7FFFFFFF);

    expect(fn () => $cipher->encrypt('overflow test', ''))
        ->toThrow(RuntimeException::class, 'nonce overflow');
});
