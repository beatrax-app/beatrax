<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Forecasting\Internal\Pipeline\CadenceJitter;
use Modules\Forecasting\Internal\Pipeline\ForecastContribution;

/** @link ../../../../.docs/features/forecasting/projection-math.md#cadence-jitter */
function cjContribution(int $point = -1000, int $low = -1100, int $high = -900): ForecastContribution
{
    return new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-15'),
        pointMinor: $point,
        lowMinor: $low,
        highMinor: $high,
        currency: 'EUR',
        fxRateUsed: null,
        seriesId: 101,
        accountId: 7,
    );
}

beforeEach(function (): void {
    $this->jitter = new CadenceJitter;
});

it('replicates a single contribution into 7 jittered entries across a ±3-day window', function (): void {
    $contributions = [cjContribution(-1000, -1100, -900)];

    $jittered = $this->jitter->apply($contributions, 3);

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

    $jittered = $this->jitter->apply($contributions, 3);

    $perReplicaExpected = (int) round(-1000 * (100 / 7) / 100); // = -143.
    foreach ($jittered as $j) {
        expect(abs($j->pointMinor - $perReplicaExpected))->toBeLessThanOrEqual(2);
    }
});

it('preserves seriesId, accountId, currency, and fxRateUsed on every replica', function (): void {
    $contributions = [
        new ForecastContribution(
            date: CarbonImmutable::parse('2026-05-15'),
            pointMinor: -599,
            lowMinor: -660,
            highMinor: -540,
            currency: 'USD',
            fxRateUsed: 0.9050,
            seriesId: 42,
            accountId: 3,
        ),
    ];

    $jittered = $this->jitter->apply($contributions, 3);

    foreach ($jittered as $j) {
        expect($j->seriesId)->toBe(42);
        expect($j->accountId)->toBe(3);
        expect($j->currency)->toBe('USD');
        expect($j->fxRateUsed)->toBe(0.9050);
    }
});

it('preserves the sign on every replica for expense contributions', function (): void {
    $contributions = [cjContribution(-10000, -11000, -9000)];

    $jittered = $this->jitter->apply($contributions, 3);

    foreach ($jittered as $j) {
        expect($j->pointMinor)->toBeLessThan(0);
        expect($j->lowMinor)->toBeLessThan(0);
        expect($j->highMinor)->toBeLessThan(0);
        expect($j->lowMinor)->toBeLessThanOrEqual($j->pointMinor);
        expect($j->pointMinor)->toBeLessThanOrEqual($j->highMinor);
    }
});

it('returns an empty list when the input is empty', function (): void {
    expect($this->jitter->apply([], 3))->toBe([]);
    expect($this->jitter->apply([], 0))->toBe([]);
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
            fxRateUsed: null,
            seriesId: 999,
            accountId: 4,
        ),
    ];

    $jittered = $this->jitter->apply($contributions, 3);
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

    $jittered = $this->jitter->apply($contributions, 3);

    $datesWithMagnitude = 0;
    foreach ($jittered as $j) {
        if ($j->pointMinor !== 0) {
            $datesWithMagnitude++;
        }
    }
    expect($datesWithMagnitude)->toBe(7);
});
