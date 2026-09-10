<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Forecasting\Internal\Pipeline\CadenceJitter;
use Modules\Forecasting\Internal\Pipeline\ForecastContribution;

/** @link ../../../../.docs/features/forecasting/projection-math.md#cadence-jitter */
function smearedContribution(int $point, int $low, int $high): ForecastContribution
{
    return new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-15'),
        pointMinor: $point,
        lowMinor: $low,
        highMinor: $high,
        currency: 'EUR',
        seriesId: 101,
        accountId: 7,
        dateIsUncertain: true,
    );
}

/**
 * @param  list<ForecastContribution>  $replicas
 * @return array{point: int, low: int, high: int}
 */
function smearedTotals(array $replicas): array
{
    $totals = ['point' => 0, 'low' => 0, 'high' => 0];

    foreach ($replicas as $replica) {
        $totals['point'] += $replica->pointMinor;
        $totals['low'] += $replica->lowMinor;
        $totals['high'] += $replica->highMinor;
    }

    return $totals;
}

it('smears a charge across seven days without inventing or losing a minor unit', function (int $point, int $low, int $high): void {
    $replicas = (new CadenceJitter)->apply(
        [smearedContribution($point, $low, $high)],
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    expect(smearedTotals($replicas))->toBe(['point' => $point, 'low' => $low, 'high' => $high]);
})->with([
    'a euro that does not divide by seven' => [-100, -110, -90],
    'the doc worked example' => [-1000, -1100, -900],
    'an income side' => [2999, 2699, 3299],
    'a yen charge with no minor unit' => [-50000, -55000, -45000],
    'one minor unit, which seven days cannot each carry' => [-1, -2, -1],
]);

it('gives every replica of a whole that does divide the same share', function (): void {
    $replicas = (new CadenceJitter)->apply(
        [smearedContribution(-70000, -77000, -63000)],
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    foreach ($replicas as $replica) {
        expect($replica->pointMinor)->toBe(-10000);
    }
});
