<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use InvalidArgumentException;

/**
 * Per-field XChaCha20-Poly1305 IETF AEAD encrypt/decrypt with the epoch id
 * bound as associated data (D-02/D-04, 14-RESEARCH.md Pattern 2).
 *
 * This is the AEAD sibling of `Modules\Auth\Internal\Lock\AppLockKeyWrap`:
 * the same base64(nonce||ciphertext) TEXT-column-safe framing, the same
 * "false not garbage" contract (decrypt() returns `false` — never throws,
 * never garbage — on any AEAD/auth failure, malformed base64, or
 * too-short input), so callers can route straight to a quarantine/lock
 * path on a strict `=== false` check (T-05-03 posture).
 *
 * The `$associatedData` argument is the epoch-binding channel: callers pass
 * a canonical string embedding the epoch id (e.g. the op-log ENTRY AD
 * `"{table}:{pk}:{field}:{epochId}"` or the projection AD
 * `"{table}:{field}:{epochId}"` used by the Sync Public
 * `SensitiveColumnCodec`) so that relabeling the epoch tag on a stored
 * ciphertext invalidates the Poly1305 authentication tag — defense in
 * depth alongside the Ed25519 signature that already covers the whole
 * op-log entry.
 */
final class OpLogFieldCrypto
{
    /**
     * Encrypt $plaintext under $rawGdkKey with $associatedData bound into
     * the AEAD authentication tag. Returns base64(nonce||ciphertext).
     *
     * @throws InvalidArgumentException when $rawGdkKey is empty (non-empty-string
     *                                  guard, PHPStan level 10 — mirrors
     *                                  DeviceKeySigner::sign()).
     */
    public function encrypt(string $plaintext, string $rawGdkKey, string $associatedData): string
    {
        if ($rawGdkKey === '') {
            throw new InvalidArgumentException('OpLogFieldCrypto::encrypt — rawGdkKey must not be empty.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $associatedData,
            $nonce,
            $rawGdkKey,
        );

        return base64_encode($nonce.$ciphertext);
    }

    /**
     * Decrypt $stored back to plaintext under $rawGdkKey + $associatedData.
     *
     * Returns `false` (NEVER throws, never garbage) when:
     *   - $stored is not valid strict base64,
     *   - the decoded blob is too short (<= NPUBBYTES) to contain a ciphertext,
     *   - the AEAD authentication fails (wrong key, tampered ciphertext,
     *     or a relabeled $associatedData / epoch tag).
     *
     * Callers MUST use a strict `=== false` check — never `!$result` — and
     * route a false result to the quarantine/lock path (never treat it as
     * an empty-string plaintext).
     *
     * @throws InvalidArgumentException when $rawGdkKey is empty.
     */
    public function decrypt(string $stored, string $rawGdkKey, string $associatedData): string|false
    {
        if ($rawGdkKey === '') {
            throw new InvalidArgumentException('OpLogFieldCrypto::decrypt — rawGdkKey must not be empty.');
        }

        $decoded = base64_decode($stored, strict: true);
        if ($decoded === false) {
            return false;
        }

        if (strlen($decoded) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return false;
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        // sodium_crypto_aead_xchacha20poly1305_ietf_decrypt returns false on
        // any authentication failure — "false not garbage" (T-05-03).
        return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $associatedData,
            $nonce,
            $rawGdkKey,
        );
    }
}
