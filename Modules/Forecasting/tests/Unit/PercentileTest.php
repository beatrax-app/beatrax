<?php

declare(strict_types=1);

use Modules\Forecasting\Internal\Pipeline\Percentile;

/*
 * Unit coverage for the percentile-tier R-7 helper.
 *
 * Locks the linear-interpolation method against a hand-computed
 * snapshot for `[10..100]` (the canonical numpy/scipy R-7 example):
 *   - p10 of the 10-value list is computed at index (10-1) × 0.10 = 0.9,
 *     interpolating between sortedValues[0]=10 and sortedValues[1]=20:
 *     10 + 0.9 × 10 = 19.
 *   - p50 of the same list is at index 4.5, interpolating between
 *     sortedValues[4]=50 and sortedValues[5]=60: 50 + 0.5 × 10 = 55.
 *   - p90 is at index 8.1, interpolating between sortedValues[8]=90
 *     and sortedValues[9]=100: 90 + 0.1 × 10 = 91.
 * The plan's hand-computed values are locked here as the contract.
 */

beforeEach(function (): void {
    $this->percentile = new Percentile;
});

it('returns the single value for n=1 without interpolation', function (): void {
    expect($this->percentile->p10([42]))->toBe(42);
    expect($this->percentile->p50([42]))->toBe(42);
    expect($this->percentile->p90([42]))->toBe(42);
});

it('interpolates between two values for n=2', function (): void {
    // sortedValues = [10, 100], index = (2-1) × p/100.
    // p10 → 10 + 0.10 × 90 = 19.
    // p50 → 10 + 0.50 × 90 = 55.
    // p90 → 10 + 0.90 × 90 = 91.
    expect($this->percentile->p10([10, 100]))->toBe(19);
    expect($this->percentile->p50([10, 100]))->toBe(55);
    expect($this->percentile->p90([10, 100]))->toBe(91);
});

it('locks the R-7 canonical snapshot for [10..100]', function (): void {
    $values = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];
    expect($this->percentile->p10($values))->toBe(19);
    expect($this->percentile->p50($values))->toBe(55);
    expect($this->percentile->p90($values))->toBe(91);
});

it('handles unsorted input by sorting internally', function (): void {
    $shuffled = [100, 50, 30, 10, 90, 20, 80, 60, 70, 40];
    expect($this->percentile->p10($shuffled))->toBe(19);
    expect($this->percentile->p50($shuffled))->toBe(55);
    expect($this->percentile->p90($shuffled))->toBe(91);
});

it('keeps n=6 in a typical real-world distribution within the min/max range', function (): void {
    // Variable-utility-like distribution: six observed amounts.
    $values = [6000, 8500, 13000, 18000, 19500, 22000];
    $p10 = $this->percentile->p10($values);
    $p50 = $this->percentile->p50($values);
    $p90 = $this->percentile->p90($values);

    expect($p10)->toBeGreaterThanOrEqual(6000);
    expect($p10)->toBeLessThanOrEqual(8500);
    expect($p50)->toBeGreaterThanOrEqual(13000);
    expect($p50)->toBeLessThanOrEqual(18000);
    expect($p90)->toBeGreaterThanOrEqual(19500);
    expect($p90)->toBeLessThanOrEqual(22000);

    // Hand-computed R-7 values for this distribution:
    // p10 index = 5 × 0.1 = 0.5 → sortedValues[0]=6000 + 0.5 × (8500-6000) = 7250
    // p50 index = 5 × 0.5 = 2.5 → sortedValues[2]=13000 + 0.5 × (18000-13000) = 15500
    // p90 index = 5 × 0.9 = 4.5 → sortedValues[4]=19500 + 0.5 × (22000-19500) = 20750
    expect($p10)->toBe(7250);
    expect($p50)->toBe(15500);
    expect($p90)->toBe(20750);
});

it('preserves negative sign for expense distributions', function (): void {
    // Real expense fixture: all-negative observed amounts.
    $values = [-22000, -19500, -18000, -13000, -10500, -8500, -6000];
    $p10 = $this->percentile->p10($values);
    $p50 = $this->percentile->p50($values);
    $p90 = $this->percentile->p90($values);

    // R-7 of an all-negative list returns negative values — never
    // crosses zero. The "low end" (p10) is more negative than the
    // "high end" (p90) when read as signed integers.
    expect($p10)->toBeLessThan(0);
    expect($p50)->toBeLessThan(0);
    expect($p90)->toBeLessThan(0);
    expect($p10)->toBeLessThanOrEqual($p50);
    expect($p50)->toBeLessThanOrEqual($p90);
});

it('throws InvalidArgumentException on an empty list', function (): void {
    expect(fn () => $this->percentile->p50([]))
        ->toThrow(InvalidArgumentException::class, 'Cannot compute percentile of empty list.');
    expect(fn () => $this->percentile->p10([]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->percentile->p90([]))
        ->toThrow(InvalidArgumentException::class);
});

it('does not mutate the caller array', function (): void {
    $original = [50, 30, 70, 10, 90];
    $copy = $original;
    $this->percentile->p50($original);
    expect($original)->toBe($copy);
});
