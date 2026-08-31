<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\WeekStart;
use Modules\Recurring\Public\Enums\SeriesCadence;

/**
 * @link ../../../.docs/features/recurring/series-detection.md#missed-periods-and-why-the-cadence-snaps-on-the-unfiltered-median
 */
final class CadenceInferrer
{
    private const int WEEKLY_MAX_EXCLUSIVE = 10;

    private const int MONTHLY_MIN = 10;

    private const int MONTHLY_MAX = 45;

    private const int QUARTERLY_MIN = 80;

    private const int QUARTERLY_MAX = 100;

    private const int YEARLY_MIN = 350;

    private const int YEARLY_MAX = 380;

    private const float MISSED_INTERVAL_MULTIPLIER = 1.8;

    private const int MAX_MISSED_PER_WINDOW = 2;

    private const int MISSED_WINDOW_SIZE = 6;

    private const float CONFIDENCE_LOW_STDDEV_THRESHOLD = 5.0;

    private const int MONTHS_PER_QUARTER = 3;

    /**
     * @param  list<CarbonImmutable>  $sortedTimestamps  ascending
     */
    public function infer(array $sortedTimestamps): InferredCadence
    {
        if (count($sortedTimestamps) < 2) {
            return new InferredCadence(SeriesCadence::Irregular, 0.0, null, false, 0);
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
            return new InferredCadence(SeriesCadence::Irregular, $provisionalMedian, null, true, $missedCount);
        }

        $refinedMedian = $filtered === [] ? $provisionalMedian : self::median($filtered);
        $stddev = self::stddev($filtered);
        $confidenceLow = $stddev > self::CONFIDENCE_LOW_STDDEV_THRESHOLD;

        // Provisional, not refined: snapping on the refined median would let
        // the missed-interval filter rescue a one-outlier noise cluster into a
        // cadence it does not have. The class link works the case through.
        $cadence = self::snapToBand($provisionalMedian);

        $nextExpectedAt = null;
        $billingDay = null;
        if ($cadence->isRegular()) {
            $last = $sortedTimestamps[count($sortedTimestamps) - 1];
            $billingDay = self::billingDay($sortedTimestamps);
            $nextExpectedAt = self::stepOnePeriod($last, $billingDay, $cadence);
        }

        return new InferredCadence($cadence, $refinedMedian, $nextExpectedAt, $confidenceLow, $missedCount, $billingDay);
    }

    // A posting on its month's last day is clamped evidence: the real billing
    // day is at least that, never less. Only unclamped postings can name it,
    // and the most frequent of those does — read off whichever row happened to
    // be oldest in the window, a month-end bill moved by up to three days.
    /**
     * @param  list<CarbonImmutable>  $sortedTimestamps
     */
    private static function billingDay(array $sortedTimestamps): int
    {
        $exact = [];
        $clamped = 1;
        foreach ($sortedTimestamps as $timestamp) {
            if ($timestamp->day < $timestamp->daysInMonth) {
                $exact[] = $timestamp->day;

                continue;
            }
            $clamped = max($clamped, $timestamp->day);
        }

        if ($exact === []) {
            return $clamped;
        }

        $counts = [];
        foreach ($exact as $day) {
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        // Ties keep the earliest posting's day: a cluster whose days never
        // agree has no billing day to find, and moving it would be noise.
        $billingDay = $exact[0];
        foreach ($counts as $day => $count) {
            if ($count > $counts[$billingDay]) {
                $billingDay = $day;
            }
        }

        return $billingDay;
    }

    // A day count drifts a monthly bill off its own day of the month every
    // period, so the projection steps the calendar unit the band names. A band
    // with no step of its own answers null and the caller falls back to the
    // day median.
    private static function stepOnePeriod(CarbonImmutable $last, int $billingDay, SeriesCadence $cadence): ?CarbonImmutable
    {
        return match ($cadence) {
            SeriesCadence::Weekly => $last->addDays(WeekStart::DAYS_IN_WEEK),
            SeriesCadence::Monthly => SeriesCadence::onBillingDay($last->addMonthNoOverflow(), $billingDay),
            SeriesCadence::Quarterly => SeriesCadence::onBillingDay($last->addMonthsNoOverflow(self::MONTHS_PER_QUARTER), $billingDay),
            SeriesCadence::Yearly => SeriesCadence::onBillingDay($last->addYearNoOverflow(), $billingDay),
            SeriesCadence::Irregular => null,
        };
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
