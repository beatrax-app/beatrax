<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAmountParser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

dataset('amounts', [
    // cell, decimalSeparator, expected minor
    'dot decimal' => ['1234.56', '.', 123456],
    'comma decimal, dot thousands' => ['1.234,56', ',', 123456],
    'comma decimal small' => ['-12,34', ',', -1234],
    'dot decimal, comma thousands' => ['1,234.56', '.', 123456],
    'leading plus' => ['+12.00', '.', 1200],
    'parenthesised negative' => ['(12,34)', ',', -1234],
    'rounds up on third decimal' => ['12.999', '.', 1300],
    'rounds down' => ['12.344', '.', 1234],
    'rounds with carry into euros' => ['0.999', '.', 100],
    'currency symbol stripped' => ['€ 9,99', ',', 999],
    'nbsp thousands' => ["1\u{00A0}234,50", ',', 123450],
    'whole number no decimals' => ['42', '.', 4200],
]);

it('parses amounts into signed minor units', function (string $cell, string $sep, int $expected): void {
    expect((new GenericCsvAmountParser)->parseMinor($cell, $sep))->toBe($expected);
})->with('amounts');

it('throws a domain exception on an empty cell', function (): void {
    expect(fn () => (new GenericCsvAmountParser)->parseMinor('', '.'))
        ->toThrow(InvalidAmountException::class);
});

it('throws a domain exception (not a TypeError) on an out-of-range amount', function (): void {
    expect(fn () => (new GenericCsvAmountParser)->parseMinor('99999999999999999999.99', '.'))
        ->toThrow(InvalidAmountException::class);
});

it('throws on a non-numeric cell', function (): void {
    expect(fn () => (new GenericCsvAmountParser)->parseMinor('n/a', '.'))
        ->toThrow(InvalidAmountException::class);
});

// The ceiling is MoneyInput::MAX_WHOLE_DIGITS, not a 15 written here as well.
it('accepts an integer part exactly MAX_WHOLE_DIGITS long', function (): void {
    $cell = str_repeat('1', MoneyInput::MAX_WHOLE_DIGITS).'.00';

    expect((new GenericCsvAmountParser)->parseMinor($cell, '.'))
        ->toBe((int) str_repeat('1', MoneyInput::MAX_WHOLE_DIGITS) * 100);
});

it('refuses an integer part one digit past MAX_WHOLE_DIGITS', function (): void {
    $cell = str_repeat('1', MoneyInput::MAX_WHOLE_DIGITS + 1).'.00';

    expect(fn (): int => (new GenericCsvAmountParser)->parseMinor($cell, '.'))
        ->toThrow(InvalidAmountException::class);
});
