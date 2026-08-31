<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\TimeBucketGenerator;
use Modules\Reports\Internal\Enums\ReportGranularity;

function bucketRange(string $from, string $toExclusive): Period
{
    return new Period(
        start: CarbonImmutable::parse($from),
        endExclusive: CarbonImmutable::parse($toExclusive),
        label: $from.'..'.$toExclusive,
    );
}

it('gives every weekly bucket a label of its own', function (): void {
    $buckets = app(TimeBucketGenerator::class)
        ->generate(bucketRange('2026-06-01', '2026-09-01'), ReportGranularity::Weekly);

    $labels = array_map(static fn (Period $p): string => $p->label, $buckets);

    // Fourteen rows and three distinct labels is a report no row can be read
    // out of, and a CSV that cannot be pivoted.
    expect(count($labels))->toBeGreaterThan(12);
    expect(array_unique($labels))->toHaveCount(count($labels));
});

it('keeps a monthly bucket labelled by its month', function (): void {
    $buckets = app(TimeBucketGenerator::class)
        ->generate(bucketRange('2026-06-01', '2026-09-01'), ReportGranularity::Monthly);

    expect(array_map(static fn (Period $p): string => $p->label, $buckets))
        ->toBe(['Jun 2026', 'Jul 2026', 'Aug 2026']);
});

it('holds an arbitrarily long range inside the point cap whatever granularity it starts at', function (): void {
    $generator = app(TimeBucketGenerator::class);

    foreach ([ReportGranularity::Weekly, ReportGranularity::Monthly] as $granularity) {
        foreach ([['1900-01-01', '2027-01-01'], ['1000-01-01', '2027-01-01']] as [$from, $to]) {
            $buckets = $generator->generate(bucketRange($from, $to), $granularity);

            expect(count($buckets))
                ->toBeLessThanOrEqual(TimeBucketGenerator::MAX_BUCKET_POINTS, "{$granularity->value} {$from}..{$to}");
            expect(array_unique(array_map(static fn (Period $p): string => $p->label, $buckets)))
                ->toHaveCount(count($buckets));
        }
    }
});
