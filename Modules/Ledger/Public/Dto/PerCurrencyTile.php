<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// The SQL aggregator groups by settled_currency first, so each tile's integer
// SUMs are guaranteed to be single-currency.
final class PerCurrencyTile extends Data
{
    public function __construct(
        public readonly string $currency,
        public readonly Money $inflow,
        public readonly Money $outflow,
        public readonly Money $net,
    ) {}
}
