<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Tests\Support\CsvHandedToTheApp;

// league/csv combines a record onto the header by offset: a surplus cell is
// dropped and a missing one becomes null. Both readings are silent, and both
// are what a single unescaped delimiter inside a description produces.
it('refuses a row carrying more cells than the header names', function (): void {
    $body = CsvHandedToTheApp::N26_HEADER."\n"
        .'"2026-05-01","2026-05-01","REWE","DE89370400440532013000","Presentment","Groceries","Main Account","-23.45","","","","SURPLUS"'."\n";

    // Before: the twelfth cell was dropped and the row imported as if the file
    // had been the eleven columns the header names.
    expect(fn (): array => CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::N26, $body))
        ->toThrow(InvalidAmountException::class, 'carries more cells than the 11 columns the header names');
});

it('refuses a row that stops before a column the header names', function (): void {
    $body = CsvHandedToTheApp::ING_HEADER."\n"
        .'"20260501","Albert Heijn","NL91ABNA0417164300","NL57ASNB0123456789","BA","Af","23,45","Betaalautomaat"'."\n";

    // Before: 'Mededelingen' is ING's last column and the description this
    // preset reads, so the row imported with no description and nothing said.
    expect(fn (): array => CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::ING_NL, $body))
        ->toThrow(InvalidAmountException::class, "stops before the 'Mededelingen' column");
});

// An unescaped delimiter inside a counterparty name shifts every column after
// it, so the amount comes off whichever column landed on the amount's offset.
it('refuses a row whose columns were shifted by an unescaped delimiter', function (): void {
    $body = CsvHandedToTheApp::N26_HEADER."\n"
        .'"2026-05-01","2026-05-01",REWE, Berlin Mitte,"DE89370400440532013000","Presentment","Groceries","Main Account","-23.45","","",""'."\n";

    expect(fn (): array => CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::N26, $body))
        ->toThrow(InvalidAmountException::class, 'carries more cells than the 11 columns the header names');
});

it('leaves a rectangular file alone, empty trailing cells and all', function (): void {
    $body = CsvHandedToTheApp::N26_HEADER."\n"
        .'"2026-05-01","2026-05-01","REWE","DE89370400440532013000","Presentment","Groceries","Main Account","-23.45","","",""'."\n";

    $rows = CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::N26, $body);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->amountMinor)->toBe(-2345);
});

// An export that separates its sections with a bare delimiter line carries no
// figure to disagree with, so it stays skipped rather than becoming a refusal.
it('skips a filler line that holds nothing at all', function (): void {
    $body = CsvHandedToTheApp::N26_HEADER."\n"
        .'"2026-05-01","2026-05-01","REWE","DE89370400440532013000","Presentment","Groceries","Main Account","-23.45","","",""'."\n"
        .',,,,,,,,,,'."\n";

    expect(CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::N26, $body))->toHaveCount(1);
});
