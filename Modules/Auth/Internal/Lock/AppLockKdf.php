<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// MODERATE limits (~256 MB, ~500ms) keep this memory-hard, so a PIN's low
// entropy is not cheaply brute-forced out of a stolen database.
final class AppLockKdf
{
    // The caller must sodium_memzero() the returned key bytes after use.
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

    // One fresh salt per PIN enrollment — never reuse one across enrollments.
    public function generateSalt(): string
    {
        return random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    }
}
