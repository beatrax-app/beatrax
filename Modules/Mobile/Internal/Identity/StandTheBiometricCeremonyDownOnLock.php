<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

// The enclave releases the blob into a transient native slot before PHP has
// decided anything, and an idle re-lock happens with the app still in the
// foreground - where no lifecycle edge fires at all. Locking is the one moment
// the app knows for certain that nothing biometric may still be outstanding.
/**
 * @link ../../../../.docs/design/cold-start-biometric-unlock.md#the-transient-slot-and-what-bounds-it
 */
final readonly class StandTheBiometricCeremonyDownOnLock
{
    public function __construct(
        private BiometricKeyVault $vault,
    ) {}

    // Takes no event: which one it answers is declared where it is bound, and
    // there is nothing in the lock worth reading. Standing the ceremony down is
    // the same act whatever locked the app.
    public function handle(): void
    {
        $this->vault->cancelPrompt();
    }
}
