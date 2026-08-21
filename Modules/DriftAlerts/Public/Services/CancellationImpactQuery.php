<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Services;

use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Dto\CancellationImpactDto;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

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
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, CancellationImpactDto> batched lookup keyed on recurring_series_id
     *                                           — pages rendering multiple series (the /drift open tab groups alerts by series) call
     *                                           this once with the full id list instead of looping forSeries(), which would issue N
     *                                           queries. Unknown/cross-user ids are silently absent from the result
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
