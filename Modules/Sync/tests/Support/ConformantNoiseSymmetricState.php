<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

// The positive control for the vector test: a symmetric state that follows the
// Noise framework, so that "our handshake does not reproduce the published
// vectors" and "the published vectors are wrong" stop being the same
// observation. It carries its own HMAC and HKDF rather than reaching for a
// helper declared in a test file, which nothing autoloads.
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
        [$chainingKey, $tempKey] = self::hkdf($this->chainingKey, $inputKeyMaterial);

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

    /**
     * @return array{0: string, 1: string}
     */
    private static function hkdf(string $chainingKey, string $inputKeyMaterial): array
    {
        $tempKey = self::hmacBlake2b($chainingKey, $inputKeyMaterial);
        $first = self::hmacBlake2b($tempKey, "\x01");

        return [$first, self::hmacBlake2b($tempKey, $first."\x02")];
    }

    private static function hmacBlake2b(string $key, string $data): string
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
}
