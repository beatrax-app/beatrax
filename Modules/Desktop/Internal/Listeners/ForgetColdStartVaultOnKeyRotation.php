<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Events\AppLockPassphraseChanged;

// A rotated data key leaves whatever the vault holds unable to decrypt, so
// dropping it turns a guaranteed failed Touch ID unlock into a clean PIN
// prompt. A PIN change re-wraps the same key and rotates nothing.
final readonly class ForgetColdStartVaultOnKeyRotation
{
    public function __construct(
        private ColdStartVault $vault,
    ) {}

    public function handle(AppLockPassphraseChanged $event): void
    {
        // Unconditional, this deleted a blob that still unwrapped the correct
        // key: changing your PIN silently switched Touch ID unlock off.
        if (hash_equals($event->oldKek, $event->newKek)) {
            return;
        }

        $this->vault->forget($event->userId);
    }
}
