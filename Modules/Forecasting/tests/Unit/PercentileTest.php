<?php

declare(strict_types=1);

use Modules\Forecasting\Internal\Pipeline\Percentile;

/** @link ../../../../.docs/features/forecasting/projection-math.md#the-interpolation-rule-exactly */
beforeEach(function (): void {
    $this->percentile = new Percentile;
});

it('returns the single value for n=1 without interpolation', function (): void {
    expect($this->percentile->p10([42]))->toBe(42);
    expect($this->percentile->p50([42]))->toBe(42);
    expect($this->percentile->p90([42]))->toBe(42);
});

it('interpolates between two values for n=2', function (): void {
    // R-7 index is (n-1) × p/100, so on [10, 100] each percentile is
    // 10 + (p/100) × 90.
    expect($this->percentile->p10([10, 100]))->toBe(19);
    expect($this->percentile->p50([10, 100]))->toBe(55);
    expect($this->percentile->p90([10, 100]))->toBe(91);
});

it('returns 19, 55 and 91 for the evenly spaced ten-value list [10..100]', function (): void {
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

    // Every R-7 index here lands on a half step (0.5, 2.5, 4.5), so each
    // figure is the midpoint of its bracketing pair.
    expect($p10)->toBe(7250);
    expect($p50)->toBe(15500);
    expect($p90)->toBe(20750);
});

it('preserves negative sign for expense distributions', function (): void {
    $values = [-22000, -19500, -18000, -13000, -10500, -8500, -6000];
    $p10 = $this->percentile->p10($values);
    $p50 = $this->percentile->p50($values);
    $p90 = $this->percentile->p90($values);

    // Read as signed integers, p10 is the more negative end: interpolation
    // never crosses zero on an all-negative list.
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
