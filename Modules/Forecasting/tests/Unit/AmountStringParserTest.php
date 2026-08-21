<?php

declare(strict_types=1);

use Modules\Forecasting\Internal\Support\AmountStringParser;

// The inline parsers this replaced stripped every dot, so a US-style "12.50"
// read as 1250 and became 125000 minor — €1,250 charged instead of €12.50.

it('parses a US-style decimal "12.50" as 1250 minor units', function (): void {
    expect(AmountStringParser::toMinor('12.50'))->toBe(1250);
});

it('parses a NL-style decimal "12,50" as 1250 minor units', function (): void {
    expect(AmountStringParser::toMinor('12,50'))->toBe(1250);
});

it('parses a US-style thousands+decimal "1,234.56" as 123456 minor units', function (): void {
    expect(AmountStringParser::toMinor('1,234.56'))->toBe(123456);
});

it('parses a NL-style thousands+decimal "1.234,56" as 123456 minor units', function (): void {
    expect(AmountStringParser::toMinor('1.234,56'))->toBe(123456);
});

it('parses an integer string "1234" as 123400 minor units', function (): void {
    expect(AmountStringParser::toMinor('1234'))->toBe(123400);
});

it('parses a negative US-style decimal "-12.50" as -1250 minor units', function (): void {
    expect(AmountStringParser::toMinor('-12.50'))->toBe(-1250);
});

it('parses a negative NL-style decimal "-12,50" as -1250 minor units', function (): void {
    expect(AmountStringParser::toMinor('-12,50'))->toBe(-1250);
});

it('strips embedded spaces ("1 234,56" → 123456)', function (): void {
    expect(AmountStringParser::toMinor('1 234,56'))->toBe(123456);
});

it('accepts a leading plus sign ("+12,50" → 1250)', function (): void {
    expect(AmountStringParser::toMinor('+12,50'))->toBe(1250);
});

it('throws on empty input', function (): void {
    expect(fn () => AmountStringParser::toMinor(''))
        ->toThrow(InvalidArgumentException::class, 'Amount is required.');
});

it('throws on whitespace-only input', function (): void {
    expect(fn () => AmountStringParser::toMinor('   '))
        ->toThrow(InvalidArgumentException::class, 'Amount is required.');
});

it('throws on non-numeric garbage', function (): void {
    expect(fn () => AmountStringParser::toMinor('abc'))
        ->toThrow(InvalidArgumentException::class, 'Amount must be a number with at most two decimals.');
});

it('rejects a negative value when allowNegative=false', function (): void {
    expect(fn () => AmountStringParser::toMinor('-1,50', allowNegative: false))
        ->toThrow(InvalidArgumentException::class, 'Amount must be zero or positive.');
});

it('rejects a zero value when requireNonZero=true', function (): void {
    expect(fn () => AmountStringParser::toMinor('0', requireNonZero: true))
        ->toThrow(InvalidArgumentException::class, 'Amount must be non-zero.');
});

it('accepts zero by default', function (): void {
    expect(AmountStringParser::toMinor('0'))->toBe(0);
});

it('refuses a third decimal rather than rounding it away', function (): void {
    // "12.345" used to become 1235 minor. Silently deciding which of two
    // amounts the typist meant is worse than asking, and no other amount
    // input in the app does it.
    expect(fn () => AmountStringParser::toMinor('12.345'))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses scientific notation, which is_numeric() used to wave through', function (): void {
    // "1e3" parsed as €1,000 here and as nothing anywhere else.
    expect(fn () => AmountStringParser::toMinor('1e3'))
        ->toThrow(InvalidArgumentException::class);
});
