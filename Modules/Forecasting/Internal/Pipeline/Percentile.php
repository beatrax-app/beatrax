<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use InvalidArgumentException;

/**
 * Pure-math helper for the percentile tier of `RangeProjector`.
 *
 * Implements the R-7 method ("Linear interpolation between closest
 * ranks"). Given a list of N observed amounts, R-7 returns the p-th
 * percentile by computing a continuous index `(N-1) × p/100`, taking
 * the floor as the lower neighbour, and linearly interpolating
 * between that neighbour and the one above by the fractional part.
 *
 * R-7 is the default for numpy.percentile, scipy.stats.scoreatpercentile,
 * Excel's PERCENTILE.INC, and the R `quantile(type=7)` shape used in
 * most empirical-distribution research. The nearest-rank alternative
 * collapses to a single observation when N is small (n=6 maps each
 * decile to one exact sample point, hiding the spread between them);
 * R-7's linear interpolation preserves the band continuously even at
 * n=6, which is exactly the percentile-tier trigger threshold.
 *
 * The helper has NO DI — it does not read the DB, does not depend on
 * the clock, and is constructor-parameterless. Tests instantiate it
 * directly via `new Percentile`.
 *
 * Sign convention: the input list is signed (expenses negative,
 * incomes positive). The returned percentile preserves the sign by
 * construction — R-7 is a pure linear combination of the input
 * values, so an all-negative input cannot produce a positive result
 * and vice versa.
 *
 * Reference: Wikipedia, "Percentile § Linear interpolation between
 * closest ranks" (the R-7 quantile estimator).
 */
final readonly class Percentile
{
    /**
     * @param  list<int>  $values
     */
    public function p10(array $values): int
    {
        return $this->percentile($values, 10);
    }

    /**
     * @param  list<int>  $values
     */
    public function p50(array $values): int
    {
        return $this->percentile($values, 50);
    }

    /**
     * @param  list<int>  $values
     */
    public function p90(array $values): int
    {
        return $this->percentile($values, 90);
    }

    /**
     * @param  list<int>  $values
     */
    private function percentile(array $values, int $p): int
    {
        $n = count($values);
        if ($n === 0) {
            throw new InvalidArgumentException('Cannot compute percentile of empty list.');
        }
        if ($n === 1) {
            return $values[0];
        }

        // Don't mutate the caller's array — R-7 requires sorted input.
        $sorted = $values;
        sort($sorted, SORT_NUMERIC);

        $index = ($n - 1) * ($p / 100);
        $k = (int) floor($index);
        $d = $index - $k;

        if ($k + 1 < $n) {
            return (int) round($sorted[$k] + $d * ($sorted[$k + 1] - $sorted[$k]));
        }

        return $sorted[$k];
    }
}
