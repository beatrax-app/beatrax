<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Public\Enums\ReportGranularity;

final class TimeBucketGenerator
{
    // ~5 years of monthly points. Exceeding it widens the stepping rather than
    // truncating the range, so the full window still renders, just coarser.
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

    // Widens weekly -> monthly over the cap; generateMonthly() then applies its
    // own monthly -> quarterly widening on top.
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
            // Widen to quarterly rather than truncate the requested window.
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
