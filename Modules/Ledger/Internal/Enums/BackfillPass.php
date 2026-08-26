<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Enums;

// The key a completion marker is filed under. A one-shot pass over rows an
// older release wrote gets a case here rather than a string literal, because
// the value is written to `ledger_backfill_state.backfill` and read back by a
// second call site that must spell it identically.
enum BackfillPass: string
{
    case AsnDescriptionDelimiters = 'asn-description-delimiters';
}
