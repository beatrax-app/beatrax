<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class CancellationImpactDto extends Data
{
    /**
     * @param  Money  $monthlySavings  positive Money value — cancellation savings always
     *                                 reduce expenses, so sign is dropped at the boundary
     * @param  Money  $annualSavings  same sign convention as $monthlySavings
     * @param  string  $currency  the recurring series's original currency
     *                            (recurring_series.latest_currency), not necessarily EUR — the renderer picks up a
     *                            separate cross-currency projection if the dashboard needs the EUR-converted figure
     */
    public function __construct(
        public readonly int $recurringSeriesId,
        public readonly Money $monthlySavings,
        public readonly Money $annualSavings,
        public readonly string $currency,
    ) {}
}
