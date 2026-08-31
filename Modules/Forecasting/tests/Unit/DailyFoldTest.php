<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Forecasting\Internal\Pipeline\DailyFold;
use Modules\Forecasting\Internal\Pipeline\ForecastContribution;
use Modules\FX\Public\Services\CrossCurrencyTotal;

/** @link ../../../../.docs/features/forecasting/projection-math.md#per-day-aggregation-and-quadrature */
function dfFold(): DailyFold
{
    return new DailyFold(app(CrossCurrencyTotal::class));
}

it('emits horizonDays+1 days starting at asOf with no contributions and a flat band', function (): void {
    $asOf = CarbonImmutable::parse('2026-05-19');
    $points = dfFold()->fold(
        openingBalanceMinor: 150000,
        contributions: [],
        asOf: $asOf,
        horizonDays: 3,
        defaultCurrency: 'EUR',
        rates: [],
    )->points;

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
            seriesId: 1,
            accountId: 1,
        ),
        new ForecastContribution(
            date: $on,
            pointMinor: -100,
            lowMinor: -110,
            highMinor: -90,
            currency: 'EUR',
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
        rates: [],
    )->points;

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
        rates: [],
    )->points;

    expect($points['2026-05-19']['point_minor'])->toBe(10000);
    expect($points['2026-05-19']['low_minor'])->toBe(10000);
    expect($points['2026-05-20']['point_minor'])->toBe(9500);
    expect($points['2026-05-20']['high_minor'] - $points['2026-05-20']['low_minor'])->toBe(100); // 2 × half-width 50
    expect($points['2026-05-21']['point_minor'])->toBe(9500);
    expect($points['2026-05-21']['low_minor'])->toBe($points['2026-05-20']['low_minor']);
    expect($points['2026-05-22']['point_minor'])->toBe(9500);
});

it('converts cross-currency contributions to the default currency at the supplied rate', function (): void {
    $asOf = CarbonImmutable::parse('2026-05-19');
    $on = CarbonImmutable::parse('2026-05-20');

    // USD 5.99 = 599 minor units; USD→EUR at 0.9 → 539 EUR minor.
    $contributions = [
        new ForecastContribution(
            date: $on,
            pointMinor: -599,
            lowMinor: -629, // half-width 15 in USD
            highMinor: -569,
            currency: 'USD',
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
        rates: ['USD' => '0.9'],
    )->points;

    // point: 10000 + round(-5.99 × 0.9) = 10000 + (-539) = 9461.
    expect($points['2026-05-20']['point_minor'])->toBe(9461);
    expect($points['2026-05-20']['currency'])->toBe('EUR');
});

// Raising here took the whole projection down for one dollar subscription. A
// currency the rate table cannot reach is left out of the running balance and
// named, which is what every other cross-currency total in the app does.
it('names a currency it has no rate for instead of raising on it', function (): void {
    $asOf = CarbonImmutable::parse('2026-05-19');
    $on = CarbonImmutable::parse('2026-05-20');

    $contributions = [
        new ForecastContribution(
            date: $on,
            pointMinor: -599,
            lowMinor: -629,
            highMinor: -569,
            currency: 'USD',
            seriesId: 1,
            accountId: 1,
        ),
    ];

    $folded = dfFold()->fold(
        openingBalanceMinor: 10000,
        contributions: $contributions,
        asOf: $asOf,
        horizonDays: 2,
        defaultCurrency: 'EUR',
        rates: [],
    );

    expect($folded->unconvertedCurrencies)->toBe(['USD'])
        ->and($folded->points['2026-05-20']['point_minor'])->toBe(10000)
        ->and($folded->points['2026-05-20']['high_minor'])->toBe(10000);
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
        rates: [],
    )->points;

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
        rates: [],
    )->points;

    expect($points['2026-05-19']['high_minor'] - $points['2026-05-19']['low_minor'])->toBe(0);
    expect($points['2026-05-20']['high_minor'] - $points['2026-05-20']['low_minor'])->toBe(20);
    expect($points['2026-05-21']['high_minor'] - $points['2026-05-21']['low_minor'])->toBe(20);
    expect($points['2026-05-22']['high_minor'] - $points['2026-05-22']['low_minor'])->toBe(20);
});

