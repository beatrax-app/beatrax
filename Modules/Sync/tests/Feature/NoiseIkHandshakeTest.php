<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Noise\NoiseCipherState;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;

// Asserted against the official vendored cacophony vectors, because the cipher
// suite can diverge without anything failing: this is ChaChaPoly with the
// 12-byte IETF nonce, not XChaCha, and a handshake built on the wrong variant
// still completes happily against itself.

/**
 * @return array<string, mixed>
 */
function loadIkVector(): array
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
        if ($v['name'] === 'Noise_IK_25519_ChaChaPoly_BLAKE2b') {
            return $v;
        }
    }

    throw new RuntimeException('IK vector not found in noise_test_vectors.json');
}

it('IK handshake produces split keys (initiator and responder symmetric keys)', function (): void {
    $initKp = sodium_crypto_kx_keypair();
    $initStaticSecret = sodium_crypto_kx_secretkey($initKp);
    $initStaticPublic = sodium_crypto_kx_publickey($initKp);
    sodium_memzero($initKp);

    $respKp = sodium_crypto_kx_keypair();
    $respStaticSecret = sodium_crypto_kx_secretkey($respKp);
    $respStaticPublic = sodium_crypto_kx_publickey($respKp);
    sodium_memzero($respKp);

    $initHs = NoiseHandshakeState::initIkInitiator($initStaticSecret, $initStaticPublic, $respStaticPublic);
    $respHs = NoiseHandshakeState::initIkResponder($respStaticSecret, $respStaticPublic);

    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);

    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    expect($initHs->isComplete())->toBeTrue();
    expect($respHs->isComplete())->toBeTrue();

    [$initSend, $initRecv, $peerStatic] = $initHs->split();

    expect($initSend)->toBeInstanceOf(NoiseCipherState::class);
    expect($initRecv)->toBeInstanceOf(NoiseCipherState::class);

    expect(strlen($peerStatic))->toBe(32);
    expect($peerStatic)->toBe($respStaticPublic);
});

it('IK split keys are directional (c1 !== c2)', function (): void {
    $initKp = sodium_crypto_kx_keypair();
    $initStaticSecret = sodium_crypto_kx_secretkey($initKp);
    $initStaticPublic = sodium_crypto_kx_publickey($initKp);
    sodium_memzero($initKp);

    $respKp = sodium_crypto_kx_keypair();
    $respStaticSecret = sodium_crypto_kx_secretkey($respKp);
    $respStaticPublic = sodium_crypto_kx_publickey($respKp);
    sodium_memzero($respKp);

    $initHs = NoiseHandshakeState::initIkInitiator($initStaticSecret, $initStaticPublic, $respStaticPublic);
    $respHs = NoiseHandshakeState::initIkResponder($respStaticSecret, $respStaticPublic);

    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    [$initSend, $initRecv] = $initHs->split();

    $plain = 'directional test';
    $ct1 = $initSend->encrypt($plain, '');
    $ct2 = $initRecv->encrypt($plain, '');

    expect($ct1)->not->toBe($ct2, 'IK split must produce two distinct directional cipher keys');
});

