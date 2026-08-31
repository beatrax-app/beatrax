<?php

declare(strict_types=1);

use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Support\AmountStringParser;
use Modules\Ledger\Public\Enums\Currency;

// The inline parsers this replaced stripped every dot, so a US-style "12.50"
// read as 1250 and became 125000 minor — €1,250 charged instead of €12.50.
// The two-decimal assumption that replaced them was a second bug of the same
// shape: it is a property of the euro, never of the parser, so every case here
// names the currency it is asserting about.

it('parses a US-style decimal "12.50" as 1250 minor units in a two-decimal currency', function (): void {
    expect(AmountStringParser::toMinor('12.50', Currency::Eur->value))->toBe(1250);
});

it('parses a NL-style decimal "12,50" as 1250 minor units in a two-decimal currency', function (): void {
    expect(AmountStringParser::toMinor('12,50', Currency::Eur->value))->toBe(1250);
});

it('parses a US-style thousands+decimal "1,234.56" as 123456 minor units', function (): void {
    expect(AmountStringParser::toMinor('1,234.56', Currency::Eur->value))->toBe(123456);
});

it('parses a NL-style thousands+decimal "1.234,56" as 123456 minor units', function (): void {
    expect(AmountStringParser::toMinor('1.234,56', Currency::Eur->value))->toBe(123456);
});

it('parses an integer string "1234" at the currency\'s own scale, not a fixed hundred', function (): void {
    expect(AmountStringParser::toMinor('1234', Currency::Eur->value))->toBe(123400)
        ->and(AmountStringParser::toMinor('1234', Currency::Jpy->value))->toBe(1234);
});

// The shipped symptom: typing 5000 into a field prefixed ¥ stored ¥500,000.
it('stores what was typed into a yen box rather than a hundred times it', function (): void {
    expect(AmountStringParser::toMinor('5000', Currency::Jpy->value))->toBe(5000);
});

it('groups a yen figure without reading the group mark as a decimal point', function (): void {
    expect(AmountStringParser::toMinor('1.234', Currency::Jpy->value))->toBe(1234)
        ->and(AmountStringParser::toMinor('1,234', Currency::Jpy->value))->toBe(1234);
});

it('refuses a fractional yen, which the currency has no unit for', function (): void {
    expect(fn () => AmountStringParser::toMinor('12.50', Currency::Jpy->value))
        ->toThrow(InvalidArgumentException::class, Lang::get('forecasting::forecast.errors.amount_whole'));
});

it('parses a negative US-style decimal "-12.50" as -1250 minor units', function (): void {
    expect(AmountStringParser::toMinor('-12.50', Currency::Eur->value))->toBe(-1250);
});

it('parses a negative NL-style decimal "-12,50" as -1250 minor units', function (): void {
    expect(AmountStringParser::toMinor('-12,50', Currency::Eur->value))->toBe(-1250);
});

it('strips embedded spaces ("1 234,56" → 123456)', function (): void {
    expect(AmountStringParser::toMinor('1 234,56', Currency::Eur->value))->toBe(123456);
});

it('accepts a leading plus sign ("+12,50" → 1250)', function (): void {
    expect(AmountStringParser::toMinor('+12,50', Currency::Eur->value))->toBe(1250);
});

it('throws on empty input', function (): void {
    expect(fn () => AmountStringParser::toMinor('', Currency::Eur->value))
        ->toThrow(InvalidArgumentException::class, Lang::get('forecasting::forecast.errors.amount_required'));
});

it('throws on whitespace-only input', function (): void {
    expect(fn () => AmountStringParser::toMinor('   ', Currency::Eur->value))
        ->toThrow(InvalidArgumentException::class, Lang::get('forecasting::forecast.errors.amount_required'));
});

it('throws on non-numeric garbage, naming the decimals the currency has', function (): void {
    expect(fn () => AmountStringParser::toMinor('abc', Currency::Eur->value))
        ->toThrow(
            InvalidArgumentException::class,
            Lang::choice('forecasting::forecast.errors.amount_decimals', 2, ['decimals' => 2]),
        );
});

it('rejects a negative value when allowNegative=false', function (): void {
    expect(fn () => AmountStringParser::toMinor('-1,50', Currency::Eur->value, allowNegative: false))
        ->toThrow(InvalidArgumentException::class, Lang::get('forecasting::forecast.errors.amount_non_negative'));
});

it('rejects a zero value when requireNonZero=true', function (): void {
    expect(fn () => AmountStringParser::toMinor('0', Currency::Eur->value, requireNonZero: true))
        ->toThrow(InvalidArgumentException::class, Lang::get('forecasting::forecast.errors.amount_non_zero'));
});

it('accepts zero by default', function (): void {
    expect(AmountStringParser::toMinor('0', Currency::Eur->value))->toBe(0);
});

it('refuses a third decimal rather than rounding it away', function (): void {
    // "12.345" used to become 1235 minor. Silently deciding which of two
    // amounts the typist meant is worse than asking, and no other amount
    // input in the app does it.
    expect(fn () => AmountStringParser::toMinor('12.345', Currency::Eur->value))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses scientific notation, which is_numeric() used to wave through', function (): void {
    // "1e3" parsed as €1,000 here and as nothing anywhere else.
    expect(fn () => AmountStringParser::toMinor('1e3', Currency::Eur->value))
        ->toThrow(InvalidArgumentException::class);
});

it('reports every message in the reader\'s own language', function (): void {
    app()->setLocale('nl');

    expect(fn () => AmountStringParser::toMinor('', Currency::Eur->value))
        ->toThrow(InvalidArgumentException::class, 'Bedrag is verplicht.');
});
