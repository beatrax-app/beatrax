<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Projected savings if the user cancels a recurring series. Read-only;
 * computed at query time from the series's `monthly_equivalent_minor`
 * and the cadence multiplier that converts a monthly figure into an
 * annual one.
 *
 * `currency` is the recurring series's original currency
 * (`recurring_series.latest_currency`) — NOT necessarily EUR. The
 * renderer is responsible for picking up `eurEquivalent` from any
 * separate cross-currency projection if the dashboard needs the
 * EUR-converted figure.
 *
 * Both `monthlySavings` and `annualSavings` are positive Money values
 * representing the absolute amount of cash flow no longer leaving the
 * account; sign is dropped at the boundary because cancellation
 * savings always reduce expenses.
 */
final class CancellationImpactDto extends Data
{
    public function __construct(
        public readonly int $recurringSeriesId,
        public readonly Money $monthlySavings,
        public readonly Money $annualSavings,
        public readonly string $currency,
    ) {}
}
