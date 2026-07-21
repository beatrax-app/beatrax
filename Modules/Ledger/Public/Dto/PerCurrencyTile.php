<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// One tile-row per currency present in the period with non-zero
// activity. inflow/outflow/net are Money values denominated in
// $currency — the SQL aggregator groups by settled_currency first, so
// the integer SUMs are guaranteed single-currency.
final class PerCurrencyTile extends Data
{
    public function __construct(
        public readonly string $currency,
        public readonly Money $inflow,
        public readonly Money $outflow,
        public readonly Money $net,
    ) {}
}
