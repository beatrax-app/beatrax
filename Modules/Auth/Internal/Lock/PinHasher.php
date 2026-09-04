<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Modules\Core\Public\Contracts\KdfCost;

// libsodium rather than password_hash(), so sodium stays the single crypto
// dependency. The cost is the same injected one AppLockKdf derives at, and
// must stay so: an attacker cracks whichever of the two is weaker.
final class PinHasher
{
    public function __construct(private readonly KdfCost $cost) {}

    // The parameters go into the returned string, so a hash written at one
    // cost still verifies after the shipped cost is raised.
    public function hash(string $pin): string
    {
        return sodium_crypto_pwhash_str(
            $pin,
            $this->cost->opslimit(),
            $this->cost->memlimit(),
        );
    }

    // libsodium's verify takes ($hash, $password), the reverse of this method's
    // parameter order — swapping them back makes every verification fail.
    public function verify(string $pin, string $hash): bool
    {
        return sodium_crypto_pwhash_str_verify($hash, $pin);
    }
}
