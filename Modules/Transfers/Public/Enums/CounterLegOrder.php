<?php

declare(strict_types=1);

namespace Modules\Transfers\Public\Enums;

// The two callers want a different one of several counter-legs: chain
// resolution the leg nearest the funding date, the import-time pairer the leg
// that was on the books first. Neither is a default — the ordering is the
// caller's bound, like the window and the direction beside it.
enum CounterLegOrder
{
    case NearestToCentre;

    case EarliestBooked;
}
