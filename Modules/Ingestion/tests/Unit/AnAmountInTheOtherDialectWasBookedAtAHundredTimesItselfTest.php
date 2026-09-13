<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAmountParser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Tests\Support\CsvHandedToTheApp;

// ING's own export dialog offers the reader a choice of decimal separator, so
// a file in the dialect the preset does not declare is a file the app meets.
it('refuses a period-decimal figure read through the comma-decimal ING dialect', function (): void {
    $body = CsvHandedToTheApp::ING_HEADER."\n"
        .'"20260501","Albert Heijn","NL91ABNA0417164300","NL57ASNB0123456789","BA","Af","23.45","Betaalautomaat","Pasvolgnr 001"'."\n";

    // Before: the period was read as ING's thousands separator and dropped, so
    // €23.45 was booked as €2,345.00 — a hundred times the money.
    expect(fn (): array => CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::ING_NL, $body))
        ->toThrow(InvalidAmountException::class, "Cannot parse amount '23.45'.");
});

it('refuses a comma-decimal figure read through the period-decimal N26 dialect', function (): void {
    $body = CsvHandedToTheApp::N26_HEADER."\n"
        .'"2026-05-01","2026-05-01","REWE","DE89370400440532013000","Presentment","Groceries","Main Account","-12,50","","",""'."\n";

    // Before: -1250 cents was booked as -125000.
    expect(fn (): array => CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::N26, $body))
        ->toThrow(InvalidAmountException::class, "Cannot parse amount '-12,50'.");
});

it('refuses a figure carrying both separators the wrong way round', function (): void {
    // '1.234,56' through a period-decimal preset lost its comma group and
    // became 1.23456, which rounded to €1.23 — a thousandth of the figure.
    expect(fn (): int => (new GenericCsvAmountParser)->parseMinor('1.234,56', '.'))
        ->toThrow(InvalidAmountException::class);

    expect(fn (): int => (new GenericCsvAmountParser)->parseMinor('1,234.56', ','))
        ->toThrow(InvalidAmountException::class);
});

it('refuses a lone separator that groups nothing', function (): void {
    // A period that is neither a decimal point nor a group of three is the
    // signature of the wrong dialect, not of a thousand.
    expect(fn (): int => (new GenericCsvAmountParser)->parseMinor('2500.00', ','))
        ->toThrow(InvalidAmountException::class);
});

it('still reads a group of three in the separator the preset does declare', function (): void {
    expect((new GenericCsvAmountParser)->parseMinor('1.234.567,89', ','))->toBe(123456789);
    expect((new GenericCsvAmountParser)->parseMinor('1,234,567.89', '.'))->toBe(123456789);
});

it('still reads the shipped ING export unchanged', function (): void {
    $body = CsvHandedToTheApp::ING_HEADER."\n"
        .'"20260501","Albert Heijn","NL91ABNA0417164300","NL57ASNB0123456789","BA","Af","23,45","Betaalautomaat","Pasvolgnr 001"'."\n"
        .'"20260503","Werkgever","NL91ABNA0417164300","DE89370400440532013000","OV","Bij","2.500,00","Overschrijving","Salaris mei"'."\n";

    $rows = CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::ING_NL, $body);

    expect($rows)->toHaveCount(2);
    expect($rows[0]->amountMinor)->toBe(-2345);
    expect($rows[1]->amountMinor)->toBe(250000);
});
