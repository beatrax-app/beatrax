<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// Derives a symmetric wrap key from a PIN or password using Argon2id.
// MODERATE limits (~256 MB RAM, ~500ms/derivation) raise the per-guess
// cost and make derivation memory-hard, resisting an offline brute-force
// of the low-entropy PIN against a stolen database.
final class AppLockKdf
{
    // The caller MUST zero the returned key bytes with sodium_memzero()
    // after use.
    public function deriveWrapKey(string $secret, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $secret,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    // A fresh salt must be generated for every new PIN enrollment; store
    // it in the kdf_salt column alongside the wrapped data key.
    public function generateSalt(): string
    {
        return random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    }
}
