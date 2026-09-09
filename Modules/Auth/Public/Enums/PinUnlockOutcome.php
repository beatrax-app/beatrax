<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Enums;

// What a PIN unlock did, for a screen that has to say so. Refused and
// OutracedByAPinChange are kept apart because only one of them spent an
// attempt: an unlock the PIN changed underneath records neither outcome, so
// reporting it as a refusal names a count the reader can watch stand still.
enum PinUnlockOutcome
{
    case Unlocked;

    case Refused;

    case OutracedByAPinChange;
}
