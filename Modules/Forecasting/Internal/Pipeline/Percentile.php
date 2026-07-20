<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use InvalidArgumentException;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
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
