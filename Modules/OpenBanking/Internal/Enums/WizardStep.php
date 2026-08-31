<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Enums;

// The steps the connect wizard can be on. The step number reaches the component
// from a client-triggerable Livewire event and decides which branch the modal
// renders, so a number outside this enum would draw a dialog with no controls
// and no way out.
enum WizardStep: int
{
    case Keypair = 1;

    case Register = 2;

    case ApplicationId = 3;

    case Bank = 4;

    case Consent = 5;

    public static function requested(?int $step): ?self
    {
        return $step === null ? null : self::tryFrom($step);
    }
}
