<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Support;

/**
 * Robust dispersion statistics for the large-vs-typical detector (D-06 /
 * D-07 / D-08). Pure, stateless arithmetic — every method is static and
 * the tunable design constants are named class constants so the
 * statistical method can be re-tuned in one line after backfill triage
 * (RESEARCH Assumptions A1–A3 anticipate this).
 *
 * The method is a median + k×MAD robust z-score, chosen over mean+σ
 * because per-merchant samples are small and outlier-laden — a single
 * legitimate large prior would inflate σ and mask the next anomaly
 * (RESEARCH Pitfall 2). MAD resists that. When the per-counterparty
 * sample is thin (< THIN_HISTORY_CUTOFF) the detector falls back to a
 * per-category high percentile, which is stable at the larger category
 * sample size.
 *
 * All inputs are settled minor units (integers). Callers normalise to
 * absolute magnitudes before passing a sample so a sign convention
 * (expenses negative, income positive) never corrupts the dispersion.
 */
final class RobustStatistics
{
    /**
     * Rolling baseline window (D-07). 12 months is the upper end of the
     * 6–12 month target: it captures a full seasonal cycle (annual
     * subscription / salary shifts) without anchoring on years-old amounts.
     */
    public const WINDOW_MONTHS = 12;

    /**
     * Per-counterparty sample count below which the detector abandons the
     * noisy per-merchant baseline and falls back to the per-category
     * percentile (D-06). "< 5 samples" is thin.
     */
    public const THIN_HISTORY_CUTOFF = 5;

    /**
     * MAD → standard-deviation consistency constant for normally
     * distributed data: robust σ ≈ 1.4826 × MAD.
     */
    public const MAD_CONSISTENCY = 1.4826;

    /**
     * Category-fallback percentile. A charge above the category p95 (same
     * direction, same window) trips the large reason on the fallback path.
     */
    public const CATEGORY_PERCENTILE = 95.0;

    /**
     * Sensitivity → k curve coefficients: k = K_BASE − K_SLOPE ×
     * (sensitivity − K_PIVOT), clamped to [K_MIN, K_MAX]. At the 50%
     * default this yields k = 3.0; higher sensitivity ⇒ lower k ⇒ more
     * alerts.
     */
    public const K_BASE = 3.0;

    public const K_SLOPE = 0.04;

    public const K_PIVOT = 50.0;

    public const K_MIN = 1.5;

    public const K_MAX = 4.0;

    /**
     * Absolute floor (minor units) applied to the robust-σ denominator so
     * a near-constant merchant (MAD ≈ 0) does not divide by zero. Callers
     * may pass a larger context-derived floor (e.g. a fraction of the
     * median); this is the hard minimum.
     */
    public const MAD_FLOOR_MINOR = 50;

    /**
     * Median of a numeric sample. Returns 0.0 for an empty sample.
     *
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
     * Median absolute deviation: median(|s − median(S)|).
     *
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
     * Robust z-score of `x` against `sample`: (x − median) / max(1.4826 ×
     * MAD, floor). `x` and the sample are compared as absolute magnitudes
     * so a signed convention never flips the result. `floorMinor` is the
     * caller's context floor; the MAD_FLOOR_MINOR hard minimum still
     * applies underneath so the denominator is never below it.
     *
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

    /**
     * Linear-interpolated percentile of a numeric sample (absolute
     * magnitudes are the caller's responsibility). `p` is a percentage in
     * [0, 100]. Returns 0.0 for an empty sample.
     *
     * @param  list<int|float>  $sample
     */
    public static function percentile(array $sample, float $p): float
    {
        $count = count($sample);
        if ($count === 0) {
            return 0.0;
        }
        if ($count === 1) {
            return (float) $sample[0];
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

    /**
     * Maps the user's single `anomaly_sensitivity_percent` knob onto the
     * robust-z trip multiplier `k`. Higher sensitivity ⇒ lower k ⇒ more
     * alerts. Clamped to [K_MIN, K_MAX]. At 50% ⇒ 3.0.
     */
    public static function kForSensitivity(int $sensitivityPercent): float
    {
        $k = self::K_BASE - self::K_SLOPE * ((float) $sensitivityPercent - self::K_PIVOT);

        return min(self::K_MAX, max(self::K_MIN, $k));
    }
}
