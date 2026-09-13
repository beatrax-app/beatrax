<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Services;

// One meter over the recovery sheet, not one per screen that spends it. The
// reset page and the sign-in escape guess the same ten secrets, so a counter
// each would hand a caller the cap twice over against one account.
final readonly class RecoveryAttemptThrottle extends GuestCredentialMeter
{
    protected function keyPrefix(): string
    {
        return 'auth.recovery-code:';
    }
}
