<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Noise\NoiseCipherState;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;

/*
 * NoiseXxHandshakeTest — XPORT-01: Noise XX pattern handshake.
 *
 * Validates that a complete XX handshake (3-message, 1.5 RTT) between an
 * initiator and a responder:
 *   1. Produces a symmetric session key pair (split keys) after message 3.
 *   2. The derived keys are directional (initiator-send ≠ responder-send).
 *   3. The handshake is deterministic given fixed ephemeral keys (test
 *      vector matching for the 3-message exchange).
 *
 * The official cacophony test vectors for Noise_XX_25519_ChaChaPoly_BLAKE2b
 * are vendored in Modules/Sync/tests/Fixtures/noise_test_vectors.json.
 * All three message ciphertexts from the vector are asserted so no cipher-
 * suite drift can go undetected (anti-Pitfall: wrong ChaCha vs XChaCha).
 *
 * Usage context: XX is the fallback pattern when the responder's static
 * X25519 key is not yet known (first connect or after static key mismatch).
 * IK is the primary pattern for reconnecting paired devices.
 */

/**
 * Loads the XX test vector from the vendored fixtures file.
 *
 * @return array<string, mixed>
 */
function loadXxVector(): array
{
    $vectors = json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/noise_test_vectors.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    /** @var array<int, array<string, mixed>> $vectorList */
    $vectorList = $vectors['vectors'];

    foreach ($vectorList as $v) {
        if ($v['name'] === 'Noise_XX_25519_ChaChaPoly_BLAKE2b') {
            return $v;
        }
    }

    throw new RuntimeException('XX vector not found in noise_test_vectors.json');
}

it('XX handshake produces split keys after 3-message exchange', function (): void {
    $initKp = sodium_crypto_kx_keypair();
    $initStaticSecret = sodium_crypto_kx_secretkey($initKp);
    $initStaticPublic = sodium_crypto_kx_publickey($initKp);
    sodium_memzero($initKp);

    $respKp = sodium_crypto_kx_keypair();
    $respStaticSecret = sodium_crypto_kx_secretkey($respKp);
    $respStaticPublic = sodium_crypto_kx_publickey($respKp);
    sodium_memzero($respKp);

    $initHs = NoiseHandshakeState::initXxInitiator($initStaticSecret, $initStaticPublic);
    $respHs = NoiseHandshakeState::initXxResponder($respStaticSecret, $respStaticPublic);

    // XX: 3 messages
    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);

    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    $msg3 = $initHs->writeMessage('');
    $respHs->readMessage($msg3);

    expect($initHs->isComplete())->toBeTrue();
    expect($respHs->isComplete())->toBeTrue();

    [$initSend, $initRecv, $peerStaticI] = $initHs->split();

    expect($initSend)->toBeInstanceOf(NoiseCipherState::class);
    expect($initRecv)->toBeInstanceOf(NoiseCipherState::class);
    expect(strlen($peerStaticI))->toBe(32);
    expect($peerStaticI)->toBe($respStaticPublic);
});

it('XX split keys are directional (c1 !== c2)', function (): void {
    $initKp = sodium_crypto_kx_keypair();
    $initStaticSecret = sodium_crypto_kx_secretkey($initKp);
    $initStaticPublic = sodium_crypto_kx_publickey($initKp);
    sodium_memzero($initKp);

    $respKp = sodium_crypto_kx_keypair();
    $respStaticSecret = sodium_crypto_kx_secretkey($respKp);
    $respStaticPublic = sodium_crypto_kx_publickey($respKp);
    sodium_memzero($respKp);

    $initHs = NoiseHandshakeState::initXxInitiator($initStaticSecret, $initStaticPublic);
    $respHs = NoiseHandshakeState::initXxResponder($respStaticSecret, $respStaticPublic);

    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);
    $msg3 = $initHs->writeMessage('');
    $respHs->readMessage($msg3);

    [$initSend, $initRecv] = $initHs->split();

    $plain = 'directional test';
    $ct1 = $initSend->encrypt($plain, '');
    $ct2 = $initRecv->encrypt($plain, '');

    expect($ct1)->not->toBe($ct2, 'XX split must produce two distinct directional cipher keys');
});

