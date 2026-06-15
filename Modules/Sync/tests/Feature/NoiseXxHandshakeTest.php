<?php

declare(strict_types=1);

/*
 * NoiseXxHandshakeTest — XPORT-01: Noise XX pattern handshake.
 *
 * RED until Wave 2 ships NoiseCipherState, NoiseSymmetricState, and
 * NoiseHandshakeState under Modules\Sync\Internal\Transport\Noise\.
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

it('XX handshake produces split keys after 3-message exchange', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseHandshakeState'))->toBeFalse(
        'Wave 0 guard: NoiseHandshakeState must not exist yet — implement in Wave 2.'
    );
})->todo('Wave 2: NoiseHandshakeState XX completes in 3 messages and calls split() to produce two NoiseCipherState instances');

it('XX split keys are directional (c1 !== c2)', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseCipherState'))->toBeFalse(
        'Wave 0 guard: NoiseCipherState must not exist yet.'
    );
})->todo('Wave 2: XX split produces two distinct 32-byte directional keys');

it('XX handshake all 3 message ciphertexts match official Noise_XX_25519_ChaChaPoly_BLAKE2b test vector', function (): void {
    $vectors = json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/noise_test_vectors.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    /** @var array<int, array<string, mixed>> $vectorList */
    $vectorList = $vectors['vectors'];

    $xxVector = null;
    foreach ($vectorList as $v) {
        if ($v['name'] === 'Noise_XX_25519_ChaChaPoly_BLAKE2b') {
            $xxVector = $v;
            break;
        }
    }

    expect($xxVector)->not->toBeNull('XX vector must be present in noise_test_vectors.json');

    /** @var array<string, mixed> $xxVector */
    expect($xxVector)->toHaveKey('messages');

    /** @var array<int, array<string, string>> $messages */
    $messages = $xxVector['messages'];
    expect($messages)->toHaveCount(3, 'XX pattern has exactly 3 messages');

    foreach ($messages as $idx => $msg) {
        expect($msg)->toHaveKey('payload', "message {$idx} must have payload");
        expect($msg)->toHaveKey('ciphertext', "message {$idx} must have ciphertext");
    }

    // Actual cipher-output match: RED until Wave 2 XX state machine exists.
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseHandshakeState'))->toBeFalse(
        'Wave 0 guard: XX ciphertext vector match deferred to Wave 2.'
    );
})->todo('Wave 2: compute XX msgs 1-3 from test-vector ephemeral/static keys and assert all ciphertexts match');

it('XX initiator static key is revealed and authenticated in message 3', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\Noise\\NoiseHandshakeState'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 2.'
    );
})->todo('Wave 2: after XX completes, initiatorStaticPublicKey() matches known test-vector init_static hex');
