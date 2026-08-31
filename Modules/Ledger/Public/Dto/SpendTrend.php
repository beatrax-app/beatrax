<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

// All amounts are minor units of $currency, the reader's display currency;
// totalDeltaMinor and each CategoryDelta::deltaMinor are positive when spend
// went up.
final class SpendTrend extends Data
{
    /**
     * @param  list<CategoryDelta>  $movers
     * @param  list<string>  $unconvertedCurrencies  codes left out of the totals for want of a rate
     */
    public function __construct(
        public readonly int $currentTotalMinor,
        public readonly int $previousTotalMinor,
        public readonly int $totalDeltaMinor,
        public readonly string $currency,
        public readonly string $currentLabel,
        public readonly string $previousLabel,
        public readonly array $movers,
        public readonly array $unconvertedCurrencies = [],
        public readonly bool $previousPeriodIsReachable = true,
    ) {}

    // Spend nothing but an unpriced currency and both totals are zero, which
    // used to hide the card and with it the only notice a figure was left out.
    // Tested against nought, not above it: these totals are signed, so a
    // period whose refunds outran its spending has money in it, not nothing.

    // None of that applies where the ledger never reached the period being
    // compared against. Every figure here is a difference, so with no second
    // side there is nothing to draw: a reader whose first row was four days
    // old was shown their whole spend as a rise, against a month with no rows.
    public function hasComparison(): bool
    {
        if (! $this->previousPeriodIsReachable) {
            return false;
        }

        return $this->currentTotalMinor !== 0
            || $this->previousTotalMinor !== 0
            || $this->unconvertedCurrencies !== [];
    }
}
