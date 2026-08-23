<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal;

use Carbon\CarbonImmutable;
use Modules\Recurring\Public\Enums\SeriesCadence;

/**
 * @link ../../../.docs/features/recurring/series-detection.md#missed-periods-and-why-the-cadence-snaps-on-the-unfiltered-median
 */
final class CadenceInferrer
{
    private const WEEKLY_MAX_EXCLUSIVE = 10;

    private const MONTHLY_MIN = 10;

    private const MONTHLY_MAX = 45;

    private const QUARTERLY_MIN = 80;

    private const QUARTERLY_MAX = 100;

    private const YEARLY_MIN = 350;

    private const YEARLY_MAX = 380;

    private const MISSED_INTERVAL_MULTIPLIER = 1.8;

    private const MAX_MISSED_PER_WINDOW = 2;

    private const MISSED_WINDOW_SIZE = 6;

    private const CONFIDENCE_LOW_STDDEV_THRESHOLD = 5.0;

    private const DAYS_PER_WEEK = 7;

    private const MONTHS_PER_QUARTER = 3;

    /**
     * @param  list<CarbonImmutable>  $sortedTimestamps  ascending
     * @return array{
     *   cadence: SeriesCadence,
     *   median_interval_days: float,
     *   next_expected_at: ?CarbonImmutable,
     *   confidence_low: bool,
     *   missed_count: int,
     * }
     */
    public function infer(array $sortedTimestamps): array
    {
        if (count($sortedTimestamps) < 2) {
            return [
                'cadence' => SeriesCadence::Irregular,
                'median_interval_days' => 0.0,
                'next_expected_at' => null,
                'confidence_low' => false,
                'missed_count' => 0,
            ];
        }

        // No defensive abs(): the ascending contract makes the diff
        // non-negative, and an unsorted caller breaks the cadence math anyway.
        $intervals = [];
        $previous = null;
        foreach ($sortedTimestamps as $timestamp) {
            if ($previous !== null) {
                $intervals[] = $previous->diffInDays($timestamp);
            }
            $previous = $timestamp;
        }

        $provisionalMedian = self::median($intervals);

        $filtered = [];
        $missedCount = 0;
        $missedFlags = [];
        $missedThreshold = $provisionalMedian * self::MISSED_INTERVAL_MULTIPLIER;
        foreach ($intervals as $interval) {
            if ($provisionalMedian > 0.0 && $interval > $missedThreshold) {
                $missedCount++;
                $missedFlags[] = true;

                continue;
            }
            $filtered[] = $interval;
            $missedFlags[] = false;
        }

        // Too many misses in any rolling window means the cluster is too
        // unstable to snap into a band at all.
        if (self::exceedsMissedWindowCap($missedFlags)) {
            return [
                'cadence' => SeriesCadence::Irregular,
                'median_interval_days' => $provisionalMedian,
                'next_expected_at' => null,
                'confidence_low' => true,
                'missed_count' => $missedCount,
            ];
        }

        $refinedMedian = $filtered === [] ? $provisionalMedian : self::median($filtered);
        $stddev = self::stddev($filtered);
        $confidenceLow = $stddev > self::CONFIDENCE_LOW_STDDEV_THRESHOLD;

        // Provisional, not refined: snapping on the refined median would let
        // the missed-interval filter rescue a one-outlier noise cluster into a
        // cadence it does not have. The class link works the case through.
        $cadence = self::snapToBand($provisionalMedian);

        $nextExpectedAt = null;
        if ($cadence->isRegular()) {
            $last = $sortedTimestamps[count($sortedTimestamps) - 1];
            $nextExpectedAt = self::stepOnePeriod($last, $sortedTimestamps[0], $cadence)
                ?? $last->addDays((int) round($refinedMedian));
        }

        return [
            'cadence' => $cadence,
            'median_interval_days' => $refinedMedian,
            'next_expected_at' => $nextExpectedAt,
            'confidence_low' => $confidenceLow,
            'missed_count' => $missedCount,
        ];
    }

    // A day count drifts a monthly bill off its own day of the month every
    // period, so the projection steps the calendar unit the band names. A band
    // with no step of its own answers null and the caller falls back to the
    // day median.
    private static function stepOnePeriod(CarbonImmutable $last, CarbonImmutable $first, SeriesCadence $cadence): ?CarbonImmutable
    {
        return match ($cadence) {
            SeriesCadence::Weekly => $last->addDays(self::DAYS_PER_WEEK),
            SeriesCadence::Monthly => self::onBillingDay($last->addMonthNoOverflow(), $first),
            SeriesCadence::Quarterly => self::onBillingDay($last->addMonthsNoOverflow(self::MONTHS_PER_QUARTER), $first),
            SeriesCadence::Yearly => self::onBillingDay($last->addYearNoOverflow(), $first),
            SeriesCadence::Irregular => null,
        };
    }

    // February clamps a bill charged on the 31st to the 28th, and a stepped date
    // never recovers the 31st from there. The billing day is read off the first
    // posting every time instead, so the step out of February restores it.
    private static function onBillingDay(CarbonImmutable $stepped, CarbonImmutable $first): CarbonImmutable
    {
        return $stepped->setDay(min($first->day, $stepped->daysInMonth));
    }

    private static function snapToBand(float $medianDays): SeriesCadence
    {
        // The gaps between bands fall through to irregular rather than being
        // absorbed by whichever comparison happened to come last.
        return match (true) {
            $medianDays > 0.0 && $medianDays < self::WEEKLY_MAX_EXCLUSIVE => SeriesCadence::Weekly,
            $medianDays >= self::MONTHLY_MIN && $medianDays <= self::MONTHLY_MAX => SeriesCadence::Monthly,
            $medianDays >= self::QUARTERLY_MIN && $medianDays <= self::QUARTERLY_MAX => SeriesCadence::Quarterly,
            $medianDays >= self::YEARLY_MIN && $medianDays <= self::YEARLY_MAX => SeriesCadence::Yearly,
            default => SeriesCadence::Irregular,
        };
    }

    /**
     * @param  list<bool>  $missedFlags
     */
    private static function exceedsMissedWindowCap(array $missedFlags): bool
    {
        $window = self::MISSED_WINDOW_SIZE;
        $cap = self::MAX_MISSED_PER_WINDOW;
        $count = count($missedFlags);
        if ($count < $window) {
            return false;
        }
        for ($i = 0; $i + $window <= $count; $i++) {
            $missedInWindow = 0;
            for ($j = $i; $j < $i + $window; $j++) {
                if ($missedFlags[$j]) {
                    $missedInWindow++;
                }
            }
            if ($missedInWindow > $cap) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<float>  $values
     */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return $values[$mid];
        }

        return ($values[$mid - 1] + $values[$mid]) / 2.0;
    }

    /**
     * @param  list<float>  $values
     */
    private static function stddev(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $count;
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $variance /= $count;

        return sqrt($variance);
    }
}
