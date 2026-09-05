<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;

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

function noiseHmacBlake2b(string $key, string $data): string
{
    $blockBytes = 128;

    if (strlen($key) > $blockBytes) {
        $key = sodium_crypto_generichash($key, '', 64);
    }

    $key = str_pad($key, $blockBytes, "\0");

    return sodium_crypto_generichash(
        ($key ^ str_repeat("\x5c", $blockBytes)).sodium_crypto_generichash(
            ($key ^ str_repeat("\x36", $blockBytes)).$data,
            '',
            64,
        ),
        '',
        64,
    );
}

/**
 * @return array{0: string, 1: string}
 */
function noiseHkdf(string $chainingKey, string $inputKeyMaterial): array
{
    $tempKey = noiseHmacBlake2b($chainingKey, $inputKeyMaterial);
    $first = noiseHmacBlake2b($tempKey, "\x01");

    return [$first, noiseHmacBlake2b($tempKey, $first."\x02")];
}

// The conformant symmetric state, written out here as the positive control:
// without it, "our handshake does not reproduce the published vectors" and
// "the published vectors are wrong" are the same observation.
final class ConformantNoiseSymmetricState
{
    public string $chainingKey;

    public string $hash;

    private ?string $key = null;

    private int $nonce = 0;

    public function __construct(string $protocolName)
    {
        $this->hash = strlen($protocolName) <= 64
            ? str_pad($protocolName, 64, "\0")
            : sodium_crypto_generichash($protocolName, '', 64);

        $this->chainingKey = $this->hash;
    }

    public function mixHash(string $data): void
    {
        $this->hash = sodium_crypto_generichash($this->hash.$data, '', 64);
    }

    public function mixKey(string $inputKeyMaterial): void
    {
        [$chainingKey, $tempKey] = noiseHkdf($this->chainingKey, $inputKeyMaterial);

        $this->chainingKey = $chainingKey;
        $this->key = substr($tempKey, 0, 32);
        $this->nonce = 0;
    }

    public function encryptAndHash(string $plaintext): string
    {
        if ($this->key === null) {
            $this->mixHash($plaintext);

            return $plaintext;
        }

        $ciphertext = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
            $plaintext,
            $this->hash,
            "\0\0\0\0".pack('P', $this->nonce),
            $this->key,
        );

        $this->nonce++;
        $this->mixHash($ciphertext);

        return $ciphertext;
    }
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
