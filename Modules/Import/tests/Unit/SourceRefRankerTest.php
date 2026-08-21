<?php

declare(strict_types=1);

use Modules\Import\Public\Services\SourceRefRanker;

it('returns zero when the reference is null or empty', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->rank(null, 'asn-csv'))->toBe(0);
    expect($ranker->rank('', 'asn-csv'))->toBe(0);
});

it('ranks camt053 above mt940 above csv variants', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->rank('ref', 'camt053'))->toBeGreaterThan($ranker->rank('ref', 'mt940'));
    expect($ranker->rank('ref', 'mt940'))->toBeGreaterThan($ranker->rank('ref', 'asn-csv'));
});

it('ranks paypal-receipt above paypal-csv and below camt053', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->rank('ref', 'paypal-receipt'))->toBeGreaterThan($ranker->rank('ref', 'paypal-csv'));
    expect($ranker->rank('ref', 'paypal-receipt'))->toBeLessThan($ranker->rank('ref', 'camt053'));
});

it('returns zero for an unknown format', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->rank('ref', 'something-else'))->toBe(0);
});

it('ranks ics-receipt above ics-pdf and below camt053', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->rank('ref', 'ics-receipt'))->toBeGreaterThan($ranker->rank('ref', 'ics-pdf'));
    expect($ranker->rank('ref', 'ics-receipt'))->toBeLessThan($ranker->rank('ref', 'camt053'));
});

it('returns a non-zero rank for google-play-receipt', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->rank('ref', 'google-play-receipt'))->toBeGreaterThan(0);
    // Google Play deliberately shares the rank band of paypal-csv and asn-csv:
    // there is no cross-format dedup risk between them.
    expect($ranker->rank('ref', 'google-play-receipt'))->toBeLessThan($ranker->rank('ref', 'camt053'));
});

it('returns zero for a null or empty google-play-receipt reference', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->rank(null, 'google-play-receipt'))->toBe(0);
    expect($ranker->rank('', 'google-play-receipt'))->toBe(0);
});
