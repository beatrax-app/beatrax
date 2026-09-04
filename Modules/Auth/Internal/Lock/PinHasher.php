<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// libsodium rather than password_hash(), so sodium stays the single crypto
// dependency. The cost comes from the same PwhashLimits AppLockKdf derives at:
// an attacker cracks whichever of the two is weaker.
final readonly class PinHasher
{
    public function __construct(
        private PwhashLimits $limits,
    ) {}

    public function hash(string $pin): string
    {
        return sodium_crypto_pwhash_str(
            $pin,
            $this->limits->opslimit,
            $this->limits->memlimit,
        );
    }

    // libsodium's verify takes ($hash, $password), the reverse of this method's
    // parameter order — swapping them back makes every verification fail. The
    // cost is read back out of the stored string, so a hash minted at another
    // tier still verifies.
    public function verify(string $pin, string $hash): bool
    {
        return sodium_crypto_pwhash_str_verify($hash, $pin);
    }
}
