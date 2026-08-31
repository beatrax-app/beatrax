<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Forecasting\Internal\Pipeline\CadenceJitter;
use Modules\Forecasting\Internal\Pipeline\DailyFold;
use Modules\Forecasting\Internal\Pipeline\ForecastContribution;
use Modules\FX\Public\Services\CrossCurrencyTotal;

/** @link ../../../../.docs/features/forecasting/projection-math.md#cadence-jitter */
function cjContribution(int $point = -1000, int $low = -1100, int $high = -900, string $date = '2026-05-15'): ForecastContribution
{
    return new ForecastContribution(
        date: CarbonImmutable::parse($date),
        pointMinor: $point,
        lowMinor: $low,
        highMinor: $high,
        currency: 'EUR',
        seriesId: 101,
        accountId: 7,
        dateIsUncertain: true,
    );
}

function cjWindowStart(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-05-01');
}

function cjWindowEnd(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-06-30');
}

beforeEach(function (): void {
    $this->jitter = new CadenceJitter;
});

it('replicates a single contribution into 7 jittered entries across a ±3-day window', function (): void {
    $contributions = [cjContribution(-1000, -1100, -900)];

    $jittered = $this->jitter->apply($contributions, cjWindowStart(), cjWindowEnd(), 3);

    expect($jittered)->toHaveCount(7); // 2 × 3 + 1.

    $dates = array_map(static fn (ForecastContribution $c): string => $c->date->toDateString(), $jittered);
    expect($dates)->toBe([
        '2026-05-12',
        '2026-05-13',
        '2026-05-14',
        '2026-05-15',
        '2026-05-16',
        '2026-05-17',
        '2026-05-18',
    ]);
});

it('distributes the point magnitude across replicas within ±2 minor units of perfect division', function (): void {
    // The weight (100/7) stays unrounded, so only the per-replica minor
    // amount rounds; the ±2 tolerance is what that rounding can cost.
    $contributions = [cjContribution(-1000, -1100, -900)];

    $jittered = $this->jitter->apply($contributions, cjWindowStart(), cjWindowEnd(), 3);

    $perReplicaExpected = (int) round(-1000 * (100 / 7) / 100); // = -143.
    foreach ($jittered as $j) {
        expect(abs($j->pointMinor - $perReplicaExpected))->toBeLessThanOrEqual(2);
    }
});

it('preserves seriesId, accountId and currency on every replica', function (): void {
    $contributions = [
        new ForecastContribution(
            date: CarbonImmutable::parse('2026-05-15'),
            pointMinor: -599,
            lowMinor: -660,
            highMinor: -540,
            currency: 'USD',
            seriesId: 42,
            accountId: 3,
            dateIsUncertain: true,
        ),
    ];

    $jittered = $this->jitter->apply($contributions, cjWindowStart(), cjWindowEnd(), 3);

    foreach ($jittered as $j) {
        expect($j->seriesId)->toBe(42);
        expect($j->accountId)->toBe(3);
        expect($j->currency)->toBe('USD');
    }
});

it('preserves the sign on every replica for expense contributions', function (): void {
    $contributions = [cjContribution(-10000, -11000, -9000)];

    $jittered = $this->jitter->apply($contributions, cjWindowStart(), cjWindowEnd(), 3);

    foreach ($jittered as $j) {
        expect($j->pointMinor)->toBeLessThan(0);
        expect($j->lowMinor)->toBeLessThan(0);
        expect($j->highMinor)->toBeLessThan(0);
        expect($j->lowMinor)->toBeLessThanOrEqual($j->pointMinor);
        expect($j->pointMinor)->toBeLessThanOrEqual($j->highMinor);
    }
});

it('returns an empty list when the input is empty', function (): void {
    expect($this->jitter->apply([], cjWindowStart(), cjWindowEnd(), 3))->toBe([]);
    expect($this->jitter->apply([], cjWindowStart(), cjWindowEnd(), 0))->toBe([]);
});