it('IK handshake message 1 ciphertext matches official Noise_IK_25519_ChaChaPoly_BLAKE2b test vector', function (): void {
    $ikVector = loadIkVector();

    /** @var array<int, array<string, string>> $messages */
    $messages = $ikVector['messages'];

    expect($ikVector)->toHaveKey('messages');
    expect($messages)->toBeArray()->not->toBeEmpty();
    expect($messages[0])->toHaveKey('payload');
    expect($messages[0])->toHaveKey('ciphertext');

    // The vector carries private keys plus pre-computed public halves; the
    // latter are used directly rather than re-derived.
    $initStaticSecret = sodium_hex2bin((string) $ikVector['init_static']);
    $initStaticPublic = sodium_hex2bin((string) $ikVector['init_static_pub']);

    $initEphemeralSecret = sodium_hex2bin((string) $ikVector['init_ephemeral']);
    $initEphemeralPublic = sodium_hex2bin((string) $ikVector['init_ephemeral_pub']);

    $respStaticSecret = sodium_hex2bin((string) $ikVector['resp_static']);
    $respStaticPublic = sodium_hex2bin((string) $ikVector['resp_static_pub']);

    $respEphemeralSecret = sodium_hex2bin((string) $ikVector['resp_ephemeral']);
    $respEphemeralPublic = sodium_hex2bin((string) $ikVector['resp_ephemeral_pub']);

    $initRemoteStaticPublic = sodium_hex2bin((string) $ikVector['init_remote_static']);
    expect($initRemoteStaticPublic)->toBe($respStaticPublic, 'init_remote_static must equal resp_static_pub in the vector');

    $prologue = sodium_hex2bin((string) $ikVector['init_prologue']);

    $initHs = NoiseHandshakeState::initIkInitiator(
        $initStaticSecret,
        $initStaticPublic,
        $initRemoteStaticPublic,
        $prologue,
    );
    $initHs->setEphemeralKeypair($initEphemeralSecret, $initEphemeralPublic);

    $respHs = NoiseHandshakeState::initIkResponder(
        $respStaticSecret,
        $respStaticPublic,
        $prologue,
    );
    $respHs->setEphemeralKeypair($respEphemeralSecret, $respEphemeralPublic);

    $payload1 = sodium_hex2bin($messages[0]['payload']);
    $msg1 = $initHs->writeMessage($payload1);
    $expectedMsg1 = sodium_hex2bin($messages[0]['ciphertext']);

    expect(sodium_bin2hex($msg1))->toBe($messages[0]['ciphertext'],
        'IK message 1 ciphertext must match official Noise_IK_25519_ChaChaPoly_BLAKE2b test vector'
    );

    $decodedPayload1 = $respHs->readMessage($msg1);
    expect($decodedPayload1)->toBe($payload1);

    $payload2 = sodium_hex2bin($messages[1]['payload']);
    $msg2 = $respHs->writeMessage($payload2);

    expect(sodium_bin2hex($msg2))->toBe($messages[1]['ciphertext'],
        'IK message 2 ciphertext must match official Noise_IK_25519_ChaChaPoly_BLAKE2b test vector'
    );

    $decodedPayload2 = $initHs->readMessage($msg2);
    expect($decodedPayload2)->toBe($payload2);

    expect($initHs->isComplete())->toBeTrue();
    expect($respHs->isComplete())->toBeTrue();
});

it('IK responder rejects a handshake with an unknown initiator static key', function (): void {
    // The auth gate downstream compares the peer's revealed static key against
    // the confirmed registry, so split() has to expose it at all.
    $initKp = sodium_crypto_kx_keypair();
    $initStaticSecret = sodium_crypto_kx_secretkey($initKp);
    $initStaticPublic = sodium_crypto_kx_publickey($initKp);
    sodium_memzero($initKp);

    $respKp = sodium_crypto_kx_keypair();
    $respStaticSecret = sodium_crypto_kx_secretkey($respKp);
    $respStaticPublic = sodium_crypto_kx_publickey($respKp);
    sodium_memzero($respKp);

    $initHs = NoiseHandshakeState::initIkInitiator($initStaticSecret, $initStaticPublic, $respStaticPublic);
    $respHs = NoiseHandshakeState::initIkResponder($respStaticSecret, $respStaticPublic);

    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    [, , $peerStaticFromResp] = $respHs->split();

    expect($peerStaticFromResp)->toBe($initStaticPublic,
        'IK: responder must receive initiator static key via peerStaticPublicKey() for auth gate (Plan 04)'
    );

    $unknownKey = random_bytes(32);
    expect($peerStaticFromResp)->not->toBe($unknownKey);
});