// One booked row is certain, and it used to overwrite the band rather than
// leave it alone: /forecast stated "EUR5,084.64 - EUR5,084.64 on day 30" where
// the truth was ±EUR22.50. Visible on the shipped demo seed.
it('keeps the carried band when a later day carries only a zero-width contribution', function (): void {
    $asOf = CarbonImmutable::parse('2026-05-19');

    $contributions = [
        new ForecastContribution(
            date: CarbonImmutable::parse('2026-05-20'),
            pointMinor: -100,
            lowMinor: -125, // half-width 25
            highMinor: -75,
            currency: 'EUR',
            seriesId: 1,
            accountId: 1,
        ),
        new ForecastContribution(
            date: CarbonImmutable::parse('2026-05-21'),
            pointMinor: -400,
            lowMinor: -400,
            highMinor: -400,
            currency: 'EUR',
            seriesId: 0,
            accountId: 1,
        ),
    ];

    $points = dfFold()->fold(
        openingBalanceMinor: 10000,
        contributions: $contributions,
        asOf: $asOf,
        horizonDays: 3,
        defaultCurrency: 'EUR',
        rates: [],
    )->points;

    expect($points['2026-05-20']['high_minor'] - $points['2026-05-20']['low_minor'])->toBe(50)
        ->and($points['2026-05-21']['point_minor'])->toBe(9500)
        ->and($points['2026-05-21']['high_minor'] - $points['2026-05-21']['low_minor'])->toBe(50)
        ->and($points['2026-05-22']['high_minor'] - $points['2026-05-22']['low_minor'])->toBe(50);
});

// Day 0 is the anchor, and the anchor is observed. A jitter replica clamped
// onto it drew the chart's own day-0 point EUR25.71 under the header figure,
// with a band that did not contain it.
it('leaves day 0 at the opening balance and folds an on-or-before contribution into day 1', function (): void {
    $asOf = CarbonImmutable::parse('2026-05-19');

    $contributions = [
        new ForecastContribution(
            date: $asOf,
            pointMinor: -2571,
            lowMinor: -2571,
            highMinor: -2571,
            currency: 'EUR',
            seriesId: 1,
            accountId: 1,
        ),
    ];

    $points = dfFold()->fold(
        openingBalanceMinor: 660464,
        contributions: $contributions,
        asOf: $asOf,
        horizonDays: 3,
        defaultCurrency: 'EUR',
        rates: [],
    )->points;

    expect($points['2026-05-19']['point_minor'])->toBe(660464)
        ->and($points['2026-05-19']['low_minor'])->toBe(660464)
        ->and($points['2026-05-19']['high_minor'])->toBe(660464)
        ->and($points['2026-05-20']['point_minor'])->toBe(657893)
        ->and($points['2026-05-22']['point_minor'])->toBe(657893);
});

// A rate is major-to-major, so a scale change across the pair has to be applied
// with it. Multiplied straight onto minor units, a JPY5,000 one-off reached a
// euro curve as EUR0.30.
it('converts across a scale change rather than multiplying minor units by a major-unit rate', function (): void {
    $asOf = CarbonImmutable::parse('2026-05-19');

    $contributions = [
        new ForecastContribution(
            date: CarbonImmutable::parse('2026-05-20'),
            pointMinor: -5000, // JPY 5,000 — a yen has no minor unit
            lowMinor: -5000,
            highMinor: -5000,
            currency: 'JPY',
            seriesId: 1,
            accountId: 1,
        ),
    ];

    $points = dfFold()->fold(
        openingBalanceMinor: 100000,
        contributions: $contributions,
        asOf: $asOf,
        horizonDays: 2,
        defaultCurrency: 'EUR',
        rates: ['JPY' => '0.006'],
    )->points;

    // JPY 5,000 × 0.006 = EUR 30.00 = 3000 minor. A bare minor-unit product
    // says 30 minor, EUR 0.30.
    expect($points['2026-05-20']['point_minor'])->toBe(97000);
});
