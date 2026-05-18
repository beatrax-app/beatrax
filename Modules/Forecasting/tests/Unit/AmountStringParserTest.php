<?php

declare(strict_types=1);

use Modules\Forecasting\Internal\Support\AmountStringParser;

/*
 * Regression coverage for the shared "last-separator-wins" money input
 * parser. The earlier inline implementation in ScenarioEditorSidebar +
 * ModelWhatIfDropdown stripped every dot indiscriminately, so the
 * US-style decimal "12.50" silently became 1250 → 125000 minor units
 * (€1,250 instead of €12.50). These tests pin the contract for all
 * four call sites (AccountBufferEditor, OpeningBalanceEditor,
 * ScenarioEditorSidebar, ModelWhatIfDropdown).
 */

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
        ->toThrow(InvalidArgumentException::class, 'Amount must be a number.');
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

it('rounds half-up at the 2dp boundary', function (): void {
    // 12.345 → round to 1234.5 minor units → 1235 (banker's-half-to-even
    // would give 1234, but PHP round() defaults to half-away-from-zero).
    expect(AmountStringParser::toMinor('12.345'))->toBe(1235);
});
