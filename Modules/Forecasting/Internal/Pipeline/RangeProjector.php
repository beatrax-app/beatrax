<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Recurring\Public\Dto\RecurringOccurrenceDto;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Services\RecurringOccurrenceQuery;

final readonly class RangeProjector
{
    // Both bars must clear before a series escalates to the percentile tier:
    // a wide enough tolerance, and enough charges to build a distribution.
    private const int HIGH_VARIANCE_THRESHOLD_PERCENT = 40;

    private const int MIN_OCCURRENCES_FOR_PERCENTILE = 6;

    public function __construct(
        private Percentile $percentile,
        private CadenceWalk $walk,
        private RecurringOccurrenceQuery $occurrenceQuery,
    ) {}

    /**
     * @return list<ForecastContribution>
     */
    public function project(
        RecurringSeriesDto $series,
        int $accountId,
        CarbonImmutable $asOf,
        int $horizonDays,
        User $user,
    ): array {
        $isHighVariance = $series->varianceTolerancePercent >= self::HIGH_VARIANCE_THRESHOLD_PERCENT;

        $occurrences = $isHighVariance
            ? $this->occurrenceQuery->occurrencesForSeries($series->seriesId, $user)
            : [];

        $usePercentile = $isHighVariance && count($occurrences) >= self::MIN_OCCURRENCES_FOR_PERCENTILE;

        if ($usePercentile) {
            return $this->percentileTier($series, $accountId, $asOf, $horizonDays, $occurrences);
        }

        // Left un-marked: an envelope-tier series has predictable charge dates,
        // so smearing the band would invent uncertainty it does not carry.
        return $this->envelope($series, $accountId, $asOf, $horizonDays, $user);
    }

    /**
     * @return list<ForecastContribution>
     */
    public function envelope(
        RecurringSeriesDto $series,
        int $accountId,
        CarbonImmutable $asOf,
        int $horizonDays,
        User $user,
    ): array {
        unset($user);

        $next = $series->nextExpectedAt;
        if ($next === null) {
            return [];
        }

        $cadence = $series->cadence;
        if ($cadence === SeriesCadence::Irregular) {
            return [];
        }

        $horizonEnd = $asOf->addDays($horizonDays);
        $tol = $series->varianceTolerancePercent;

        $point = $series->latestAmount->toMinor();
        $currency = $series->latestAmount->currency();

        $magnitude = abs($point);
        $lowMag = (int) round($magnitude * (100 - $tol) / 100);
        $highMag = (int) round($magnitude * (100 + $tol) / 100);
        $sign = $point < 0 ? -1 : 1;

        // A wider expense is more negative, so its low carries the larger
        // magnitude. Flipping keeps low <= point <= high as signed integers.
        if ($sign < 0) {
            $lowMinor = -$highMag;
            $highMinor = -$lowMag;
        } else {
            $lowMinor = $lowMag;
            $highMinor = $highMag;
        }

        $contributions = [];

        foreach ($this->walk->datesInHorizon($next, $cadence, $asOf, $horizonEnd, $series->billingDay) as $date) {
            $contributions[] = new ForecastContribution(
                date: $date,
                pointMinor: $point,
                lowMinor: $lowMinor,
                highMinor: $highMinor,
                currency: $currency,
                seriesId: $series->seriesId,
                accountId: $accountId,
            );
        }

        return $contributions;
    }

    /**
     * @param  list<RecurringOccurrenceDto>  $occurrences
     * @return list<ForecastContribution>
     */
    private function percentileTier(
        RecurringSeriesDto $series,
        int $accountId,
        CarbonImmutable $asOf,
        int $horizonDays,
        array $occurrences,
    ): array {
        $next = $series->nextExpectedAt;
        $cadence = $series->cadence;
        if ($next === null || $cadence === SeriesCadence::Irregular) {
            return [];
        }

        $amounts = [];
        foreach ($occurrences as $occ) {
            $amounts[] = $occ->observedAmount->toMinor();
        }
        if ($amounts === []) {
            return [];
        }

        $p10 = $this->percentile->p10($amounts);
        $p50 = $this->percentile->p50($amounts);
        $p90 = $this->percentile->p90($amounts);

        // Sorted as signed integers, so low <= point <= high holds for an
        // expense (negative) as well as an income.
        $triple = [$p10, $p50, $p90];
        sort($triple, SORT_NUMERIC);
        $lowMinor = $triple[0];
        $pointMinor = $triple[1];
        $highMinor = $triple[2];

        $currency = $series->latestAmount->currency();

        $horizonEnd = $asOf->addDays($horizonDays);
        $contributions = [];

        foreach ($this->walk->datesInHorizon($next, $cadence, $asOf, $horizonEnd, $series->billingDay) as $date) {
            $contributions[] = new ForecastContribution(
                date: $date,
                pointMinor: $pointMinor,
                lowMinor: $lowMinor,
                highMinor: $highMinor,
                currency: $currency,
                seriesId: $series->seriesId,
                accountId: $accountId,
                dateIsUncertain: true,
            );
        }

        return $contributions;
    }
}
