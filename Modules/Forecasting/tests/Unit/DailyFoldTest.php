<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Forecasting\Internal\Pipeline\DailyFold;
use Modules\Forecasting\Internal\Pipeline\ForecastContribution;

/*
 * Unit coverage for DailyFold — the per-day quadrature combiner.
 *
 * Asserts the load-bearing math: spreads of independent contributions
 * combine via √(Σ spread²), NOT linearly. Specifically, two ±€10
 * contributions on the same day yield a combined spread of
 * round(√2 × 10) = 14 minor units, not 20.
 *
 * Also covers: empty contributions (continuous running balance), single
 * contribution (band starts on that day), cross-day cumulation,
 * cross-currency conversion via fxRateUsed, and the InvalidArgumentException
 * raised when a cross-currency contribution lacks a stored fx rate.
 */

function dfFold(): DailyFold
{
    return new DailyFold;
}

it('emits horizonDays+1 days starting at asOf with no contributions and a flat band', function (): void {
    $asOf = CarbonImmutable::parse('2026-05-19');
    $points = dfFold()->fold(
        openingBalanceMinor: 150000,
        contributions: [],
        asOf: $asOf,
        horizonDays: 3,
        defaultCurrency: 'EUR',
    );

    expect($points)->toHaveCount(4); // asOf + 3 days inclusive
    expect(array_keys($points))->toBe(['2026-05-19', '2026-05-20', '2026-05-21', '2026-05-22']);
    foreach ($points as $point) {
        expect($point['low_minor'])->toBe(150000);
        expect($point['point_minor'])->toBe(150000);
        expect($point['high_minor'])->toBe(150000);
        expect($point['currency'])->toBe('EUR');
    }
});

it('combines spreads of two independent contributions via quadrature (sqrt(2)*10 = 14, NOT 20)', function (): void {
    // Two contributions on the same day, each with a half-width of 10
    // minor units. Quadrature: spread = √(10² + 10²) = √200 ≈ 14.14.
    // (int) round(14.14) = 14.
    $asOf = CarbonImmutable::parse('2026-05-19');
    $on = CarbonImmutable::parse('2026-05-20');

    $contributions = [
        new ForecastContribution(
            date: $on,
            pointMinor: -100,
            lowMinor: -110, // half-width 10
            highMinor: -90,
            currency: 'EUR',
            fxRateUsed: null,
            seriesId: 1,
            accountId: 1,
        ),
        new ForecastContribution(
            date: $on,
            pointMinor: -100,
            lowMinor: -110,
            highMinor: -90,
            currency: 'EUR',
            fxRateUsed: null,
            seriesId: 2,
            accountId: 1,
        ),
    ];

    $points = dfFold()->fold(
        openingBalanceMinor: 1000,
        contributions: $contributions,
        asOf: $asOf,
        horizonDays: 2,
        defaultCurrency: 'EUR',
    );

    // On the contribution day: running = 1000 + (-100) + (-100) = 800,
    // spread = round(√(10² + 10²)) = round(14.14) = 14.
    expect($points['2026-05-20']['point_minor'])->toBe(800);
    expect($points['2026-05-20']['high_minor'] - $points['2026-05-20']['low_minor'])->toBe(28); // 2 × 14
    expect($points['2026-05-20']['high_minor'])->toBe(814);
    expect($points['2026-05-20']['low_minor'])->toBe(786);

    // Sanity: the linear sum (which the implementation must NOT use) would
    // produce a spread of 20, not 14.
    expect($points['2026-05-20']['high_minor'] - $points['2026-05-20']['low_minor'])->not->toBe(40);
});