it('replicates two contributions independently — 2 × 7 = 14 jittered entries', function (): void {
    $contributions = [
        cjContribution(-1000),
        new ForecastContribution(
            date: CarbonImmutable::parse('2026-06-10'),
            pointMinor: -2000,
            lowMinor: -2200,
            highMinor: -1800,
            currency: 'EUR',
            seriesId: 999,
            accountId: 4,
            dateIsUncertain: true,
        ),
    ];

    $jittered = $this->jitter->apply($contributions, cjWindowStart(), cjWindowEnd(), 3);
    expect($jittered)->toHaveCount(14);

    $firstSeven = array_slice($jittered, 0, 7);
    $secondSeven = array_slice($jittered, 7, 7);
    foreach ($firstSeven as $c) {
        expect($c->seriesId)->toBe(101);
        expect($c->accountId)->toBe(7);
    }
    foreach ($secondSeven as $c) {
        expect($c->seriesId)->toBe(999);
        expect($c->accountId)->toBe(4);
    }
});

it('produces a wider band when daily-folded vs a single point on day D (quadrature effect proxy)', function (): void {
    // Un-jittered, only day D carries the -1000. Jittered, all seven days
    // carry a share, which is what widens the band the daily fold draws.
    $contributions = [cjContribution(-1000, -1100, -900)];

    $jittered = $this->jitter->apply($contributions, cjWindowStart(), cjWindowEnd(), 3);

    $datesWithMagnitude = 0;
    foreach ($jittered as $j) {
        if ($j->pointMinor !== 0) {
            $datesWithMagnitude++;
        }
    }
    expect($datesWithMagnitude)->toBe(7);
});

it('leaves a contribution whose charge date is known exactly where it is', function (): void {
    $certain = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-15'),
        pointMinor: -1199,
        lowMinor: -1259,
        highMinor: -1139,
        currency: 'EUR',
        seriesId: 55,
        accountId: 2,
    );

    $jittered = $this->jitter->apply([$certain], cjWindowStart(), cjWindowEnd(), 3);

    expect($jittered)->toHaveCount(1);
    expect($jittered[0])->toBe($certain);
});

it('keeps every minor unit of an occurrence dated on the first day the fold walks', function (): void {
    // The three replicas at D-3..D-1 fall before the fold's first day, and
    // the fold never reads a bucket outside its walk: 3/7 of the charge used
    // to vanish on the one day the reader is looking at.
    $asOf = CarbonImmutable::parse('2026-05-15');
    $contributions = [cjContribution(-70000, -77000, -63000, '2026-05-15')];

    $jittered = (new CadenceJitter)->apply($contributions, $asOf, $asOf->addDays(30), 3);

    $folded = (new DailyFold(app(CrossCurrencyTotal::class)))->fold(
        openingBalanceMinor: 0,
        contributions: $jittered,
        asOf: $asOf,
        horizonDays: 30,
        defaultCurrency: 'EUR',
        rates: [],
    )->points;

    $lastDay = $folded[$asOf->addDays(30)->toDateString()];
    expect($lastDay['point_minor'])->toBeGreaterThanOrEqual(-70007)
        ->and($lastDay['point_minor'])->toBeLessThanOrEqual(-69993);
});

it('keeps every minor unit of an occurrence dated on the last day the fold walks', function (): void {
    $asOf = CarbonImmutable::parse('2026-05-15');
    $horizonEnd = $asOf->addDays(30);
    $contributions = [cjContribution(-70000, -77000, -63000, $horizonEnd->toDateString())];

    $jittered = (new CadenceJitter)->apply($contributions, $asOf, $horizonEnd, 3);

    $folded = (new DailyFold(app(CrossCurrencyTotal::class)))->fold(
        openingBalanceMinor: 0,
        contributions: $jittered,
        asOf: $asOf,
        horizonDays: 30,
        defaultCurrency: 'EUR',
        rates: [],
    )->points;

    $lastDay = $folded[$horizonEnd->toDateString()];
    expect($lastDay['point_minor'])->toBeGreaterThanOrEqual(-70007)
        ->and($lastDay['point_minor'])->toBeLessThanOrEqual(-69993);
});
