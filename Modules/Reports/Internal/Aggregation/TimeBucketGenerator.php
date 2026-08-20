<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Public\Enums\ReportGranularity;

final class TimeBucketGenerator
{
    // ~5 years of monthly points. A monthly request whose uncapped bucket
    // count would exceed this ceiling auto-widens to quarterly stepping
    // instead of truncating the user's selected range — the full window
    // still renders, just at a coarser granularity.
    public const MAX_BUCKET_POINTS = 60;

    /**
     * @return list<Period>
     */
    public function generate(Period $period, ReportGranularity $granularity): array
    {
        return match ($granularity) {
            ReportGranularity::Weekly => $this->generateWeekly($period),
            ReportGranularity::Monthly => $this->generateMonthly($period),
        };
    }

    // Mirrors generateMonthly()'s own strategy: widen weekly -> monthly
    // (rather than truncating the range) whenever the uncapped weekly
    // point count exceeds the cap; generateMonthly() then applies its own
    // further monthly -> quarterly widening on top of this.
    /**
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
            // Auto-widen monthly -> quarterly rather than truncating the
            // range — the full requested window still renders, just as
            // fewer, wider points.
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
            // range's endExclusive (half-open Period contract).
            $endExclusive = $next->greaterThan($period->endExclusive) ? $period->endExclusive : $next;

            $buckets[] = new Period(
                start: $cursor,
                endExclusive: $endExclusive,
                label: $cursor->translatedFormat('M Y'),
            );

            $cursor = $endExclusive;
        }

        return $buckets;
    }
}
