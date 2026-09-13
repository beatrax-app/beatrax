<?php

declare(strict_types=1);

use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Tests\Support\CsvHandedToTheApp;
use Modules\Ledger\Public\ValueObjects\CurrencyScale;

// The currency table's lookup is case-sensitive, so a code that is not in the
// case ISO 4217 writes finds no entry and falls back to the repo-wide two
// decimals — which is a hundredfold error in a currency that has none.
it('reads a lower-case currency code as the currency it names', function (): void {
    $body = CsvHandedToTheApp::REVOLUT_HEADER."\n"
        .'CARD_PAYMENT,Current,2026-07-28 09:00:00,2026-07-28 09:00:00,Konbini,-1000,0,jpy,COMPLETED,49000'."\n";

    $rows = CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::REVOLUT, $body);

    // Before: 'jpy' scaled by 100, so ¥1,000 was booked as -100000 minor units.
    expect($rows)->toHaveCount(1);
    expect($rows[0]->amountMinor)->toBe(-1000);
    expect($rows[0]->currency)->toBe('JPY');
});

it('reads a padded currency code as the currency it names', function (): void {
    $body = CsvHandedToTheApp::REVOLUT_HEADER."\n"
        .'CARD_PAYMENT,Current,2026-07-28 09:00:00,2026-07-28 09:00:00,Konbini,-1000,0,"  jpy ",COMPLETED,49000'."\n";

    $rows = CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::REVOLUT, $body);

    expect($rows[0]->amountMinor)->toBe(-1000);
    expect($rows[0]->currency)->toBe('JPY');
});

it('leaves the upper-case code the export actually writes alone', function (): void {
    $body = CsvHandedToTheApp::REVOLUT_HEADER."\n"
        .'CARD_PAYMENT,Current,2026-07-28 09:00:00,2026-07-28 09:00:00,Konbini,-1000,0,JPY,COMPLETED,49000'."\n";

    expect(CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::REVOLUT, $body)[0]->amountMinor)->toBe(-1000);
});

// The measurement the fix rests on: the lookup itself does not fold case, so
// normalising has to happen where the file is read.
it('shows the lookup that the two spellings do not share', function (): void {
    expect(CurrencyScale::minorUnitsPerMajor('JPY'))->toBe(1);
    expect(CurrencyScale::minorUnitsPerMajor('jpy'))->toBe(100);
});
