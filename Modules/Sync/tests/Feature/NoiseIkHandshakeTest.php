<?php

declare(strict_types=1);

/*
 * NoiseIkHandshakeTest — XPORT-01: Noise IK pattern handshake.
 *
 * RED until Wave 2 ships NoiseCipherState, NoiseSymmetricState, and
 * NoiseHandshakeState under Modules\Sync\Internal\Transport\Noise\.
 *
 * Validates that a complete IK handshake between an initiator and a
 * responder:
 *   1. Produces a symmetric session key pair (split keys).
 *   2. The derived keys are not equal to each other (directional).
 *   3. The handshake is reproducible given fixed ephemeral keys
 *      (deterministic — required for test-vector matching).
 *
 * The official cacophony test vectors for Noise_IK_25519_ChaChaPoly_BLAKE2b
 * are vendored in Modules/Sync/tests/Fixtures/noise_test_vectors.json.
 * The test asserts the known message ciphertext from the vector matches the
 * hand-built handshake's output so the cipher suite can't silently diverge
 * (Pitfall 1 guard: wrong XChaCha vs ChaCha variant; Pitfall Nonce ordering).
 *
 * CRITICAL (from RESEARCH Pattern 1): Noise uses ChaChaPoly (12-byte nonce,
 * IETF variant: sodium_crypto_aead_chacha20poly1305_ietf_*). NOT XChaCha.
 */

// Wave 0 stubs — the Noise classes do not yet exist.
// Tests use ->todo() so the suite stays RUNNABLE but shows as pending/red.

it('IK handshake produces split keys (initiator and responder symmetric keys)', function (): void {
    // Noise_IK_25519_ChaChaPoly_BLAKE2b state machine required.
    // Phase 13 Wave 2 implements Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState.
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseHandshakeState'))->toBeFalse(
        'Wave 0 guard: NoiseHandshakeState must not exist yet — implement in Wave 2.'
    );
})->todo('Wave 2: NoiseHandshakeState IK initiator->split() returns two NoiseCipherState instances');

it('IK split keys are directional (c1 !== c2)', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseCipherState'))->toBeFalse(
        'Wave 0 guard: NoiseCipherState must not exist yet — implement in Wave 2.'
    );
})->todo('Wave 2: IK split produces two distinct 32-byte keys from the final SymmetricState::split()');

it('IK handshake message 1 ciphertext matches official Noise_IK_25519_ChaChaPoly_BLAKE2b test vector', function (): void {
    $vectors = json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/noise_test_vectors.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    /** @var array<int, array<string, mixed>> $vectorList */
    $vectorList = $vectors['vectors'];

    $ikVector = null;
    foreach ($vectorList as $v) {
        if ($v['name'] === 'Noise_IK_25519_ChaChaPoly_BLAKE2b') {
            $ikVector = $v;
            break;
        }
    }

    expect($ikVector)->not->toBeNull('IK vector must be present in noise_test_vectors.json');

    /** @var array<string, mixed> $ikVector */
    expect($ikVector)->toHaveKey('messages');
    expect($ikVector['messages'])->toBeArray()->not->toBeEmpty();

    // Vector-level shape check: the fixture structure is correct.
    /** @var array<int, array<string, string>> $messages */
    $messages = $ikVector['messages'];
    expect($messages[0])->toHaveKey('payload');
    expect($messages[0])->toHaveKey('ciphertext');

    // Actual cipher-output match: RED until Wave 2 IK state machine exists.
    // When NoiseHandshakeState exists, re-implement this to compute msg1 and
    // assert hex(computed_ciphertext) === $messages[0]['ciphertext'].
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseHandshakeState'))->toBeFalse(
        'Wave 0 guard: IK ciphertext vector match deferred to Wave 2.'
    );
})->todo('Wave 2: compute IK msg1 from test-vector init_ephemeral/static/remote_static and assert ciphertext matches');

it('IK responder rejects a handshake with an unknown initiator static key', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseHandshakeState'))->toBeFalse(
        'Wave 0 guard: NoisePeerAuth deferred to Wave 2 NoisePeerAuthTest.'
    );
})->todo('Wave 2: IK responder performs post-handshake static-key check against DeviceRegistryService::deviceX25519Keys()');
