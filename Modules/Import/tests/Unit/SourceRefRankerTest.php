<?php

declare(strict_types=1);

use Modules\Import\Public\Services\SourceRefRanker;
use Modules\Ingestion\Public\Enums\SourceFormat;

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

it('returns zero for an unknown format', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->rank('ref', 'something-else'))->toBe(0);
});

it('counts the eml and mbox transports as receipt formats, the only ones a stored receipt row ever carries', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->isReceiptFormat(SourceFormat::Eml->value))->toBeTrue();
    expect($ranker->isReceiptFormat(SourceFormat::Mbox->value))->toBeTrue();
});

it('ranks an eml or mbox receipt above the statement exports it enriches', function (): void {
    $ranker = new SourceRefRanker;
    expect($ranker->rank('ref', SourceFormat::Eml->value))->toBeGreaterThan($ranker->rank('ref', 'paypal-csv'));
    expect($ranker->rank('ref', SourceFormat::Eml->value))->toBeGreaterThan($ranker->rank('ref', 'ics-pdf'));
    expect($ranker->rank('ref', SourceFormat::Mbox->value))->toBeGreaterThan($ranker->rank('ref', 'asn-csv'));
    expect($ranker->rank('ref', SourceFormat::Eml->value))->toBeLessThan($ranker->rank('ref', 'camt053'));
});

// The list lived here three times over — a const, and again in the match arms —
// and this file's own comment records that omitting one broke every receipt.
// SourceFormat::isReceiptFile() is the one answer; a new receipt transport must
// be recognised here the moment the enum recognises it, with nothing to update.
it('recognises exactly the transports SourceFormat itself calls receipt files', function (): void {
    $ranker = new SourceRefRanker;

    foreach (SourceFormat::cases() as $format) {
        expect($ranker->isReceiptFormat($format->value))
            ->toBe($format->isReceiptFile(), $format->value.' disagrees with SourceFormat::isReceiptFile().');
    }
});

it('ranks every receipt transport in one band, so none can be forgotten out of it', function (): void {
    $ranker = new SourceRefRanker;

    $receiptRanks = [];
    foreach (SourceFormat::cases() as $format) {
        if ($format->isReceiptFile()) {
            $receiptRanks[] = $ranker->rank('ref', $format->value);
        }
    }

    expect($receiptRanks)->not->toBeEmpty();
    expect(array_unique($receiptRanks))->toHaveCount(1);
    expect($receiptRanks[0])->toBeGreaterThan($ranker->rank('ref', SourceFormat::IcsPdf->value));
});

it('does not mistake a receipt matcher key for a source format', function (): void {
    $ranker = new SourceRefRanker;

    foreach (['paypal-receipt', 'ics-receipt', 'google-play-receipt'] as $matcherKey) {
        expect($ranker->isReceiptFormat($matcherKey))->toBeFalse($matcherKey.' is a matcher key, not a source_format value.');
        expect($ranker->rank('ref', $matcherKey))->toBe(0, $matcherKey.' never reaches rank(): both callers pass a source_format.');
    }
});
