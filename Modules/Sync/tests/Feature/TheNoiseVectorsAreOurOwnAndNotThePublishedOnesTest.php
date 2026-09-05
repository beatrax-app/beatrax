<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Tests\Support\ConformantNoiseSymmetricState;

// The vendored fixture is this implementation's own output, and a test cannot
// establish interoperability against vectors the implementation produced. This
// file measures the gap instead of describing it: the published vectors are
// re-derived here from nothing but ext-sodium, and the same handshake is then
// run against them so the exact byte the two part company at is a number.

/**
 * @return array<string, array<string, mixed>>
 */
function publishedNoiseVectors(): array
{
    /** @var array{vectors: list<array<string, mixed>>} $file */
    $file = json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/noise_published_vectors.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $byName = [];

    foreach ($file['vectors'] as $vector) {
        $byName[(string) $vector['protocol_name']] = $vector;
    }

    return $byName;
}

/**
 * @return array<string, mixed>
 */
function publishedNoiseVector(string $protocolName): array
{
    $vectors = publishedNoiseVectors();

    if (! isset($vectors[$protocolName])) {
        throw new RuntimeException('Vector not found in noise_published_vectors.json: '.$protocolName);
    }

    return $vectors[$protocolName];
}

// The published IK vector, written by a symmetric state that follows the
// framework: BLAKE2b at its full 64-byte output, the HMAC-based key
// derivation, and the 12-byte IETF nonce.
function conformantIkMessageOne(): string
{
    $vector = publishedNoiseVector('Noise_IK_25519_ChaChaPoly_BLAKE2b');

    $initiatorStatic = sodium_hex2bin((string) $vector['init_static']);
    $initiatorEphemeral = sodium_hex2bin((string) $vector['init_ephemeral']);
    $responderStaticPublic = sodium_crypto_scalarmult_base(sodium_hex2bin((string) $vector['resp_static']));

    $state = new ConformantNoiseSymmetricState('Noise_IK_25519_ChaChaPoly_BLAKE2b');
    $state->mixHash(sodium_hex2bin((string) $vector['init_prologue']));
    $state->mixHash($responderStaticPublic);

    $ephemeralPublic = sodium_crypto_scalarmult_base($initiatorEphemeral);
    $state->mixHash($ephemeralPublic);
    $state->mixKey(sodium_crypto_scalarmult($initiatorEphemeral, $responderStaticPublic));

    $staticField = $state->encryptAndHash(sodium_crypto_scalarmult_base($initiatorStatic));
    $state->mixKey(sodium_crypto_scalarmult($initiatorStatic, $responderStaticPublic));

    /** @var array<int, array<string, string>> $messages */
    $messages = $vector['messages'];

    return $ephemeralPublic.$staticField.$state->encryptAndHash(sodium_hex2bin($messages[0]['payload']));
}

// The whole point of carrying the published file: an implementation that
// cannot reproduce it is the thing in question, not the vectors.
it('re-derives the published vector from ext-sodium alone, so the vectors are not in doubt', function (): void {
    $vector = publishedNoiseVector('Noise_IK_25519_ChaChaPoly_BLAKE2b');

    /** @var array<int, array<string, string>> $messages */
    $messages = $vector['messages'];

    expect(sodium_bin2hex(sodium_crypto_scalarmult_base(sodium_hex2bin((string) $vector['resp_static']))))
        ->toBe((string) $vector['init_remote_static'])
        ->and(sodium_bin2hex(conformantIkMessageOne()))
        ->toBe($messages[0]['ciphertext']);
});

// The suite name goes into the handshake hash, so this is not a naming
// quibble: the state seeded by that name is not the state the name describes.
it('does not reproduce the published vector, because its symmetric state is not the named suite', function (): void {
    $vector = publishedNoiseVector('Noise_IK_25519_ChaChaPoly_BLAKE2b');

    /** @var array<int, array<string, string>> $messages */
    $messages = $vector['messages'];

    $initiatorStatic = sodium_hex2bin((string) $vector['init_static']);
    $initiatorEphemeral = sodium_hex2bin((string) $vector['init_ephemeral']);
    $responderStaticPublic = sodium_crypto_scalarmult_base(sodium_hex2bin((string) $vector['resp_static']));

    $handshake = NoiseHandshakeState::initIkInitiator(
        $initiatorStatic,
        sodium_crypto_scalarmult_base($initiatorStatic),
        $responderStaticPublic,
        sodium_hex2bin((string) $vector['init_prologue']),
    );
    $handshake->setEphemeralKeypair($initiatorEphemeral, sodium_crypto_scalarmult_base($initiatorEphemeral));

    $ours = $handshake->writeMessage(sodium_hex2bin($messages[0]['payload']));
    $published = sodium_hex2bin($messages[0]['ciphertext']);

    // The ephemeral key is plain curve arithmetic and agrees exactly; the
    // first byte after it is the first byte the symmetric state produces, and
    // it is where the two implementations part.
    expect(strlen($ours))->toBe(strlen($published))
        ->and(substr($ours, 0, 32))->toBe(substr($published, 0, 32))
        ->and(substr($ours, 32, 1))->not->toBe(substr($published, 32, 1));
});

// A future reader must not re-inherit the claim that the vendored keys are
// the published ones. Two of the four are not, and that is why the vendored
// ciphertexts had to be regenerated in the first place.
it('carries responder keys the published vectors never contained', function (): void {
    /** @var array{vectors: list<array<string, string>>} $vendored */
    $vendored = json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/noise_test_vectors.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $published = publishedNoiseVector('Noise_IK_25519_ChaChaPoly_BLAKE2b');
    $ours = null;

    foreach ($vendored['vectors'] as $vector) {
        if ($vector['name'] === 'Noise_IK_25519_ChaChaPoly_BLAKE2b') {
            $ours = $vector;
        }
    }

    expect($ours)->not->toBeNull()
        ->and($ours['init_static'])->toBe((string) $published['init_static'])
        ->and($ours['init_ephemeral'])->toBe((string) $published['init_ephemeral'])
        ->and($ours['resp_static'])->not->toBe((string) $published['resp_static'])
        ->and($ours['resp_ephemeral'])->not->toBe((string) $published['resp_ephemeral']);
});
