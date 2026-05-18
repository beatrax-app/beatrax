<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Forecasting\Internal\Pipeline\CadenceJitter;
use Modules\Forecasting\Internal\Pipeline\ForecastContribution;

/*
 * Unit coverage for CadenceJitter — the ±N-day cadence-date jitter
 * helper.
 *
 * Asserts the deterministic replication shape (2N+1 replicas per
 * contribution), the lossless-within-rounding distribution of point
 * magnitudes across replicas, the preservation of seriesId / accountId
 * / currency / fxRateUsed on every replica, and the empty-input
 * pass-through. The helper has no DI so the tests instantiate it
 * directly via `new CadenceJitter`.
 */

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
    // -€10.00 = -1000 minor units. With 7-replica window and weight=
    // round(100/7)=14, each replica should be -€1.40 (-140 minor) and
    // the sum should be -€9.80 (-980), losing -€0.20 (20 minor) to
    // integer rounding. Tolerance is ±2 minor per replica.
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
        // low <= point <= high invariant survives the weight scaling.
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

    // First 7 belong to the first contribution; next 7 to the second.
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
    // Single contribution at day D. Without jitter, the per-day signed
    // sum on day D is -1000; on D-3 and D+3 it is 0. With jitter, the
    // contribution is spread across the ±3-day window so EACH day
    // carries a fractional share — D-3 through D+3 each get
    // approximately -143 (= round(-1000 × 14 / 100)). The band the
    // daily fold synthesises is therefore wider in time (7 days vs 1),
    // which is what produces the visual "spread" on the chart.
    $contributions = [cjContribution(-1000, -1100, -900)];

    $jittered = $this->jitter->apply($contributions, 3);

    // Count non-zero days in the jittered output.
    $datesWithMagnitude = 0;
    foreach ($jittered as $j) {
        if ($j->pointMinor !== 0) {
            $datesWithMagnitude++;
        }
    }
    expect($datesWithMagnitude)->toBe(7); // All 7 days carry a fractional share.
});
