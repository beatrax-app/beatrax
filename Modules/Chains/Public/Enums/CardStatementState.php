<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Enums;

// The settlement state of a card_statements row, derived from its open
// balance as ics_bulk_settle links accumulate: `open`, `partially_settled`,
// fully `settled`, or `overpaid`. The column stays string; this enum is the
// one canonical spelling callers map through.
enum CardStatementState: string
{
    case Open = 'open';

    case PartiallySettled = 'partially_settled';

    case Settled = 'settled';

    case Overpaid = 'overpaid';
}
