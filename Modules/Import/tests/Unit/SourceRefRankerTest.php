<?php

declare(strict_types=1);

use Modules\Import\Public\Services\SourceRefRanker;

it('returns zero when the reference is null or empty', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->rank(null, 'asn-csv'))->toBe(0);
    expect($ranker->rank('', 'asn-csv'))->toBe(0);
});

it('ranks asn-camt053 above mt940 above csv variants', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->rank('ref', 'asn-camt053'))->toBeGreaterThan($ranker->rank('ref', 'asn-mt940'));
    expect($ranker->rank('ref', 'asn-mt940'))->toBeGreaterThan($ranker->rank('ref', 'asn-csv'));
});

it('ranks paypal-receipt above paypal-csv and below asn-camt053', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->rank('ref', 'paypal-receipt'))->toBeGreaterThan($ranker->rank('ref', 'paypal-csv'));
    expect($ranker->rank('ref', 'paypal-receipt'))->toBeLessThan($ranker->rank('ref', 'asn-camt053'));
});

it('returns zero for an unknown format', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->rank('ref', 'something-else'))->toBe(0);
});
