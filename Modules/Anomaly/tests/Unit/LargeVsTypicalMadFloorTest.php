<?php

declare(strict_types=1);

use Modules\Anomaly\Internal\Detectors\LargeVsTypicalDetector;
use Modules\Anomaly\Internal\Support\RobustStatistics;

// madFloorFor computes the 1%-of-median floor in float and casts to int
// only at the end; both regimes are pinned so a refactor cannot flip the
// €50 break-even by truncating early.
/**
 * @param  list<int>  $sample
 */
function callMadFloorFor(array $sample): int
{
    $method = new ReflectionMethod(LargeVsTypicalDetector::class, 'madFloorFor');

    /** @var int $result */
    $result = $method->invoke(null, $sample);

    return $result;
}

it('collapses to the hard MAD_FLOOR_MINOR for sub-€50-median merchants', function (int $medianMinor): void {
    $sample = array_fill(0, 5, -$medianMinor);

    expect(callMadFloorFor($sample))->toBe(RobustStatistics::MAD_FLOOR_MINOR);
})->with([
    'tiny €1 merchant' => [100],
    'just below the €50 break-even' => [4999],
    'exactly €50 (1% == 50, no scaling yet)' => [5000],
]);

it('scales with 1% of median above the €50 break-even, computed in float', function (): void {
    // median €120.00 -> 1% == 120 minor units, above the hard floor of 50.
    $sample = array_fill(0, 5, -12000);

    expect(callMadFloorFor($sample))->toBe(120);
});

it('does not discard the fractional 1% before comparing to the hard floor', function (): void {
    // median €99.99 -> 1% == 99.99; the float max keeps 99.99 above 50,
    // then the final (int) cast yields 99 (truncating only at the end).
    $sample = array_fill(0, 5, -9999);

    expect(callMadFloorFor($sample))->toBe(99);
});
