<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Enums;

// Which amount the transactions list prints per row. BaseOnly spells the euro
// for history — the toggle it drives means "base currency only", and the value
// rides the query string and the reader's saved choice, so renaming it would
// silently reset everyone's.
enum CurrencyView: string
{
    case BaseOnly = 'eur';

    case Original = 'original';
}
