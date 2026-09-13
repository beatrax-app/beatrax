<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAmountParser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;

dataset('minus glyphs', [
    'U+2212 MINUS SIGN' => "\u{2212}",
    'U+2013 EN DASH' => "\u{2013}",
    'U+2014 EM DASH' => "\u{2014}",
    'U+FE63 SMALL HYPHEN-MINUS' => "\u{FE63}",
    'U+FF0D FULLWIDTH HYPHEN-MINUS' => "\u{FF0D}",
    'ASCII hyphen-minus' => '-',
]);

// The old parser stripped every character outside [0-9<sep>+-], and each of
// these glyphs fell into that strip, so the payment arrived as a credit.
it('reads a typographic minus as a minus, not as decoration to be dropped', function (string $glyph): void {
    expect((new GenericCsvAmountParser)->parseMinor($glyph.'12.50', '.'))->toBe(-1250);
})->with('minus glyphs');

it('reads a typographic minus in the comma dialect too', function (string $glyph): void {
    expect((new GenericCsvAmountParser)->parseMinor($glyph.'1.234,56', ','))->toBe(-123456);
})->with('minus glyphs');

it('leaves an unsigned figure positive', function (): void {
    expect((new GenericCsvAmountParser)->parseMinor('12.50', '.'))->toBe(1250);
});

dataset('signs the parser cannot place', ['12.50-', '12.50DR', '12.50 CR', '1e3']);

// A trailing marker carries direction in some exports and no shipped preset
// declares one, so the figure is refused rather than guessed at. Before, the
// letters were stripped and a debit of 12.50 was booked as a credit of it.
it('refuses a sign it cannot place rather than dropping it', function (string $cell): void {
    expect(fn (): int => (new GenericCsvAmountParser)->parseMinor($cell, '.'))
        ->toThrow(InvalidAmountException::class);
})->with('signs the parser cannot place');
