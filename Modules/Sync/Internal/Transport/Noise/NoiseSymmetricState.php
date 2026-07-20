<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Noise;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class NoiseSymmetricState
{
    /** @var string 32-byte chaining key */
    private string $ck;

    /** @var string 32-byte running handshake hash */
    private string $h;

    private ?NoiseCipherState $cipher = null;

    /**
     * @param  string  $protocolName  E.g. 'Noise_IK_25519_ChaChaPoly_BLAKE2b'.
     */
    public function __construct(string $protocolName)
    {
        $nameLen = strlen($protocolName);

        if ($nameLen <= 32) {
            $this->h = str_pad($protocolName, 32, "\0");
        } else {
            $this->h = sodium_crypto_generichash($protocolName, '', 32);
        }

        $this->ck = $this->h;
    }

    // HKDF(ck, inputMaterial): temp = BLAKE2b-512(inputMaterial, key=ck),
    // ck = temp[0:32], k = temp[32:64]. Ephemeral DH outputs should be
    // zeroed by the caller immediately after passing to mixKey.
    public function mixKey(string $inputMaterial): void
    {
        $temp = sodium_crypto_generichash($inputMaterial, $this->ck, 64);
        $this->ck = substr($temp, 0, 32);
        $k = substr($temp, 32, 32);
        sodium_memzero($temp);

        $this->cipher = new NoiseCipherState($k);
        sodium_memzero($k);
    }

    public function mixHash(string $data): void
    {
        $this->h = sodium_crypto_generichash($this->h.$data, '', 32);
    }

    // If cipher not yet initialised (pre-mixKey), returns plaintext and
    // mixes plaintext into $h (send in the clear). After the first mixKey
    // call, AEAD encrypts and mixes the ciphertext into $h instead.
    public function encryptAndHash(string $plaintext): string
    {
        if ($this->cipher === null) {
            $this->mixHash($plaintext);

            return $plaintext;
        }

        $ciphertext = $this->cipher->encrypt($plaintext, $this->h);
        $this->mixHash($ciphertext);

        return $ciphertext;
    }

    // If cipher not yet initialised, returns ciphertext as-is and mixes it
    // into $h (receive in the clear).
    /**
     * @throws \RuntimeException on AEAD MAC failure (via NoiseCipherState).
     */
    public function decryptAndHash(string $ciphertext): string
    {
        if ($this->cipher === null) {
            $this->mixHash($ciphertext);

            return $ciphertext;
        }

        $plaintext = $this->cipher->decrypt($ciphertext, $this->h);
        $this->mixHash($ciphertext);

        return $plaintext;
    }

    // HKDF with empty input: temp = BLAKE2b-512('', key=ck), k1 =
    // temp[0:32] (initiator send key), k2 = temp[32:64] (responder send key).
    /**
     * @return array{0: NoiseCipherState, 1: NoiseCipherState}
     */
    public function split(): array
    {
        $temp = sodium_crypto_generichash('', $this->ck, 64);
        $k1 = substr($temp, 0, 32);
        $k2 = substr($temp, 32, 32);
        sodium_memzero($temp);

        $c1 = new NoiseCipherState($k1);
        $c2 = new NoiseCipherState($k2);

        sodium_memzero($k1);
        sodium_memzero($k2);

        return [$c1, $c2];
    }

    public function getHandshakeHash(): string
    {
        return $this->h;
    }

    // Used by NoiseHandshakeState to determine whether the 's' token should
    // produce 32 bytes (unkeyed, plaintext static key) or 48 bytes (32 + 16
    // AEAD tag).
    public function isKeyed(): bool
    {
        return $this->cipher !== null;
    }
}
