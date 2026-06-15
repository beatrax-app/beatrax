<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Noise;

use SodiumException;

/**
 * Noise Protocol Framework §4 — CipherState.
 *
 * Wraps ChaCha20-Poly1305 (IETF variant, 12-byte nonce) with a monotonically
 * incrementing nonce counter. The nonce is encoded as a 64-bit little-endian
 * unsigned integer in the first 8 bytes of the 12-byte nonce buffer (the
 * remaining 4 bytes are zero), as required by the Noise specification.
 *
 * CRITICAL: This uses sodium_crypto_aead_chacha20poly1305_IETF (12-byte
 * nonce), NOT xchacha20poly1305 (24-byte nonce). The Noise spec §4 specifies
 * "ChaChaPoly" with a 64-bit counter nonce encoded in 12 bytes. Using the
 * XChaCha variant will produce ciphertext that does not match official test
 * vectors.
 *
 * Nonce overflow guard (RESEARCH Pitfall 1): PHP integers are 64-bit signed.
 * The nonce counter is tracked as two 32-bit unsigned words ($nonceLo and
 * $nonceHi) to avoid PHP_INT_MAX overflow before the Noise MAXNONCE (2^64-1).
 * Encryption throws when the counter reaches PHP_INT_MAX on the combined view.
 *
 * Decryption throws \RuntimeException on AEAD MAC failure (close-connection
 * posture — never silently return false in a transport context).
 *
 * This class is mutable (NOT readonly) because the nonce counter state must
 * change with each encrypt/decrypt call.
 */
final class NoiseCipherState
{
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
        // Key must be exactly 32 bytes (SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_KEYBYTES).
        // Caller (NoiseSymmetricState::split) guarantees this via substr(, 0, 32).
    }

    /**
     * Encrypts $plaintext with $ad (additional data) using ChaCha20-Poly1305 IETF.
     *
     * Nonce is encoded as 64-bit LE little-endian in the first 8 bytes, zeros in [8:12].
     * Nonce counter increments AFTER building the nonce bytes.
     *
     * @throws \RuntimeException if the nonce counter would overflow (Pitfall 1 guard).
     * @throws \RuntimeException wrapping SodiumException on crypto failure.
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
            throw new \RuntimeException('Noise CipherState: encrypt failed — '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Decrypts $ciphertext with $ad (additional data) using ChaCha20-Poly1305 IETF.
     *
     * Throws \RuntimeException on any AEAD authentication failure — callers MUST close
     * the connection on failure (never silently discard; see RESEARCH Pattern 1).
     *
     * @throws \RuntimeException on MAC failure or malformed ciphertext.
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
            throw new \RuntimeException('Noise CipherState: AEAD decryption failed — '.$e->getMessage(), 0, $e);
        }

        if ($plaintext === false) {
            throw new \RuntimeException(
                'Noise CipherState: AEAD decryption failed — invalid ciphertext or wrong key.'
            );
        }

        return $plaintext;
    }

    /**
     * Returns the current nonce counter as a 12-byte IETF nonce.
     *
     * Noise §4: n encoded as a 64-bit little-endian uint in bytes 0-7; bytes 8-11 are zero.
     * PHP pack 'V' = unsigned 32-bit LE; three words → 12 bytes total.
     *
     * Layout: [lo_lo_lo_lo hi_hi_hi_hi 00_00_00_00]
     *          bytes 0-3    bytes 4-7    bytes 8-11
     */
    private function buildNonce(): string
    {
        // Overflow guard: if nonceHi would overflow past 32-bit unsigned when nonceLo wraps,
        // we may eventually exceed 2^64-1 (MAXNONCE). Guard at PHP_INT_MAX to be safe.
        if ($this->nonceHi === 0x7FFFFFFF && $this->nonceLo === 0xFFFFFFFF) {
            throw new \RuntimeException(
                'Noise CipherState: nonce overflow — nonce approaches PHP_INT_MAX. '.
                'Initiate a rekey before this point (RESEARCH Pitfall 1).'
            );
        }

        return pack('VVV', $this->nonceLo, $this->nonceHi, 0);
    }

    /**
     * Increments the 64-bit nonce counter using two 32-bit unsigned words.
     *
     * Carry propagates from nonceLo to nonceHi on 32-bit wrap.
     */
    private function incrementNonce(): void
    {
        $this->nonceLo = ($this->nonceLo + 1) & 0xFFFFFFFF;
        if ($this->nonceLo === 0) {
            $this->nonceHi = ($this->nonceHi + 1) & 0xFFFFFFFF;
        }
    }
}
