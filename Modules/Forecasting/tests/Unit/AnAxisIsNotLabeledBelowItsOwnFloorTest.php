<?php

declare(strict_types=1);

use Modules\Forecasting\Internal\Support\ChartAxisBounds;

// The bounds were padded a unit either side unconditionally, and ApexCharts
// labels its own minimum. Every cash account's floor is the zero-crossing
// BufferFloor, so five of eight accounts on a paired iPhone drew a "-€1"
// gridline — and the yen ones a "-¥1" — under a balance that never went near it.

it('leaves a real range exactly where the data puts it', function (): void {
    expect(ChartAxisBounds::spanning(0.0, 5249.58))->toBe([0.0, 5249.58])
        ->and(ChartAxisBounds::spanning(-1345.0, -12.5))->toBe([-1345.0, -12.5]);
});

// The one case the pad was really for: a series that never moves would
// otherwise be drawn in an axis of zero height.
it('spreads a flat series so it still has an axis to sit in', function (): void {
    expect(ChartAxisBounds::spanning(1126.0, 1126.0))->toBe([1125.0, 1127.0]);
});

it('never puts the floor below zero for a balance that starts at zero', function (): void {
    [$min] = ChartAxisBounds::spanning(0.0, 400.0);

    expect($min)->toBe(0.0);
});
