<?php

declare(strict_types=1);

use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Parsers\Support\AmountStringParser;

it('AmountStringParser: matches EnvelopeWriter::parseAmount() on the shared Dutch + plain sample set', function (): void {
    $parser = new AmountStringParser;
    $envelopeWriter = app(EnvelopeWriter::class);

    $samples = [
        '45.00',
        '0.00',
        '',
        '1.234,56',
        '1234.56',
        '  20.00  ',
        '15,00',
        '100',
        'not-a-number',
        '999999999999.99',
    ];

    foreach ($samples as $sample) {
        expect($parser->parse($sample))->toBe($envelopeWriter->parseAmount($sample), "mismatch for input '{$sample}'");
    }
});

it('AmountStringParser: a blank or zero-valued cell resolves to null (never zero)', function (): void {
    $parser = new AmountStringParser;

    expect($parser->parse(''))->toBeNull();
    expect($parser->parse('0.00'))->toBeNull();
    expect($parser->parse('0,00'))->toBeNull();
});

it('AmountStringParser: parses plain and Dutch-grouped decimals to the correct minor amount', function (): void {
    $parser = new AmountStringParser;

    expect($parser->parse('45.00'))->toBe(4500);
    expect($parser->parse('45,00'))->toBe(4500);
    expect($parser->parse('1.234,56'))->toBe(123456);
    expect($parser->parse('1,234.56'))->toBe(123456);
});

// A register row states its figure in one of two columns, so the unused one is
// blank and reads as zero. What must not read as zero is a cell holding
// something: folded into 0 it imports a transaction at 0,00, which is a wrong
// amount in the ledger that nothing on screen distinguishes from a real one.
it('reads a blank and a written zero as zero, and refuses a figure it cannot read', function (string $cell, ?int $expected): void {
    $parser = new AmountStringParser;

    if ($expected === null) {
        expect(fn () => $parser->requireMinor($cell, 'Register.csv Outflow'))
            ->toThrow(UnrecognizedMigrationFileException::class);

        return;
    }

    expect($parser->requireMinor($cell, 'Register.csv Outflow'))->toBe($expected);
})->with([
    'the column this row did not use' => ['', 0],
    'a zero the file states' => ['0.00', 0],
    'a plain figure' => ['45.00', 4500],
    'a figure with surrounding space' => ['  20.00  ', 2000],
    'a cell that is not a number at all' => ['not-a-number', null],
]);

it('names the column and the cell, so the row can be found in the file', function (): void {
    $parser = new AmountStringParser;

    expect(fn () => $parser->requireMinor('twelve pounds', 'Register.csv Inflow'))
        ->toThrow(
            UnrecognizedMigrationFileException::class,
            "could not parse Register.csv Inflow value 'twelve pounds'",
        );
});
