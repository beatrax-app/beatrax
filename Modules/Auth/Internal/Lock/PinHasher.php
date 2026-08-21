<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// libsodium rather than password_hash(), so sodium stays the single crypto
// dependency. The MODERATE limits must track AppLockKdf's: an attacker cracks
// whichever of the two is weaker.
final class PinHasher
{
    public function hash(string $pin): string
    {
        return sodium_crypto_pwhash_str(
            $pin,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
        );
    }

    // libsodium's verify takes ($hash, $password), the reverse of this method's
    // parameter order — swapping them back makes every verification fail.
    public function verify(string $pin, string $hash): bool
    {
        return sodium_crypto_pwhash_str_verify($hash, $pin);
    }
}
