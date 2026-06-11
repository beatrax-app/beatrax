<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

/**
 * Wraps libsodium's Argon2id password-hashing API for PIN storage.
 *
 * The hash() method returns a self-describing Argon2id verifier string
 * (starting with `$argon2id$`) — the same format PHP's password_hash()
 * would produce for PASSWORD_ARGON2ID, but via libsodium so the sodium
 * extension is the single dependency. This string is stored in the
 * `pin_hash` column (D-09 — PIN stored as a per-user Argon2id hash).
 *
 * INTERACTIVE limits are used because the PIN is verified in a web
 * request — the same latency tradeoff BackupEncryptor makes for MODERATE
 * (offline passphrase, can afford more time). For a real-time unlock
 * flow, INTERACTIVE (~64 MB RAM, ~1 op) is the right tier.
 */
final class PinHasher
{
    /**
     * Hash a PIN using Argon2id (sodium_crypto_pwhash_str).
     *
     * Returns a self-describing verifier string starting with `$argon2id$`.
     * The raw PIN is never written to any persistent store.
     */
    public function hash(string $pin): string
    {
        return sodium_crypto_pwhash_str(
            $pin,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        );
    }

    /**
     * Verify a PIN against a stored Argon2id hash.
     *
     * Note the argument order: the hash comes first, matching libsodium's
     * sodium_crypto_pwhash_str_verify($hash, $password) signature.
     */
    public function verify(string $pin, string $hash): bool
    {
        return sodium_crypto_pwhash_str_verify($hash, $pin);
    }
}
