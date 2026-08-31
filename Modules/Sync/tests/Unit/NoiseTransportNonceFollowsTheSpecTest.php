<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Noise\NoiseCipherState;

// The vendored handshake vectors cannot see this. Every handshake message
// encrypts at n = 0, where a counter-first nonce and the spec's zeros-first
// nonce are byte-identical, so the deviation only appears from the SECOND
// transport message in a direction onward — and both peers ran the same wrong
// encoding, so live sync never noticed either.

/**
 * @return array<string, string> counter (as a decimal string) => 12-byte nonce, hex
 */
function specTransportNonces(): array
{
    /** @var array{chachapoly_transport_nonces: array{nonces: array<string, string>}} $vectors */
    $vectors = json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/noise_test_vectors.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $vectors['chachapoly_transport_nonces']['nonces'];
}

it('builds every transport nonce as 32 bits of zeros followed by the little-endian counter', function (): void {
    $key = str_repeat("\x2b", SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_KEYBYTES);
    $ad = 'beatrax-noise-transport';

    $cipher = new NoiseCipherState($key);

    foreach (specTransportNonces() as $counter => $nonceHex) {
        $plaintext = 'transport message '.$counter;

        expect($cipher->encrypt($plaintext, $ad))->toBe(
            sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
                $plaintext,
                $ad,
                (string) sodium_hex2bin($nonceHex),
                $key,
            ),
            sprintf('transport message n=%s does not match the Noise rev34 §12.3 nonce', $counter),
        );
    }
});

it('reads back a transport message past the first one, which is where the two nonce layouts diverge', function (): void {
    $key = str_repeat("\x7f", SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_KEYBYTES);

    $sender = new NoiseCipherState($key);
    $receiver = new NoiseCipherState($key);

    foreach (['first', 'second', 'third'] as $plaintext) {
        expect($receiver->decrypt($sender->encrypt($plaintext)))->toBe($plaintext);
    }
});
