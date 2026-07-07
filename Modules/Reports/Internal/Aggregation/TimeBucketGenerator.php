<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Ledger\Public\Dto\Period;

/**
 * Splits a `Period` into an ordered list of half-open sub-`Period` buckets
 * (Req 6/7) — the building block `TimeBucketSpendQuery` and the net-worth
 * series query (later plans) loop to produce one grouped total per point on
 * a chart's x-axis.
 *
 * There is no existing Public utility for this: `PeriodQuery` only steps
 * one window at a time, and `Modules\Forecasting\Internal\Pipeline\
 * RangeProjector` has similar cadence-stepping logic but lives in
 * `Forecasting\Internal`, which is arch-fenced from every other module
 * (999.6-RESEARCH.md Pattern 4 / Pitfall 9) — this class deliberately
 * mirrors `PeriodQuery`'s `CarbonImmutable::addMonthNoOverflow()` idiom
 * instead of reaching for that class.
 *
 * Every generated bucket is itself a half-open `[start, endExclusive)`
 * `Period`; the LAST bucket's `endExclusive` is always clamped to the
 * overall range's `endExclusive` so a partial trailing bucket never
 * overshoots the caller's requested window.
 */
final class TimeBucketGenerator
{
    /**
     * Documented point cap (Req 7 / RESEARCH Assumption A1): ~5 years of
     * monthly points. A monthly request whose uncapped bucket count would
     * exceed this ceiling auto-widens to quarterly stepping instead of
     * silently truncating the user's selected range — the range the user
     * asked for is always fully covered, just at a coarser granularity.
     */
    public const MAX_BUCKET_POINTS = 60;

    /**
     * @param  string  $granularity  'monthly' | 'weekly'
     * @return list<Period>
     */
    public function generate(Period $period, string $granularity): array
    {
        return match ($granularity) {
            'weekly' => $this->generateWeekly($period),
            'monthly' => $this->generateMonthly($period),
            default => throw new InvalidArgumentException("Unknown time-bucket granularity: {$granularity}"),
        };
    }

    /**
     * WR-03: `generateMonthly()`'s count-then-widen cap only ever applied
     * to the monthly branch — an unbounded custom range with
     * `granularity: 'weekly'` (e.g. multi-year) could produce hundreds of
     * weekly buckets, one dimension query each, silently violating this
     * class's own documented `MAX_BUCKET_POINTS` cap contract. Mirrors
     * `generateMonthly()`'s own strategy: widen weekly -> monthly (rather
     * than truncating the range) whenever the uncapped weekly point count
     * exceeds the cap. `generateMonthly()` applies its OWN further
     * monthly -> quarterly widening on top of this, so an arbitrarily long
     * custom range never exceeds the cap regardless of starting
     * granularity.
     *
     * @return list<Period>
     */
    private function generateWeekly(Period $period): array
    {
        $uncappedPointCount = $this->countSteps(
            $period,
            static fn (CarbonImmutable $cursor): CarbonImmutable => $cursor->addWeek(),
        );

        if ($uncappedPointCount > self::MAX_BUCKET_POINTS) {
            return $this->generateMonthly($period);
        }

        return $this->stepBuckets(
            $period,
            static fn (CarbonImmutable $cursor): CarbonImmutable => $cursor->addWeek(),
        );
    }

    /**
     * @return list<Period>
     */
    private function generateMonthly(Period $period): array
    {
        $uncappedPointCount = $this->countSteps(
            $period,
            static fn (CarbonImmutable $cursor): CarbonImmutable => $cursor->addMonthNoOverflow(),
        );

        if ($uncappedPointCount > self::MAX_BUCKET_POINTS) {
            // Auto-widen monthly -> quarterly (Req 7 / A1) rather than
            // truncating the range — the full requested window still
            // renders, just as fewer, wider points.
            return $this->stepBuckets(
                $period,
                static fn (CarbonImmutable $cursor): CarbonImmutable => $cursor->addMonthsNoOverflow(3),
            );
        }

        return $this->stepBuckets(
            $period,
            static fn (CarbonImmutable $cursor): CarbonImmutable => $cursor->addMonthNoOverflow(),
        );
    }

    /**
     * @param  callable(CarbonImmutable): CarbonImmutable  $step
     */
    private function countSteps(Period $period, callable $step): int
    {
        $cursor = $period->start;
        $count = 0;

        while ($cursor->lessThan($period->endExclusive)) {
            $cursor = $step($cursor);
            $count++;
        }

        return $count;
    }

    /**
     * @param  callable(CarbonImmutable): CarbonImmutable  $step
     * @return list<Period>
     */
    private function stepBuckets(Period $period, callable $step): array
    {
        $buckets = [];
        $cursor = $period->start;

        while ($cursor->lessThan($period->endExclusive)) {
            $next = $step($cursor);
            // Clamp the last bucket so it never overshoots the overall
            // range's endExclusive (RESEARCH Pattern 4 half-open contract).
            $endExclusive = $next->greaterThan($period->endExclusive) ? $period->endExclusive : $next;

            $buckets[] = new Period(
                start: $cursor,
                endExclusive: $endExclusive,
                label: $cursor->format('M Y'),
            );

            $cursor = $endExclusive;
        }

        return $buckets;
    }
}
