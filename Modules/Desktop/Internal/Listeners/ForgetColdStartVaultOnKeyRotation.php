<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Events\AppLockPassphraseChanged;

// A passphrase change re-wraps the data key, so whatever the vault holds can no
// longer decrypt. Dropping it turns a guaranteed failed Touch ID unlock into a
// clean PIN prompt, and re-enrolment happens on that unlock.
final readonly class ForgetColdStartVaultOnKeyRotation
{
    public function __construct(
        private ColdStartVault $vault,
    ) {}

    public function handle(AppLockPassphraseChanged $event): void
    {
        $this->vault->forget($event->userId);
    }
}
