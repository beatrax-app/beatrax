<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;

/**
 * Envelope-tier per-occurrence projector.
 *
 * For one approved recurring series, walks the next-expected date
 * forward by the series's cadence until the horizon end, emitting one
 * `ForecastContribution` per occurrence. Each contribution carries the
 * signed point estimate plus a low/high band derived from the series's
 * `variance_tolerance_percent`.
 *
 * Sign convention (Phase 8 D-816 carry-forward):
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
 * The projector reads nothing from the DB; it consumes only the
 * in-memory series DTO. The percentile tier (Wave 5) introduces a
 * call into `RecurringSeriesQuery::occurrencesForSeries` to read the
 * observed empirical distribution; until then the envelope is
 * sufficient and deterministic.
 */
final readonly class RangeProjector
{
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
        unset($user); // Wave 2 envelope tier is per-series only; user is reserved for the Wave 5 percentile-tier query.

        $next = $series->nextExpectedAt;
        if ($next === null) {
            return [];
        }

        $cadence = $series->cadence;
        // Cadences without a deterministic forward-projection rule
        // (`irregular`, anything else) emit nothing in the envelope
        // tier. Wave 5's percentile tier handles irregular series via
        // observed-distribution resampling instead.
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
        // the opposite is true. Encode the convention by applying the
        // sign to the magnitudes such that `low <= point <= high` for
        // an income series and `low >= point >= high` for an expense
        // when read as raw signed integers — i.e. `low` always
        // represents the lower bound of the SIGNED interval.
        if ($sign < 0) {
            $lowMinor = -$highMag;  // most-negative outflow
            $highMinor = -$lowMag;  // least-negative outflow
        } else {
            $lowMinor = $lowMag;
            $highMinor = $highMag;
        }

        $contributions = [];
        $cursor = $next;

        // Walk forward by cadence until we exceed the horizon end.
        while ($cursor->lessThanOrEqualTo($horizonEnd)) {
            if ($cursor->greaterThanOrEqualTo($asOf)) {
                $contributions[] = new ForecastContribution(
                    date: $cursor,
                    pointMinor: $point,
                    lowMinor: $lowMinor,
                    highMinor: $highMinor,
                    currency: $currency,
                    fxRateUsed: null, // Wave 2 baseline-only: fx_rate is read at the fold boundary if needed.
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
     * envelope tier produces no contributions for those series and
     * Wave 5's percentile tier handles them via observed-distribution
     * resampling instead.
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
