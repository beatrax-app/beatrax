<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Enums\ReportGranularity;

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

    // The chain of steps tried in order, coarsest last. The cap was applied
    // once per level and then stopped being checked, so weekly widened to
    // monthly and monthly widened to quarterly -- and quarterly was final. A
    // custom range starting in the year 1000 therefore produced 4108 buckets,
    // and every bucket costs one query per account plus an FX conversion: 7.8
    // seconds on a 164-transaction database, from a plain GET.
    /**
     * @param  callable(CarbonImmutable): CarbonImmutable  $step
     * @return list<Period>
     */
    private function firstStepInsideTheCap(Period $period, array $steps): array
    {
        foreach ($steps as $step) {
            if ($this->countSteps($period, $step['step'], self::MAX_BUCKET_POINTS) <= self::MAX_BUCKET_POINTS) {
                return $this->stepBuckets($period, $step['step'], $step['label']);
            }
        }

        // Nothing on the calendar fits, so the step is computed from the range
        // itself: whole years, widened until the point count is inside the cap.
        $years = max(1, (int) ceil(($period->start->diffInYears($period->endExclusive) + 1) / self::MAX_BUCKET_POINTS));

        return $this->stepBuckets(
            $period,
            static fn (CarbonImmutable $cursor): CarbonImmutable => $cursor->addYears($years),
            self::spanLabel('Y'),
        );
    }

    /**
     * @return list<Period>
     */
    private function generateWeekly(Period $period): array
    {
        return $this->firstStepInsideTheCap($period, [
            ['step' => static fn (CarbonImmutable $c): CarbonImmutable => $c->addWeek(), 'label' => self::startLabel('j M Y')],
            ...self::monthlyAndCoarser(),
        ]);
    }

    /**
     * @return list<Period>
     */
    private function generateMonthly(Period $period): array
    {
        return $this->firstStepInsideTheCap($period, self::monthlyAndCoarser());
    }

    /**
     * @return list<array{step: callable(CarbonImmutable): CarbonImmutable, label: callable(CarbonImmutable, CarbonImmutable): string}>
     */
    private static function monthlyAndCoarser(): array
    {
        return [
            ['step' => static fn (CarbonImmutable $c): CarbonImmutable => $c->addMonthNoOverflow(), 'label' => self::startLabel('M Y')],
            ['step' => static fn (CarbonImmutable $c): CarbonImmutable => $c->addMonthsNoOverflow(3), 'label' => self::spanLabel('M Y')],
        ];
    }

    // Every bucket used to be labelled with the month its range STARTS in,
    // whatever its length, so a weekly report printed three distinct labels
    // over fourteen rows and no row could be told from another.
    /**
     * @return callable(CarbonImmutable, CarbonImmutable): string
     */
    private static function startLabel(string $format): callable
    {
        return static fn (CarbonImmutable $start, CarbonImmutable $endExclusive): string => $start->translatedFormat($format);
    }

    /**
     * @return callable(CarbonImmutable, CarbonImmutable): string
     */
    private static function spanLabel(string $format): callable
    {
        return static function (CarbonImmutable $start, CarbonImmutable $endExclusive) use ($format): string {
            $endInclusive = $endExclusive->subDay();
            $from = $start->translatedFormat($format);
            $to = $endInclusive->translatedFormat($format);

            return $from === $to ? $from : $from.' – '.$to;
        };
    }

    /**
     * @param  callable(CarbonImmutable): CarbonImmutable  $step
     */
    private function countSteps(Period $period, callable $step, int $stopAfter = PHP_INT_MAX): int
    {
        $cursor = $period->start;
        $count = 0;

        while ($cursor->lessThan($period->endExclusive)) {
            $cursor = $step($cursor);
            $count++;

            // Counting a millennium of weeks to learn it is "more than 60"
            // costs 52 000 iterations and answers the same question.
            if ($count > $stopAfter) {
                return $count;
            }
        }

        return $count;
    }

    /**
     * @param  callable(CarbonImmutable): CarbonImmutable  $step
     * @param  callable(CarbonImmutable, CarbonImmutable): string  $label
     * @return list<Period>
     */
    private function stepBuckets(Period $period, callable $step, callable $label): array
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
                label: $label($cursor, $endExclusive),
            );

            $cursor = $endExclusive;
        }

        return $buckets;
    }
}
