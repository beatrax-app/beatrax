<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Support;

// The vertical span a balance chart is drawn in. Padding one unit either side
// unconditionally made ApexCharts label its own minimum, and every cash
// account's floor is the zero-crossing BufferFloor — so the axis read "-€1".

// The pad was load-bearing for a flat series only: min === max gives an axis of
// zero height, so that is the one case that still gets it.
final class ChartAxisBounds
{
    /**
     * @return array{0: float, 1: float}
     */
    public static function spanning(float $min, float $max): array
    {
        if ($min === $max) {
            return [$min - 1, $max + 1];
        }

        return [$min, $max];
    }
}
