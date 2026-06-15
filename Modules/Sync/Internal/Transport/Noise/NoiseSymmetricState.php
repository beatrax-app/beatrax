<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Noise;

/**
 * Noise Protocol Framework §5 — SymmetricState.
 *
 * Maintains the running chaining key ($ck) and handshake hash ($h) state
 * during a Noise handshake. Uses BLAKE2b as the hash function via
 * sodium_crypto_generichash().
 *
 * HKDF implementation: Two-output HKDF is approximated using BLAKE2b with the
 * input as the message and the current chaining key as the BLAKE2b key. The
 * 64-byte output is split into two 32-byte halves — the new chaining key and
 * the new cipher key (Noise §5 definition).
 *
 * Protocol name initialization: The handshake hash $h is initialized to the
 * zero-padded (or truncated) protocol name string. For protocol names longer
 * than 32 bytes, $h = BLAKE2b(name). For names ≤ 32 bytes, $h = name padded
 * with NUL bytes to 32 bytes. Both IK and XX protocol names are > 32 bytes,
 * so the hash path applies.
 *
 * encryptAndHash / decryptAndHash: When the cipher is not yet initialized (no
 * mixKey called yet), the data is sent in the clear and the plaintext is mixed
 * into $h. After the first mixKey, AEAD is used.
 *
 * split(): Derives the final two NoiseCipherState objects from the chaining
 * key. The first is the initiator's send key; the second is the responder's
 * send key (Noise §5).
 *
 * This class is mutable (NOT readonly): $ck, $h, and $cipher change during
 * the handshake.
 */
final class NoiseSymmetricState
{
    private string $ck;   // 32-byte chaining key

    private string $h;    // 32-byte running handshake hash

    private ?NoiseCipherState $cipher = null;

    /**
     * Initialises the symmetric state for the given Noise protocol name.
     *
     * If |protocolName| > 32 bytes, $h = BLAKE2b-256(protocolName).
     * If |protocolName| <= 32 bytes, $h = protocolName padded with NUL to 32 bytes.
     * In both cases, $ck = $h (they start equal, per Noise §5.2).
     *
     * @param  string  $protocolName  E.g. 'Noise_IK_25519_ChaChaPoly_BLAKE2b'.
     */
    public function __construct(string $protocolName)
    {
        $nameLen = strlen($protocolName);

        if ($nameLen <= 32) {
            $this->h = str_pad($protocolName, 32, "\0");
        } else {
            // sodium_crypto_generichash with no key, 32-byte output = BLAKE2b-256
            $this->h = sodium_crypto_generichash($protocolName, '', 32);
        }

        $this->ck = $this->h;
    }

    /**
     * Noise §5 mixKey: derives new ($ck, $k) from input DH material.
     *
     * HKDF(ck, inputMaterial) implemented as:
     *   temp = BLAKE2b-512(inputMaterial, key=ck)
     *   ck   = temp[0:32]
     *   k    = temp[32:64]
     *
     * Ephemeral DH outputs should be zeroed (sodium_memzero) by the caller
     * immediately after passing to mixKey (RESEARCH Pitfall / T-13-05).
     */
    public function mixKey(string $inputMaterial): void
    {
        $temp = sodium_crypto_generichash($inputMaterial, $this->ck, 64);
        $this->ck = substr($temp, 0, 32);
        $k = substr($temp, 32, 32);
        sodium_memzero($temp);

        $this->cipher = new NoiseCipherState($k);
        // Zero $k after handing it to NoiseCipherState (which keeps its own copy).
        sodium_memzero($k);
    }

    /**
     * Noise §5 mixHash: chains $data into the running handshake hash.
     *
     * h = BLAKE2b-256(h || data)
     */
    public function mixHash(string $data): void
    {
        $this->h = sodium_crypto_generichash($this->h.$data, '', 32);
    }

    /**
     * Noise §5 encryptAndHash: encrypts $plaintext using current hash as AD.
     *
     * If cipher not yet initialised (pre-mixKey), returns plaintext and mixes
     * plaintext into $h (send in the clear).
     *
     * After the first mixKey call, AEAD encrypts and mixes the ciphertext into $h.
     */
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

    /**
     * Noise §5 decryptAndHash: decrypts $ciphertext using current hash as AD.
     *
     * If cipher not yet initialised, returns ciphertext as-is and mixes it
     * into $h (receive in the clear).
     *
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

    /**
     * Noise §5 split: derives the two final transport CipherState objects.
     *
     * HKDF with empty input:
     *   temp = BLAKE2b-512('', key=ck)
     *   k1   = temp[0:32]   → initiator send key
     *   k2   = temp[32:64]  → responder send key
     *
     * Returns [initiatorSend, responderSend].
     *
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

    /**
     * Returns the current handshake hash value (32 bytes).
     *
     * Used as the AD for AEAD calls in encryptAndHash/decryptAndHash.
     */
    public function getHandshakeHash(): string
    {
        return $this->h;
    }

    /**
     * Returns true if the symmetric state has been keyed by at least one mixKey call.
     *
     * Used by NoiseHandshakeState to determine whether the 's' token should produce
     * 32 bytes (unkeyed, plaintext static key) or 48 bytes (32 + 16 AEAD tag).
     */
    public function isKeyed(): bool
    {
        return $this->cipher !== null;
    }
}
