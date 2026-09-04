<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Modules\Core\Public\Contracts\KdfCost;

// The shipped cost is memory-hard, so a PIN's low entropy is not cheaply
// brute-forced out of a stolen database. It arrives injected rather than
// named here because the suite derives thousands of these keys.
final class AppLockKdf
{
    public function __construct(private readonly KdfCost $cost) {}

    // Returns raw key material that nothing here retains, so only the caller
    // can sodium_memzero() it once the wrap or unwrap is done.
    public function deriveWrapKey(string $secret, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $secret,
            $salt,
            $this->cost->opslimit(),
            $this->cost->memlimit(),
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    public function generateSalt(): string
    {
        return random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    }
}