it('carries the running balance forward on days with no contributions', function (): void {
    $asOf = CarbonImmutable::parse('2026-05-19');
    $on = CarbonImmutable::parse('2026-05-20');

    $contributions = [
        new ForecastContribution(
            date: $on,
            pointMinor: -500,
            lowMinor: -550,
            highMinor: -450,
            currency: 'EUR',
            fxRateUsed: null,
            seriesId: 1,
            accountId: 1,
        ),
    ];

    $points = dfFold()->fold(
        openingBalanceMinor: 10000,
        contributions: $contributions,
        asOf: $asOf,
        horizonDays: 3,
        defaultCurrency: 'EUR',
    );

    expect($points['2026-05-19']['point_minor'])->toBe(10000);
    expect($points['2026-05-19']['low_minor'])->toBe(10000);
    expect($points['2026-05-20']['point_minor'])->toBe(9500);
    expect($points['2026-05-20']['high_minor'] - $points['2026-05-20']['low_minor'])->toBe(100); // 2 × half-width 50
    // Day 21 and 22: no new contribution. Balance + spread carry forward.
    expect($points['2026-05-21']['point_minor'])->toBe(9500);
    expect($points['2026-05-21']['low_minor'])->toBe($points['2026-05-20']['low_minor']);
    expect($points['2026-05-22']['point_minor'])->toBe(9500);
});

it('converts cross-currency contributions to the default currency via fxRateUsed', function (): void {
    $asOf = CarbonImmutable::parse('2026-05-19');
    $on = CarbonImmutable::parse('2026-05-20');

    // USD 5.99 = 599 minor units; fx_rate USD→EUR = 0.9 → 539 EUR minor.
    $contributions = [
        new ForecastContribution(
            date: $on,
            pointMinor: -599,
            lowMinor: -629, // half-width 15 in USD
            highMinor: -569,
            currency: 'USD',
            fxRateUsed: 0.9,
            seriesId: 1,
            accountId: 1,
        ),
    ];

    $points = dfFold()->fold(
        openingBalanceMinor: 10000,
        contributions: $contributions,
        asOf: $asOf,
        horizonDays: 2,
        defaultCurrency: 'EUR',
    );

    // point: 10000 + round(-599 * 0.9) = 10000 + (-539) = 9461.
    expect($points['2026-05-20']['point_minor'])->toBe(9461);
    expect($points['2026-05-20']['currency'])->toBe('EUR');
});

it('throws InvalidArgumentException when a cross-currency contribution lacks fxRateUsed', function (): void {
    $asOf = CarbonImmutable::parse('2026-05-19');
    $on = CarbonImmutable::parse('2026-05-20');

    $contributions = [
        new ForecastContribution(
            date: $on,
            pointMinor: -599,
            lowMinor: -629,
            highMinor: -569,
            currency: 'USD',
            fxRateUsed: null,
            seriesId: 1,
            accountId: 1,
        ),
    ];

    $fold = static fn (): array => dfFold()->fold(
        openingBalanceMinor: 0,
        contributions: $contributions,
        asOf: $asOf,
        horizonDays: 2,
        defaultCurrency: 'EUR',
    );

    expect($fold)->toThrow(InvalidArgumentException::class);
});

it('cumulates spread across multiple days via quadrature, not linearly', function (): void {
    // One contribution per day with half-width 10. After day 1: spread =
    // round(√100) = 10. After day 2: spread = round(√200) = 14. After
    // day 3: spread = round(√300) = 17.
    $asOf = CarbonImmutable::parse('2026-05-19');
    $contributions = [];
    foreach (['2026-05-20', '2026-05-21', '2026-05-22'] as $date) {
        $contributions[] = new ForecastContribution(
            date: CarbonImmutable::parse($date),
            pointMinor: -100,
            lowMinor: -110,
            highMinor: -90,
            currency: 'EUR',
            fxRateUsed: null,
            seriesId: 1,
            accountId: 1,
        );
    }

    $points = dfFold()->fold(
        openingBalanceMinor: 0,
        contributions: $contributions,
        asOf: $asOf,
        horizonDays: 3,
        defaultCurrency: 'EUR',
    );

    expect($points['2026-05-20']['high_minor'] - $points['2026-05-20']['low_minor'])->toBe(20); // 2 × 10
    expect($points['2026-05-21']['high_minor'] - $points['2026-05-21']['low_minor'])->toBe(28); // 2 × round(√200) = 14
    expect($points['2026-05-22']['high_minor'] - $points['2026-05-22']['low_minor'])->toBe(34); // 2 × round(√300) = 17
});
