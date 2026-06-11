<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

/**
 * Derives a symmetric wrap key from a PIN or password using Argon2id.
 *
 * The derived key is used to wrap (encrypt) the user's data key via
 * AppLockKeyWrap — it is never stored. INTERACTIVE Argon2id limits
 * are chosen for unlock-latency: ~64 MB RAM, ~1 op iteration. This
 * contrasts with BackupEncryptor's MODERATE limits (offline passphrase
 * that can afford more work).
 *
 * CALLER CONTRACT: The returned key bytes MUST be zeroed with
 * sodium_memzero($key) after use. Keeping wrap-key bytes live in
 * memory longer than necessary violates the "false/throw not garbage"
 * posture this codebase inherits from BackupEncryptor (T-05-03).
 */
final class AppLockKdf
{
    /**
     * Derive a 32-byte wrap key from $secret (PIN or password) and $salt.
     *
     * The output is SODIUM_CRYPTO_SECRETBOX_KEYBYTES (32) raw bytes,
     * suitable for use as a secretbox key in AppLockKeyWrap.
     *
     * The caller MUST call sodium_memzero($key) after use.
     */
    public function deriveWrapKey(string $secret, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $secret,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    /**
     * Generate a fresh random salt (SODIUM_CRYPTO_PWHASH_SALTBYTES = 16 bytes).
     *
     * Store this in the `kdf_salt` column alongside the wrapped data key.
     * A fresh salt must be generated for every new PIN enrollment.
     */
    public function generateSalt(): string
    {
        return random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    }
}
