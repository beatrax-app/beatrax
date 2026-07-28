<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Recurring\Internal\CadenceInferrer;

/*
 * Cadence inference dataset — snap-band boundaries, missed-interval
 * tolerance, the confidence-low signal, and the two-occurrence
 * minimum. Each row drives a single call into
 * `CadenceInferrer::infer($timestamps)` and asserts the resulting
 * cadence class plus, where load-bearing, the missed_count and
 * confidence_low flags.
 */

/**
 * Builds a sorted ascending list of CarbonImmutable timestamps starting
 * at $start, each separated by the matching $intervalDays gap. The
 * first timestamp is $start; subsequent ones add the previous gaps.
 *
 * @param  list<int>  $intervalDays
 * @return list<CarbonImmutable>
 */
function cinfTimestamps(string $start, array $intervalDays): array
{
    $cursor = CarbonImmutable::parse($start);
    $out = [$cursor];
    foreach ($intervalDays as $delta) {
        $cursor = $cursor->addDays($delta);
        $out[] = $cursor;
    }

    return $out;
}

it('infers the expected cadence band', function (array $intervals, string $expectedCadence): void {
    $inferrer = new CadenceInferrer;
    $result = $inferrer->infer(cinfTimestamps('2024-01-01', $intervals));

    expect($result['cadence']->value)->toBe($expectedCadence);
})->with([
    'weekly · four points · 7d gaps' => [[7, 7, 7], 'weekly'],
    'weekly · lower boundary · 9d gaps' => [[9, 9, 9], 'weekly'],
    'monthly · stable 30d gaps' => [[30, 30, 30], 'monthly'],
    'monthly · band lower boundary · 10d gaps' => [[10, 10, 10], 'monthly'],
    'monthly · band upper boundary · 45d gaps' => [[45, 45, 45], 'monthly'],
    'monthly · one missed period absorbed via 60d gap' => [[30, 60, 30, 30], 'monthly'],
    'irregular · just outside monthly band · 46d gaps' => [[46, 46, 46], 'irregular'],
    'irregular · gym-style sparse non-uniform gaps' => [[5, 40, 70, 120], 'irregular'],
    'quarterly · stable 91d gaps' => [[91, 91, 91], 'quarterly'],
    'quarterly · band lower boundary · 80d gaps' => [[80, 80, 80], 'quarterly'],
    'quarterly · band upper boundary · 100d gaps' => [[100, 100, 100], 'quarterly'],
    'yearly · two occurrences only · 365d gap' => [[365], 'yearly'],
    'yearly · band lower boundary · 350d gap' => [[350], 'yearly'],
    'yearly · band upper boundary · 380d gap' => [[380], 'yearly'],
    'irregular · single timestamp · no intervals' => [[], 'irregular'],
]);

it('flags confidence_low when interval stddev exceeds 5 days', function (): void {
    $inferrer = new CadenceInferrer;
    $result = $inferrer->infer(cinfTimestamps('2024-01-01', [25, 30, 35, 40]));

    expect($result['cadence']->value)->toBe('monthly');
    expect($result['confidence_low'])->toBeTrue();
});

it('leaves confidence_low false on tight monthly intervals', function (): void {
    $inferrer = new CadenceInferrer;
    $result = $inferrer->infer(cinfTimestamps('2024-01-01', [30, 30, 30, 30]));

    expect($result['cadence']->value)->toBe('monthly');
    expect($result['confidence_low'])->toBeFalse();
});

it('counts intervals above the 1.8x missed-interval multiplier as missed', function (): void {
    $inferrer = new CadenceInferrer;
    $result = $inferrer->infer(cinfTimestamps('2024-01-01', [30, 65, 30, 30]));

    expect($result['cadence']->value)->toBe('monthly');
    expect($result['missed_count'])->toBe(1);
});

it('projects next_expected_at from the last timestamp + median interval', function (): void {
    $inferrer = new CadenceInferrer;
    $timestamps = cinfTimestamps('2024-01-01', [30, 30, 30]);
    $result = $inferrer->infer($timestamps);

    expect($result['next_expected_at'])->not->toBeNull();
    $last = end($timestamps);
    expect($result['next_expected_at']?->toDateString())->toBe($last->addDays(30)->toDateString());
});

it('returns next_expected_at=null when cadence is irregular', function (): void {
    $inferrer = new CadenceInferrer;
    $result = $inferrer->infer(cinfTimestamps('2024-01-01', [46, 46, 46]));

    expect($result['cadence']->value)->toBe('irregular');
    expect($result['next_expected_at'])->toBeNull();
});

it('returns irregular and zero metrics for the empty-list edge case', function (): void {
    $inferrer = new CadenceInferrer;
    $result = $inferrer->infer([]);

    expect($result['cadence']->value)->toBe('irregular');
    expect($result['median_interval_days'])->toBe(0.0);
    expect($result['missed_count'])->toBe(0);
    expect($result['confidence_low'])->toBeFalse();
});