it('XX handshake all 3 message ciphertexts match official Noise_XX_25519_ChaChaPoly_BLAKE2b test vector', function (): void {
    $xxVector = loadXxVector();

    /** @var array<int, array<string, string>> $messages */
    $messages = $xxVector['messages'];

    expect($xxVector)->toHaveKey('messages');
    expect($messages)->toHaveCount(3, 'XX pattern has exactly 3 messages');

    foreach ($messages as $idx => $msg) {
        expect($msg)->toHaveKey('payload');
        expect($msg)->toHaveKey('ciphertext');
    }

    // Decode the fixed keys from the vector.
    // The vector stores private keys and pre-computed public keys (*_pub fields).
    $initStaticSecret = sodium_hex2bin((string) $xxVector['init_static']);
    $initStaticPublic = sodium_hex2bin((string) $xxVector['init_static_pub']);

    $initEphemeralSecret = sodium_hex2bin((string) $xxVector['init_ephemeral']);
    $initEphemeralPublic = sodium_hex2bin((string) $xxVector['init_ephemeral_pub']);

    $respStaticSecret = sodium_hex2bin((string) $xxVector['resp_static']);
    $respStaticPublic = sodium_hex2bin((string) $xxVector['resp_static_pub']);

    $respEphemeralSecret = sodium_hex2bin((string) $xxVector['resp_ephemeral']);
    $respEphemeralPublic = sodium_hex2bin((string) $xxVector['resp_ephemeral_pub']);

    $prologue = sodium_hex2bin((string) $xxVector['init_prologue']);

    // --- Initiator side ---
    $initHs = NoiseHandshakeState::initXxInitiator(
        $initStaticSecret,
        $initStaticPublic,
        $prologue,
    );
    $initHs->setEphemeralKeypair($initEphemeralSecret, $initEphemeralPublic);

    // --- Responder side ---
    $respHs = NoiseHandshakeState::initXxResponder(
        $respStaticSecret,
        $respStaticPublic,
        $prologue,
    );
    $respHs->setEphemeralKeypair($respEphemeralSecret, $respEphemeralPublic);

    // Message 1: initiator writes
    $payload1 = sodium_hex2bin($messages[0]['payload']);
    $msg1 = $initHs->writeMessage($payload1);

    expect(sodium_bin2hex($msg1))->toBe($messages[0]['ciphertext'],
        'XX message 1 ciphertext must match official Noise_XX_25519_ChaChaPoly_BLAKE2b test vector'
    );

    $decodedPayload1 = $respHs->readMessage($msg1);
    expect($decodedPayload1)->toBe($payload1);

    // Message 2: responder writes
    $payload2 = sodium_hex2bin($messages[1]['payload']);
    $msg2 = $respHs->writeMessage($payload2);

    expect(sodium_bin2hex($msg2))->toBe($messages[1]['ciphertext'],
        'XX message 2 ciphertext must match official Noise_XX_25519_ChaChaPoly_BLAKE2b test vector'
    );

    $decodedPayload2 = $initHs->readMessage($msg2);
    expect($decodedPayload2)->toBe($payload2);

    // Message 3: initiator writes
    $payload3 = sodium_hex2bin($messages[2]['payload']);
    $msg3 = $initHs->writeMessage($payload3);

    expect(sodium_bin2hex($msg3))->toBe($messages[2]['ciphertext'],
        'XX message 3 ciphertext must match official Noise_XX_25519_ChaChaPoly_BLAKE2b test vector'
    );

    $decodedPayload3 = $respHs->readMessage($msg3);
    expect($decodedPayload3)->toBe($payload3);

    expect($initHs->isComplete())->toBeTrue();
    expect($respHs->isComplete())->toBeTrue();
});

it('XX initiator static key is revealed and authenticated in message 3', function (): void {
    $xxVector = loadXxVector();

    $initStaticSecret = sodium_hex2bin((string) $xxVector['init_static']);
    $initStaticPublic = sodium_hex2bin((string) $xxVector['init_static_pub']);

    $initEphemeralSecret = sodium_hex2bin((string) $xxVector['init_ephemeral']);
    $initEphemeralPublic = sodium_hex2bin((string) $xxVector['init_ephemeral_pub']);

    $respStaticSecret = sodium_hex2bin((string) $xxVector['resp_static']);
    $respStaticPublic = sodium_hex2bin((string) $xxVector['resp_static_pub']);

    $respEphemeralSecret = sodium_hex2bin((string) $xxVector['resp_ephemeral']);
    $respEphemeralPublic = sodium_hex2bin((string) $xxVector['resp_ephemeral_pub']);

    $prologue = sodium_hex2bin((string) $xxVector['init_prologue']);

    $initHs = NoiseHandshakeState::initXxInitiator($initStaticSecret, $initStaticPublic, $prologue);
    $initHs->setEphemeralKeypair($initEphemeralSecret, $initEphemeralPublic);

    $respHs = NoiseHandshakeState::initXxResponder($respStaticSecret, $respStaticPublic, $prologue);
    $respHs->setEphemeralKeypair($respEphemeralSecret, $respEphemeralPublic);

    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);

    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    $msg3 = $initHs->writeMessage('');
    $respHs->readMessage($msg3);

    // After XX completes, the responder has the initiator's static public key.
    [, , $peerStaticFromResp] = $respHs->split();

    expect(sodium_bin2hex($peerStaticFromResp))->toBe(sodium_bin2hex($initStaticPublic),
        'XX: after handshake, responder peerStaticPublicKey() must match initiator static public key from vector'
    );

    // Initiator has the responder's static public key.
    [, , $peerStaticFromInit] = $initHs->split();
    expect(sodium_bin2hex($peerStaticFromInit))->toBe(sodium_bin2hex($respStaticPublic),
        'XX: after handshake, initiator peerStaticPublicKey() must match responder static public key from vector'
    );
});
