<?php

declare(strict_types=1);

use Modules\Anomaly\Internal\Support\RobustStatistics;

/*
 * Pure-math coverage for the RobustStatistics helper: median, MAD, the
 * MAD-floored robust z-score, the category percentile, and the
 * sensitivity -> k mapping. No database — these are deterministic
 * arithmetic assertions guarding the named-constant tunables.
 */

it('computes the median of an odd- and even-length sample', function (): void {
    expect(RobustStatistics::median([5, 1, 3]))->toBe(3.0);
    expect(RobustStatistics::median([1, 2, 3, 4]))->toBe(2.5);
});

it('computes the MAD (median absolute deviation)', function (): void {
    // sample [1,1,1] → median 1, deviations [0,0,0] → MAD 0
    expect(RobustStatistics::mad([1, 1, 1]))->toBe(0.0);
    // sample [2,4,6] → median 4, deviations [2,0,2] → MAD 2
    expect(RobustStatistics::mad([2, 4, 6]))->toBe(2.0);
});

it('maps sensitivity to k via the documented clamp curve (50% -> 3.0)', function (): void {
    expect(RobustStatistics::kForSensitivity(50))->toBe(3.0);
    // 75% → 3.0 - 0.04*(25) = 2.0
    expect(RobustStatistics::kForSensitivity(75))->toBe(2.0);
    // 0% → 3.0 - 0.04*(-50) = 5.0, clamped to 4.0
    expect(RobustStatistics::kForSensitivity(0))->toBe(4.0);
    // 100% → 3.0 - 0.04*(50) = 1.0, clamped to 1.5
    expect(RobustStatistics::kForSensitivity(100))->toBe(1.5);
});

it('floors the MAD denominator so near-constant merchants do not divide by zero', function (): void {
    // A perfectly constant merchant history: MAD == 0. The floor keeps the
    // z finite; a charge well above the constant must still trip a large z.
    $sample = [999, 999, 999, 999, 999];
    $z = RobustStatistics::robustZ(2349, $sample, 100);
    expect($z)->toBeGreaterThan(4.0);
});

it('yields a z near zero for a charge at the median', function (): void {
    $sample = [999, 999, 1049, 999, 1049];
    $z = RobustStatistics::robustZ(999, $sample, 100);
    expect(abs($z))->toBeLessThan(1.0);
});

it('computes a high percentile of a category sample', function (): void {
    // p95 of a 5-sample groceries band sits at/above the largest sample.
    $sample = [2750, 2890, 3100, 3400, 2600];
    $p95 = RobustStatistics::percentile($sample, 95.0);
    expect($p95)->toBeGreaterThanOrEqual(3100.0);
    expect($p95)->toBeLessThanOrEqual(3400.0);
});

it('exposes the tunable named constants', function (): void {
    expect(RobustStatistics::WINDOW_MONTHS)->toBe(12);
    expect(RobustStatistics::THIN_HISTORY_CUTOFF)->toBe(5);
    expect(RobustStatistics::MAD_CONSISTENCY)->toBe(1.4826);
    expect(RobustStatistics::CATEGORY_PERCENTILE)->toBe(95.0);
});
