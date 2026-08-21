<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\TimeBucketGenerator;
use Modules\Reports\Public\Enums\ReportGranularity;

it('generates 12 half-open monthly buckets for a 12-month span', function (): void {
    $period = new Period(
        start: CarbonImmutable::parse('2025-01-01'),
        endExclusive: CarbonImmutable::parse('2026-01-01'),
        label: '2025',
    );

    $buckets = app(TimeBucketGenerator::class)->generate($period, ReportGranularity::Monthly);

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

    $buckets = app(TimeBucketGenerator::class)->generate($period, ReportGranularity::Weekly);

    expect($buckets)->toHaveCount(6);
    foreach ($buckets as $bucket) {
        // diffInDays() returns float in this Carbon version, so a strict int 7
        // can never match.
        expect($bucket->start->diffInDays($bucket->endExclusive))->toBe(7.0);
    }
});

it('clamps the last bucket endExclusive to the overall range endExclusive, never overshooting', function (): void {
    $period = new Period(
        start: CarbonImmutable::parse('2025-01-01'),
        endExclusive: CarbonImmutable::parse('2025-01-20'),
        label: 'partial month',
    );

    $buckets = app(TimeBucketGenerator::class)->generate($period, ReportGranularity::Monthly);
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

    $buckets = app(TimeBucketGenerator::class)->generate($period, ReportGranularity::Monthly);

    // 11 years x 12 = 132 uncapped monthly buckets; only that SOME cap applies
    // is pinned here, not its exact value.
    expect(count($buckets))->toBeLessThan(132);
    expect($buckets)->not->toBeEmpty();
});

it('caps a multi-year weekly range to stay under MAX_BUCKET_POINTS by widening (never producing hundreds of raw weekly buckets)', function (): void {
    $period = new Period(
        start: CarbonImmutable::parse('2015-01-01'),
        endExclusive: CarbonImmutable::parse('2026-01-01'),
        label: '11 years',
    );

    // ~573 uncapped weekly points: unlike 'monthly', the 'weekly' branch used to
    // call stepBuckets() with no cap check, so it must widen instead.
    $buckets = app(TimeBucketGenerator::class)->generate($period, ReportGranularity::Weekly);

    expect(count($buckets))->toBeLessThanOrEqual(TimeBucketGenerator::MAX_BUCKET_POINTS);
    expect($buckets)->not->toBeEmpty();
    expect($buckets[0]->start->toDateString())->toBe('2015-01-01');
    expect($buckets[count($buckets) - 1]->endExclusive->toDateString())->toBe('2026-01-01');
});
