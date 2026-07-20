<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Recurring\Public\Dto\RecurringOccurrenceDto;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final readonly class RangeProjector
{
    public function __construct(
        private Percentile $percentile,
        private CadenceJitter $jitter,
        private RecurringSeriesQuery $seriesQuery,
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
        $isHighVariance = $series->varianceTolerancePercent >= 40;

        // Load observed occurrences only when the variance trigger fires;
        // envelope-only series do not need the DB read.
        $occurrences = $isHighVariance
            ? $this->seriesQuery->occurrencesForSeries($series->seriesId, $user)
            : [];

        $usePercentile = $isHighVariance && count($occurrences) >= 6;

        if ($usePercentile) {
            // Percentile-tier series carry both a wide empirical
            // distribution AND uncertain charge dates, so the jitter
            // widens the band across a ±3-day window around each date.
            $contributions = $this->percentileTier($series, $accountId, $asOf, $horizonDays, $occurrences);

            return $this->jitter->apply($contributions, 3);
        }

        // Envelope-tier series have predictable charge dates, so the
        // per-occurrence date is already the most honest signal — jitter
        // would smear the band across uncertainty it does not carry.
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
        // The envelope tier is per-series only; user is consumed by the
        // percentile tier's occurrence lookup in project() above.
        unset($user);

        $next = $series->nextExpectedAt;
        if ($next === null) {
            return [];
        }

        $cadence = $series->cadence;
        if (! in_array($cadence, ['weekly', 'monthly', 'quarterly', 'yearly'], true)) {
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

        // For an expense (negative point) a "wider" outflow is more
        // negative, so low carries the larger magnitude; for an income
        // the opposite holds. This guarantees low <= point <= high
        // when read as raw signed integers.
        if ($sign < 0) {
            $lowMinor = -$highMag;
            $highMinor = -$lowMag;
        } else {
            $lowMinor = $lowMag;
            $highMinor = $highMag;
        }

        $contributions = [];
        $cursor = $next;

        while ($cursor->lessThanOrEqualTo($horizonEnd)) {
            if ($cursor->greaterThanOrEqualTo($asOf)) {
                $contributions[] = new ForecastContribution(
                    date: $cursor,
                    pointMinor: $point,
                    lowMinor: $lowMinor,
                    highMinor: $highMinor,
                    currency: $currency,
                    fxRateUsed: $series->latestFxRateUsed,
                    seriesId: $series->seriesId,
                    accountId: $accountId,
                );
            }

            $cursor = $this->advance($cursor, $cadence);
            if ($cursor === null) {
                break;
            }
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
        if ($next === null) {
            return [];
        }

        $cadence = $series->cadence;
        if (! in_array($cadence, ['weekly', 'monthly', 'quarterly', 'yearly'], true)) {
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

        // Sort the percentile triple as signed integers so
        // low <= point <= high holds whether the series is income
        // (positive) or expense (negative) — sorting always puts the
        // lowest signed value into lowMinor and the highest into highMinor.
        $triple = [$p10, $p50, $p90];
        sort($triple, SORT_NUMERIC);
        $lowMinor = $triple[0];
        $pointMinor = $triple[1];
        $highMinor = $triple[2];

        $currency = $series->latestAmount->currency();

        $horizonEnd = $asOf->addDays($horizonDays);
        $contributions = [];
        $cursor = $next;

        while ($cursor->lessThanOrEqualTo($horizonEnd)) {
            if ($cursor->greaterThanOrEqualTo($asOf)) {
                $contributions[] = new ForecastContribution(
                    date: $cursor,
                    pointMinor: $pointMinor,
                    lowMinor: $lowMinor,
                    highMinor: $highMinor,
                    currency: $currency,
                    fxRateUsed: $series->latestFxRateUsed,
                    seriesId: $series->seriesId,
                    accountId: $accountId,
                );
            }

            $cursor = $this->advance($cursor, $cadence);
            if ($cursor === null) {
                break;
            }
        }

        return $contributions;
    }

    private function advance(CarbonImmutable $cursor, string $cadence): ?CarbonImmutable
    {
        return match ($cadence) {
            'weekly' => $cursor->addDays(7),
            'monthly' => $cursor->addMonthNoOverflow(),
            'quarterly' => $cursor->addMonthsNoOverflow(3),
            'yearly' => $cursor->addYearNoOverflow(),
            default => null,
        };
    }
}
