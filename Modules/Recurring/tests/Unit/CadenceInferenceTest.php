<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Recurring\Internal\CadenceInferrer;

/**
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

/**
 * @param  list<string>  $dates
 * @return list<CarbonImmutable>
 */
function cinfPostings(array $dates): array
{
    return array_map(
        static fn (string $date): CarbonImmutable => CarbonImmutable::parse($date.' 00:00:00'),
        $dates,
    );
}

/**
 * @param  list<string>  $dates
 */
function cinfNextExpected(array $dates): string
{
    $result = (new CadenceInferrer)->infer(cinfPostings($dates));

    return $result['next_expected_at']?->toDateString() ?? 'null';
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

it('projects next_expected_at one cadence step past the last posting', function (array $postings, string $expected): void {
    expect(cinfNextExpected($postings))->toBe($expected);
})->with([
    'monthly · two postings on the 15th' => [['2026-01-15', '2026-02-15'], '2026-03-15'],
    'monthly · a third posting stays on the 15th' => [['2026-01-15', '2026-02-15', '2026-03-15'], '2026-04-15'],
    'monthly · rent on the 1st' => [['2026-07-01', '2026-08-01', '2026-09-01'], '2026-10-01'],
    'monthly · rent five postings in' => [['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01'], '2026-12-01'],
    'weekly' => [['2026-01-05', '2026-01-12', '2026-01-19'], '2026-01-26'],
    'quarterly · on the 15th' => [['2026-01-15', '2026-04-15', '2026-07-15'], '2026-10-15'],
    'yearly · across a leap year' => [['2024-01-15', '2025-01-15'], '2026-01-15'],
]);

it('lands a month-end series on the short month and then back on the 31st', function (): void {
    expect(cinfNextExpected(['2025-12-31', '2026-01-31']))->toBe('2026-02-28');
    expect(cinfNextExpected(['2025-12-31', '2026-01-31', '2026-02-28']))->toBe('2026-03-31');
    expect(cinfNextExpected(['2025-12-31', '2026-01-31', '2026-02-28', '2026-03-31']))->toBe('2026-04-30');
});

it('lands a month-end series on 29 February in a leap year', function (): void {
    expect(cinfNextExpected(['2023-12-31', '2024-01-31']))->toBe('2024-02-29');
});

it('projects one month past the last posting when a monthly series skipped two periods', function (): void {
    $postings = ['2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-09-01'];
    $result = (new CadenceInferrer)->infer(cinfPostings($postings));

    expect($result['cadence']->value)->toBe('monthly');
    expect($result['missed_count'])->toBe(1);
    expect($result['next_expected_at']?->toDateString())->toBe('2026-10-01');
});

it('still flags a widening monthly series low-confidence and still steps the cadence', function (): void {
    // Gaps of 25, 30, 34 and 40 days: monthly by the band, soft by the stddev.
    // The flag marks the estimate, it does not move it off the billing day.
    $postings = ['2026-01-01', '2026-01-26', '2026-02-25', '2026-03-31', '2026-05-10'];
    $result = (new CadenceInferrer)->infer(cinfPostings($postings));

    expect($result['cadence']->value)->toBe('monthly');
    expect($result['confidence_low'])->toBeTrue();
    expect($result['next_expected_at']?->toDateString())->toBe('2026-06-01');
});

it('leaves an irregular cluster with no projection at all', function (): void {
    $postings = ['2024-01-01', '2024-01-06', '2024-02-15', '2024-04-25', '2024-08-23'];
    $result = (new CadenceInferrer)->infer(cinfPostings($postings));

    expect($result['cadence']->value)->toBe('irregular');
    expect($result['next_expected_at'])->toBeNull();
    expect($result['confidence_low'])->toBeTrue();
    expect($result['median_interval_days'])->toBe(40.0);
    expect($result['missed_count'])->toBe(1);
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
