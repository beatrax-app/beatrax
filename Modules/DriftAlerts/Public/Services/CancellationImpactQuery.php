<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Services;

use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Dto\CancellationImpactDto;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * Projected savings if the user cancels a recurring series. Read-only;
 * computed from `recurring_series.monthly_equivalent_minor` exposed via
 * the Recurring module's Public read API.
 *
 * The currency on the returned DTO is the series's original currency
 * (`recurring_series.latest_currency`), NOT necessarily EUR. A series
 * billed in USD (Google Play, cross-currency ICS settlements) returns
 * USD savings — the renderer is responsible for any EUR shadow it
 * surfaces alongside the original-currency primary line.
 *
 * Cross-module access is exclusively via `RecurringSeriesQuery` so the
 * `noRecurringSeriesWritesFromDriftAlerts` invariant (and broader
 * boundary discipline) stays green.
 *
 * Returns null when the series id does not belong to the supplied user
 * (cross-user invocation) or when the series row is missing.
 *
 * Sign: cancellation savings are expressed as positive amounts because
 * cancelling a recurring expense reduces outflow — the magnitude of
 * `monthly_equivalent_minor` is what the user keeps. The Recurring DTO
 * stores `monthlyEquivalent` as a signed Money (negative for expenses,
 * positive for incomes); this query takes the absolute amount so the
 * "save €X/yr" copy reads naturally for both expense series (always
 * the common case) and the rare income-series case where stopping an
 * incoming payment surfaces the equivalent foregone amount.
 */
final readonly class CancellationImpactQuery
{
    public function __construct(private RecurringSeriesQuery $recurringQuery) {}

    public function forSeries(int $seriesId, User $user): ?CancellationImpactDto
    {
        $series = $this->recurringQuery->forSeries($seriesId, $user);
        if ($series === null) {
            return null;
        }

        return $this->toDto($seriesId, $series);
    }

    /**
     * Batched lookup keyed on `recurring_series_id`. Returns one
     * `CancellationImpactDto` per id that resolves to an owned series;
     * unknown / cross-user ids are silently absent from the result.
     *
     * Pages that render multiple series (the `/drift` open tab groups
     * alerts by series and renders a cancellation hint per group) call
     * this once with the full id list instead of looping over
     * `forSeries`, which would issue N queries.
     *
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, CancellationImpactDto>
     */
    public function forSeriesIds(array $seriesIds, User $user): array
    {
        $seriesMap = $this->recurringQuery->forSeriesIds($seriesIds, $user);
        if ($seriesMap === []) {
            return [];
        }

        $result = [];
        foreach ($seriesMap as $seriesId => $series) {
            $result[$seriesId] = $this->toDto($seriesId, $series);
        }

        return $result;
    }

    private function toDto(int $seriesId, RecurringSeriesDto $series): CancellationImpactDto
    {
        $currency = $series->monthlyEquivalent->currency();
        $monthlyMinor = abs($series->monthlyEquivalent->toMinor());
        $annualMinor = $monthlyMinor * 12;

        return new CancellationImpactDto(
            recurringSeriesId: $seriesId,
            monthlySavings: Money::ofMinor($monthlyMinor, $currency),
            annualSavings: Money::ofMinor($annualMinor, $currency),
            currency: $currency,
        );
    }
}
