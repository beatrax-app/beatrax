<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Dto\CsvPreset;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Tests\Support\CsvHandedToTheApp;

// CsvPreset::DEBIT_CREDIT is a documented amount strategy that no shipped
// preset uses yet, so none of its branches had ever been run. It names
// ing-nl-csv so the sniff has a registered preset to check the body against.
function debitCreditPreset(): CsvPreset
{
    return new CsvPreset(
        format: CsvPresetRegistry::ING_NL,
        label: 'Two-column export',
        headerSignature: ['Datum'],
        dateHeader: 'Datum',
        dateFormat: 'Ymd',
        amountStrategy: CsvPreset::DEBIT_CREDIT,
        decimalSeparator: ',',
        delimiter: ',',
        descriptionHeaders: ['Mededelingen'],
        debitHeader: 'Debet',
        creditHeader: 'Credit',
        counterpartyNameHeader: 'Naam/Omschrijving',
        ownIbanHeader: 'Rekening',
    );
}

function debitCreditRow(string $debit, string $credit): string
{
    return '"Datum","Naam/Omschrijving","Rekening","Tegenrekening","Code","Af Bij","Bedrag (EUR)","MutatieSoort","Mededelingen","Debet","Credit"'."\n"
        .'"20260501","Albert Heijn","NL91ABNA0417164300","NL57ASNB0123456789","BA","Af","23,45","Betaalautomaat","Pasvolgnr 001","'.$debit.'","'.$credit.'"'."\n";
}

// Half the two-column exports in the wild write 0,00 in the column the row did
// not move through rather than leaving it blank, and the reader that took the
// first non-empty column booked every one of those rows as nothing.
it('reads the figure off the column that is not zero', function (): void {
    $rows = CsvHandedToTheApp::parsedThroughPreset(debitCreditPreset(), debitCreditRow('0,00', '23,45'));

    // Before: 0.
    expect($rows[0]->amountMinor)->toBe(2345);
});

it('reads a debit beside a zeroed credit column', function (): void {
    $rows = CsvHandedToTheApp::parsedThroughPreset(debitCreditPreset(), debitCreditRow('23,45', '0,00'));

    expect($rows[0]->amountMinor)->toBe(-2345);
});

it('signs a blank-sided row by the column that holds the figure', function (): void {
    expect(CsvHandedToTheApp::parsedThroughPreset(debitCreditPreset(), debitCreditRow('23,45', ''))[0]->amountMinor)->toBe(-2345);
    expect(CsvHandedToTheApp::parsedThroughPreset(debitCreditPreset(), debitCreditRow('', '23,45'))[0]->amountMinor)->toBe(2345);
});

it('takes a magnitude off either column whichever sign it was written with', function (): void {
    expect(CsvHandedToTheApp::parsedThroughPreset(debitCreditPreset(), debitCreditRow('-23,45', ''))[0]->amountMinor)->toBe(-2345);
});

it('keeps a row that really is zero on both sides', function (): void {
    expect(CsvHandedToTheApp::parsedThroughPreset(debitCreditPreset(), debitCreditRow('0,00', '0,00'))[0]->amountMinor)->toBe(0);
});

it('refuses a row with no figure in either column', function (): void {
    expect(fn (): array => CsvHandedToTheApp::parsedThroughPreset(debitCreditPreset(), debitCreditRow('', '')))
        ->toThrow(InvalidAmountException::class, 'Both debit and credit columns are empty.');
});

// Before, the debit branch simply won and the credit went unread.
it('refuses a row stating a debit and a credit at once', function (): void {
    expect(fn (): array => CsvHandedToTheApp::parsedThroughPreset(debitCreditPreset(), debitCreditRow('23,45', '10,00')))
        ->toThrow(InvalidAmountException::class, "Row states a debit of '23,45' and a credit of '10,00' at once.");
});

dataset('unrecognised directions', ['', 'Foo', 'Af Bij']);

// The indicator strategy the ING preset does use: an empty or unknown
// direction cell is refused rather than falling through to a credit.
it('refuses a direction indicator it does not recognise', function (string $indicator): void {
    $body = CsvHandedToTheApp::ING_HEADER."\n"
        .'"20260501","Albert Heijn","NL91ABNA0417164300","NL57ASNB0123456789","BA","'.$indicator.'","23,45","Betaalautomaat","Pasvolgnr 001"'."\n";

    expect(fn (): array => CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::ING_NL, $body))
        ->toThrow(InvalidAmountException::class, "Unrecognised direction indicator '".$indicator."' (expected 'Af' or 'Bij').");
})->with('unrecognised directions');

it('reads the direction indicator in whichever case the export wrote it', function (): void {
    $body = fn (string $i): string => CsvHandedToTheApp::ING_HEADER."\n"
        .'"20260501","Albert Heijn","NL91ABNA0417164300","NL57ASNB0123456789","BA","'.$i.'","23,45","Betaalautomaat","Pasvolgnr 001"'."\n";

    expect(CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::ING_NL, $body('AF'))[0]->amountMinor)->toBe(-2345);
    expect(CsvHandedToTheApp::parsedThrough(CsvPresetRegistry::ING_NL, $body('bij'))[0]->amountMinor)->toBe(2345);
});
