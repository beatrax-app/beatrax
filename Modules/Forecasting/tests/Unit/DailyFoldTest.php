<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Forecasting\Internal\Pipeline\DailyFold;
use Modules\Forecasting\Internal\Pipeline\ForecastContribution;

/** @link ../../../../.docs/features/forecasting/projection-math.md#per-day-aggregation-and-quadrature */
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
    // Two same-day half-widths of 10 combine as √(10² + 10²) ≈ 14.14, which
    // rounds to 14 — a linear sum would say 20.
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

    expect($points['2026-05-20']['point_minor'])->toBe(800);
    expect($points['2026-05-20']['high_minor'] - $points['2026-05-20']['low_minor'])->toBe(28); // 2 × 14
    expect($points['2026-05-20']['high_minor'])->toBe(814);
    expect($points['2026-05-20']['low_minor'])->toBe(786);

    // A linear sum would half-width at 20, so a 40-wide band.
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

it('does NOT cumulate spread across days for one-contribution-per-day occurrences', function (): void {
    // The band is the uncertainty of the latest active period's amount, not
    // uncertainty accumulated over time, so each day's spread resets to 10.
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
    expect($points['2026-05-21']['high_minor'] - $points['2026-05-21']['low_minor'])->toBe(20);
    expect($points['2026-05-22']['high_minor'] - $points['2026-05-22']['low_minor'])->toBe(20);
});

it('carries the latest-period spread forward on days without new contributions', function (): void {
    // The spread persists until a new contribution overrides it, which is what
    // keeps the rendered band continuous rather than gapped.
    $asOf = CarbonImmutable::parse('2026-05-19');
    $on = CarbonImmutable::parse('2026-05-20');

    $contributions = [
        new ForecastContribution(
            date: $on,
            pointMinor: -100,
            lowMinor: -110,
            highMinor: -90,
            currency: 'EUR',
            fxRateUsed: null,
            seriesId: 1,
            accountId: 1,
        ),
    ];

    $points = dfFold()->fold(
        openingBalanceMinor: 0,
        contributions: $contributions,
        asOf: $asOf,
        horizonDays: 3,
        defaultCurrency: 'EUR',
    );

    expect($points['2026-05-19']['high_minor'] - $points['2026-05-19']['low_minor'])->toBe(0);
    expect($points['2026-05-20']['high_minor'] - $points['2026-05-20']['low_minor'])->toBe(20);
    expect($points['2026-05-21']['high_minor'] - $points['2026-05-21']['low_minor'])->toBe(20);
    expect($points['2026-05-22']['high_minor'] - $points['2026-05-22']['low_minor'])->toBe(20);
});
