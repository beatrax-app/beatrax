<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\TimeBucketGenerator;
use Modules\Reports\Internal\Enums\ReportGranularity;

// Every calendar step this class offers clamps — addMonthNoOverflow and
// addMonthsNoOverflow(3). The yearly widening it falls back to did not, so a
// range opening on 29 February walked its bucket edges into March and stayed
// there: each bucket then straddles a month boundary its label does not name.

function yearlyStepRange(string $from, string $toExclusive): Period
{
    return new Period(
        start: CarbonImmutable::parse($from),
        endExclusive: CarbonImmutable::parse($toExclusive),
        label: $from.'..'.$toExclusive,
    );
}

it('steps a year off 29 February onto the end of February, not into March', function (): void {
    $buckets = app(TimeBucketGenerator::class)
        ->generate(yearlyStepRange('2028-02-29', '2068-01-01'), ReportGranularity::Monthly);

    expect($buckets[0]->endExclusive->toDateString())->toBe('2029-02-28')
        ->and($buckets[1]->start->toDateString())->toBe('2029-02-28');
});

it('keeps every yearly bucket edge in the month the range opened in', function (): void {
    $buckets = app(TimeBucketGenerator::class)
        ->generate(yearlyStepRange('2028-02-29', '2068-01-01'), ReportGranularity::Monthly);

    $months = [];
    foreach ($buckets as $bucket) {
        $months[] = $bucket->start->format('m');
    }

    expect(array_unique($months))->toBe(['02']);
});

it('leaves a yearly step off an ordinary day on the same day', function (): void {
    $buckets = app(TimeBucketGenerator::class)
        ->generate(yearlyStepRange('2028-03-15', '2068-01-01'), ReportGranularity::Monthly);

    expect($buckets[0]->endExclusive->toDateString())->toBe('2029-03-15');
});
