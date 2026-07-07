<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\TimeBucketGenerator;

/*
 * Wave 0 RED stub (999.6-03 Task 2, Req 6/7).
 *
 * Pins Modules\Reports\Internal\Aggregation\TimeBucketGenerator::generate(
 * Period $period, string $granularity): list<Period> — each generated
 * bucket is itself a half-open Period (mirrors PeriodQuery's
 * CarbonImmutable::addMonthNoOverflow() month-stepping idiom), with the
 * last bucket's endExclusive clamped to the overall range's endExclusive
 * (never overshoot).
 *
 * RED as intended: TimeBucketGenerator does not exist yet — every test
 * below fails on `app(TimeBucketGenerator::class)` (missing class), not on
 * the (already-existing) Period DTO it's built from.
 */

it('generates 12 half-open monthly buckets for a 12-month span', function (): void {
    $period = new Period(
        start: CarbonImmutable::parse('2025-01-01'),
        endExclusive: CarbonImmutable::parse('2026-01-01'),
        label: '2025',
    );

    $buckets = app(TimeBucketGenerator::class)->generate($period, 'monthly');

    expect($buckets)->toHaveCount(12);
    expect($buckets[0]->start->toDateString())->toBe('2025-01-01');
    expect($buckets[0]->endExclusive->toDateString())->toBe('2025-02-01');
    expect($buckets[11]->start->toDateString())->toBe('2025-12-01');
    expect($buckets[11]->endExclusive->toDateString())->toBe('2026-01-01');
});

it('generates weekly buckets for a 6-week span', function (): void {
    $period = new Period(
        start: CarbonImmutable::parse('2026-01-05'),
        endExclusive: CarbonImmutable::parse('2026-02-16'),
        label: '6 weeks',
    );

    $buckets = app(TimeBucketGenerator::class)->generate($period, 'weekly');

    expect($buckets)->toHaveCount(6);
    foreach ($buckets as $bucket) {
        expect($bucket->start->diffInDays($bucket->endExclusive))->toBe(7);
    }
});

it('clamps the last bucket endExclusive to the overall range endExclusive, never overshooting', function (): void {
    $period = new Period(
        start: CarbonImmutable::parse('2025-01-01'),
        endExclusive: CarbonImmutable::parse('2025-01-20'),
        label: 'partial month',
    );

    $buckets = app(TimeBucketGenerator::class)->generate($period, 'monthly');
    $last = $buckets[count($buckets) - 1];

    expect($last->endExclusive->toDateString())->toBe('2025-01-20');
    expect($last->endExclusive->lessThanOrEqualTo($period->endExclusive))->toBeTrue();
});

it('caps the point count for a multi-year monthly range so charts stay renderable', function (): void {
    $period = new Period(
        start: CarbonImmutable::parse('2015-01-01'),
        endExclusive: CarbonImmutable::parse('2026-01-01'),
        label: '11 years',
    );

    $buckets = app(TimeBucketGenerator::class)->generate($period, 'monthly');

    // 132 uncapped monthly buckets (11 years x 12) must be capped to a
    // renderable ceiling — the exact cap is a later-wave decision, this
    // stub only pins that SOME cap applies.
    expect(count($buckets))->toBeLessThan(132);
    expect($buckets)->not->toBeEmpty();
});
