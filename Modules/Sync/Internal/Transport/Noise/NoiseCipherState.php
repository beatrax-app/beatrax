<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Noise;

use Modules\Sync\Internal\Exceptions\CryptoOperationFailedException;
use Modules\Sync\Internal\Exceptions\NoiseDecryptionFailedException;
use Modules\Sync\Internal\Exceptions\NoiseNonceExhaustedException;
use SodiumException;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class NoiseCipherState
{
    // CRITICAL: uses sodium_crypto_aead_chacha20poly1305_IETF (12-byte
    // nonce), NOT xchacha20poly1305 (24-byte nonce) — the Noise spec
    // requires this exact variant, or ciphertext won't match test vectors.
    /** @var int Lower 32-bit word of the nonce counter (unsigned, 0 to 4294967295). */
    private int $nonceLo = 0;

    /** @var int Upper 32-bit word of the nonce counter (unsigned, 0 to 4294967295). */
    private int $nonceHi = 0;

    /**
     * @param  string  $key  Raw 32-byte ChaCha20-Poly1305 key derived from
     *                       NoiseSymmetricState::split() BLAKE2b output.
     */
    public function __construct(private readonly string $key)
    {
        // Key must be exactly 32 bytes; caller (NoiseSymmetricState::split)
        // guarantees this via substr(, 0, 32).
    }

    // Nonce is encoded as 64-bit LE in the first 8 bytes, zeros in [8:12];
    // the counter increments AFTER building the nonce bytes.
    /**
     * @throws NoiseNonceExhaustedException if the nonce counter would overflow
     * @throws CryptoOperationFailedException wrapping SodiumException
     */
    public function encrypt(string $plaintext, string $ad = ''): string
    {
        $nonce = $this->buildNonce();
        $this->incrementNonce();

        try {
            return sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
                $plaintext,
                $ad,
                $nonce,
                $this->key,
            );
        } catch (SodiumException $e) {
            throw CryptoOperationFailedException::during('Noise transport encryption', $e);
        }
    }

    // Throws on any AEAD authentication failure — callers MUST close the
    // connection on failure, never silently discard it.
    /**
     * @throws NoiseDecryptionFailedException on MAC failure or malformed ciphertext
     * @throws NoiseNonceExhaustedException if the nonce counter would overflow
     */
    public function decrypt(string $ciphertext, string $ad = ''): string
    {
        $nonce = $this->buildNonce();
        $this->incrementNonce();

        try {
            $plaintext = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(
                $ciphertext,
                $ad,
                $nonce,
                $this->key,
            );
        } catch (SodiumException $e) {
            throw NoiseDecryptionFailedException::aeadRejected($e);
        }

        if ($plaintext === false) {
            throw NoiseDecryptionFailedException::aeadRejected();
        }

        return $plaintext;
    }

    // Noise §4: n encoded as a 64-bit LE uint in bytes 0-7; bytes 8-11 zero
    // (layout: lo_lo_lo_lo hi_hi_hi_hi 00_00_00_00).
    private function buildNonce(): string
    {
        // Guard at PHP_INT_MAX rather than the true 2^64-1 MAXNONCE, since
        // nonceHi would overflow past 32-bit unsigned before reaching it.
        if ($this->nonceHi === 0x7FFFFFFF && $this->nonceLo === 0xFFFFFFFF) {
            throw NoiseNonceExhaustedException::beforeRekey();
        }

        return pack('VVV', $this->nonceLo, $this->nonceHi, 0);
    }

    private function incrementNonce(): void
    {
        $this->nonceLo = ($this->nonceLo + 1) & 0xFFFFFFFF;
        if ($this->nonceLo === 0) {
            $this->nonceHi = ($this->nonceHi + 1) & 0xFFFFFFFF;
        }
    }
}
