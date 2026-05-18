<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Recurring\Public\Dto\RecurringOccurrenceDto;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * Per-occurrence projector with envelope + percentile tiers.
 *
 * For one approved recurring series, walks the next-expected date
 * forward by the series's cadence until the horizon end, emitting one
 * `ForecastContribution` per occurrence. Each contribution carries the
 * signed point estimate plus a low/high band derived either from the
 * series's `variance_tolerance_percent` (envelope tier) or from the
 * empirical observed-occurrence distribution (percentile tier).
 *
 * Sign convention (carry-forward from Recurring):
 *   - expense series → `latestAmount` is negative; `low` is more
 *     negative than the point (a "wider absolute" outflow); `high` is
 *     closer to zero.
 *   - income series → `latestAmount` is positive; `low < point < high`.
 *
 * The envelope formula uses `magnitude = abs(point)`,
 * `lowMag = magnitude * (1 - tol)`, `highMag = magnitude * (1 + tol)`,
 * then re-applies the original sign so the daily fold's signed running
 * balance composes correctly.
 *
 * The percentile tier replaces the envelope formula with R-7 linear-
 * interpolation percentiles (P10/P50/P90) computed across the series's
 * observed occurrences. The tier is selected automatically when the
 * series has both a high variance tolerance (>= 40%) AND at least 6
 * historical occurrences. Below 6 occurrences the empirical distribution
 * is too fragile to estimate from, and the projector falls back to the
 * envelope tier.
 *
 * `envelope()` remains the pure-math primitive: it consumes a
 * RecurringSeriesDto only and emits per-occurrence envelope-tier
 * contributions. `project()` is the tier-selecting entry point: it
 * loads occurrences via `RecurringSeriesQuery::occurrencesForSeries`,
 * branches on the volatile-tier trigger, dispatches to envelope or
 * percentile, and routes the result through `CadenceJitter` so the
 * downstream daily fold sees a widened band around uncertain charge
 * dates.
 */
final readonly class RangeProjector
{
    public function __construct(
        private Percentile $percentile,
        private CadenceJitter $jitter,
        private RecurringSeriesQuery $seriesQuery,
    ) {}

    /**
     * Tier-selecting projection entry point. Loads observed occurrences
     * for the series, selects between envelope and percentile based on
     * the variance-tolerance trigger and the occurrence-count fallback,
     * then routes through CadenceJitter so the downstream daily fold
     * sees a widened band across a ±3-day window per occurrence.
     *
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
            // distribution AND uncertain charge dates — the variance
            // tolerance signals both at once. The jitter widens the
            // band across a ±3-day window so the daily fold's
            // quadrature shows the genuine date uncertainty.
            $contributions = $this->percentileTier($series, $accountId, $asOf, $horizonDays, $occurrences);

            return $this->jitter->apply($contributions, 3);
        }

        // Envelope-tier series have tight variance tolerance AND, by
        // construction, predictable charge dates. The daily fold sees
        // one signed point per occurrence with the envelope-derived
        // low/high band; the per-occurrence date is already the most
        // honest signal we have. Applying jitter here would smear the
        // band across uncertainty the series does not actually carry.
        return $this->envelope($series, $accountId, $asOf, $horizonDays, $user);
    }

    /**
     * Envelope-tier primitive — emits one contribution per occurrence
     * date with a low/high band derived from the series's
     * `variance_tolerance_percent`. Exposed publicly so unit tests can
     * exercise the envelope math directly without the percentile or
     * jitter stages.
     *
     * @return list<ForecastContribution>
     */
    public function envelope(
        RecurringSeriesDto $series,
        int $accountId,
        CarbonImmutable $asOf,
        int $horizonDays,
        User $user,
    ): array {
        unset($user); // The envelope tier is per-series only; user is consumed by the percentile tier above.

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
        // negative → low carries the larger magnitude with the original
        // sign, and high carries the smaller magnitude. For an income
        // the opposite is true. The convention guarantees
        // `low <= point <= high` when read as raw signed integers.
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
     * Percentile-tier primitive — emits one contribution per occurrence
     * date with a low/point/high triple sourced from the empirical
     * distribution of the series's observed amounts (P10, P50, P90 via
     * R-7 linear interpolation). The triple is the same for every
     * occurrence in the horizon: the percentile snapshot reflects the
     * full empirical distribution, not occurrence-by-occurrence
     * variance.
     *
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
        // `low <= point <= high` holds whether the series is income
        // (positive) or expense (negative). For an expense distribution
        // p10 is "the more negative outflow" (the larger absolute
        // amount); for an income p90 is "the larger positive amount" —
        // either way, sorting puts the lowest signed value into
        // lowMinor and the highest into highMinor.
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

    /**
     * Advance the cursor by one cadence step. Returns null for
     * cadences without a forward-projection rule (`irregular`); the
     * envelope and percentile tiers both produce no contributions for
     * those series in this wave.
     */
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
