<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// The cost is injected rather than named here, and PwhashLimits keeps MODERATE
// (~256 MB, ~500ms) as the shipped tier, so a PIN's low entropy is not cheaply
// brute-forced out of a stolen database.
final readonly class AppLockKdf
{
    public function __construct(
        private PwhashLimits $limits,
    ) {}

    // Returns raw key material that nothing here retains, so only the caller
    // can sodium_memzero() it once the wrap or unwrap is done.
    public function deriveWrapKey(string $secret, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $secret,
            $salt,
            $this->limits->opslimit,
            $this->limits->memlimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    public function generateSalt(): string
    {
        return random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    }
}
