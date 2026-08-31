<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Support;

/**
 * @link ../../../../.docs/features/anomaly/detector-maths.md
 */
final class RobustStatistics
{
    public const int WINDOW_MONTHS = 12;

    public const int THIN_HISTORY_CUTOFF = 5;

    public const float MAD_CONSISTENCY = 1.4826;

    public const float CATEGORY_PERCENTILE = 95.0;

    public const float K_BASE = 3.0;

    public const float K_SLOPE = 0.04;

    public const float K_PIVOT = 50.0;

    public const float K_MIN = 1.5;

    public const float K_MAX = 4.0;

    public const int MAD_FLOOR_MINOR = 50;

    /**
     * @param  list<int|float>  $sample
     */
    public static function median(array $sample): float
    {
        $count = count($sample);
        if ($count === 0) {
            return 0.0;
        }

        $sorted = $sample;
        sort($sorted);

        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (float) $sorted[$mid];
        }

        return ((float) $sorted[$mid - 1] + (float) $sorted[$mid]) / 2.0;
    }

    /**
     * @param  list<int|float>  $sample
     */
    public static function mad(array $sample): float
    {
        if ($sample === []) {
            return 0.0;
        }

        $median = self::median($sample);
        $deviations = [];
        foreach ($sample as $value) {
            $deviations[] = abs((float) $value - $median);
        }

        return self::median($deviations);
    }

    /**
     * @param  list<int|float>  $sample
     */
    public static function robustZ(int $x, array $sample, int $floorMinor): float
    {
        if ($sample === []) {
            return 0.0;
        }

        $absSample = array_map(static fn (int|float $v): float => abs((float) $v), $sample);
        $absX = abs((float) $x);

        $median = self::median($absSample);
        $robustSigma = self::MAD_CONSISTENCY * self::mad($absSample);

        $floor = max((float) self::MAD_FLOOR_MINOR, (float) $floorMinor);
        $denominator = max($robustSigma, $floor);

        return ($absX - $median) / $denominator;
    }

    // Unlike robustZ()/exceedsPercentile(), this does NOT reduce the sample to
    // absolute magnitudes; `p` is a percentage in [0, 100].
    /**
     * @param  list<int|float>  $sample
     */
    public static function percentile(array $sample, float $p): float
    {
        $count = count($sample);
        if ($count <= 1) {
            return $count === 1 ? (float) $sample[0] : 0.0;
        }

        $sorted = array_map(static fn (int|float $v): float => (float) $v, $sample);
        sort($sorted);

        $rank = ($p / 100.0) * ($count - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return $sorted[$low];
        }

        $fraction = $rank - $low;

        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * $fraction;
    }

    // Tie-inclusive (`>=`): at small n the interpolated p95 collapses onto the
    // sample maximum, and a strict `>` would silently pass a repeat of the
    // largest-ever charge.
    /**
     * @param  list<int|float>  $sample
     */
    public static function exceedsPercentile(int $x, array $sample, float $p): bool
    {
        if ($sample === []) {
            return false;
        }

        $absSample = array_map(static fn (int|float $v): float => abs((float) $v), $sample);
        $threshold = self::percentile($absSample, $p);

        return abs((float) $x) >= $threshold;
    }

    // Takes the value object, not a bare percent: the clamp below is a
    // rounding guard, and it was doing duty as a range check it cannot
    // perform — 500 arrived as k = -15 and left as MAXIMUM sensitivity.
    public static function kForSensitivity(AnomalySensitivity $sensitivity): float
    {
        $k = self::K_BASE - self::K_SLOPE * ((float) $sensitivity->percent - self::K_PIVOT);

        return min(self::K_MAX, max(self::K_MIN, $k));
    }
}
