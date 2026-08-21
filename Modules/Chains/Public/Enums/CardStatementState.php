<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Enums;

// Derived from the row's open balance as ics_bulk_settle links accumulate. The
// column stays a string (a trigger enforces the vocabulary); this enum is the
// one canonical spelling callers map through.
enum CardStatementState: string
{
    case Open = 'open';

    case PartiallySettled = 'partially_settled';

    case Settled = 'settled';

    case Overpaid = 'overpaid';
}
