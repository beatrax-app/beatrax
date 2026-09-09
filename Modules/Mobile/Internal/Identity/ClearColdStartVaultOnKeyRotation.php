<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Modules\Auth\Public\Events\AppLockPassphraseChanged;

final readonly class ClearColdStartVaultOnKeyRotation
{
    public function __construct(
        private MobileColdStartVault $vault,
    ) {}

    public function handle(AppLockPassphraseChanged $event): void
    {
        // Normal PIN change → data key unchanged → the enclave blob still
        // unwraps to the correct key. Nothing to do.
        if (hash_equals($event->oldKek, $event->newKek)) {
            return;
        }

        // The data key actually rotated: the enclave blob wraps the OLD key and
        // is now useless. Clear it so the user re-enrolls under the new key.
        if ($this->vault->isEnrolled($event->userId)) {
            $this->vault->forget($event->userId);
        }
    }
}
