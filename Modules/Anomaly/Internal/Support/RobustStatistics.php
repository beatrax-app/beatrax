<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Support;

/**
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
final class RobustStatistics
{
    // 12 months is the upper end of the 6-12 month target: it captures a
    // full seasonal cycle (annual subscription / salary shifts) without
    // anchoring on years-old amounts.
    public const WINDOW_MONTHS = 12;

    // Per-counterparty sample count below which the detector abandons the
    // noisy per-merchant baseline and falls back to the per-category
    // percentile.
    public const THIN_HISTORY_CUTOFF = 5;

    // MAD -> standard-deviation consistency constant for normally
    // distributed data: robust sigma ~= 1.4826 x MAD.
    public const MAD_CONSISTENCY = 1.4826;

    // A charge above the category p95 (same direction, same window) trips
    // the large reason on the fallback path.
    public const CATEGORY_PERCENTILE = 95.0;

    // Sensitivity -> k curve: k = K_BASE - K_SLOPE * (sensitivity -
    // K_PIVOT), clamped to [K_MIN, K_MAX]. At the 50% default this yields
    // k = 3.0; higher sensitivity => lower k => more alerts.
    public const K_BASE = 3.0;

    public const K_SLOPE = 0.04;

    public const K_PIVOT = 50.0;

    public const K_MIN = 1.5;

    public const K_MAX = 4.0;

    // Applied to the robust-sigma denominator so a near-constant merchant
    // (MAD ~= 0) does not divide by zero. Callers may pass a larger
    // context-derived floor; this is the hard minimum.
    public const MAD_FLOOR_MINOR = 50;

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

    // `x` and the sample are compared as absolute magnitudes so a signed
    // convention never flips the result. `floorMinor` is the caller's
    // context floor; MAD_FLOOR_MINOR still applies underneath so the
    // denominator is never below it.
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

    // Absolute magnitudes are the caller's responsibility; `p` is a
    // percentage in [0, 100].
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

    // Deliberately TIE-INCLUSIVE (`>=`): for small samples, linear
    // interpolation collapses p95 toward the sample maximum, so a strict
    // `>` would let a charge that exactly repeats the largest-ever charge
    // slip past as a false negative. Sample is reduced to absolute magnitudes.
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

    // Maps the user's single sensitivity knob onto the robust-z trip
    // multiplier `k`. Higher sensitivity => lower k => more alerts.
    public static function kForSensitivity(int $sensitivityPercent): float
    {
        $k = self::K_BASE - self::K_SLOPE * ((float) $sensitivityPercent - self::K_PIVOT);

        return min(self::K_MAX, max(self::K_MIN, $k));
    }
}
