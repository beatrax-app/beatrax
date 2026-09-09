<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Support;

use Modules\Auth\Internal\Lock\AppLockKdf;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\PinHasher;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Core\Public\Contracts\KdfCost;

// Counts Argon2id derivations by counting the cost reads that precede them:
// AppLockKdf::deriveWrapKey() and PinHasher::hash() each ask for opslimit()
// exactly once. PinHasher::verify() is invisible here — libsodium reads that
// cost out of the stored hash string rather than from this contract — so a
// test that needs to prove the verifier was skipped ruins the hash instead.
final class CountingKdfCost implements KdfCost
{
    public int $derivations = 0;

    // The lock's collaborators are container singletons resolved during boot,
    // and a singleton keeps the cost it was built with, so binding this over
    // the contract is only half of installing it.
    public static function install(): self
    {
        $cost = new self;

        app()->instance(KdfCost::class, $cost);

        foreach ([AppLockKdf::class, PinHasher::class, AppLockProvisioner::class, PinVerificationService::class] as $singleton) {
            app()->forgetInstance($singleton);
        }

        return $cost;
    }

    public function opslimit(): int
    {
        $this->derivations++;

        return 1;
    }

    public function memlimit(): int
    {
        return 8192;
    }
}
