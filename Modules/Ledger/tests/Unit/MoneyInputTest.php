<?php

declare(strict_types=1);

use Modules\Ledger\Public\ValueObjects\MoneyInput;

it('parses a plain dot-decimal amount to minor units', function (): void {
    expect(MoneyInput::tryToMinor('12.50'))->toBe(1250);
});

it('parses a comma-decimal amount to minor units', function (): void {
    expect(MoneyInput::tryToMinor('12,50'))->toBe(1250);
});

it('parses a Dutch-grouped amount, the rightmost separator being the decimal', function (): void {
    expect(MoneyInput::tryToMinor('1.234,56'))->toBe(123456);
    expect(MoneyInput::tryToMinor('1,234.56'))->toBe(123456);
});

it('honours a leading minus for a negative amount', function (): void {
    expect(MoneyInput::tryToMinor('-50,00'))->toBe(-5000);
});

it('treats whitespace (including non-breaking) as insignificant', function (): void {
    expect(MoneyInput::tryToMinor(" 12,50\u{00A0}"))->toBe(1250);
});

it('pads a single-decimal fraction to two places', function (): void {
    expect(MoneyInput::tryToMinor('12,5'))->toBe(1250);
});

it('parses a whole number without a decimal separator', function (): void {
    expect(MoneyInput::tryToMinor('40'))->toBe(4000);
});

it('returns null for an empty or blank string', function (): void {
    expect(MoneyInput::tryToMinor(''))->toBeNull();
    expect(MoneyInput::tryToMinor('   '))->toBeNull();
});

it('returns null for a non-numeric or over-precise amount', function (string $bad): void {
    expect(MoneyInput::tryToMinor($bad))->toBeNull();
})->with(['abc', '12.345', '1.2.3', '12,50,00', '--5', '10-']);

it('formats signed minor units to the plain editable form', function (): void {
    expect(MoneyInput::formatMinor(-5000))->toBe('-50,00');
    expect(MoneyInput::formatMinor(1250))->toBe('12,50');
    expect(MoneyInput::formatMinor(5))->toBe('0,05');
});

it('formats the magnitude only, dropping the sign', function (): void {
    expect(MoneyInput::formatAbsMinor(-5000))->toBe('50,00');
    expect(MoneyInput::formatAbsMinor(0))->toBe('0,00');
});

it('round-trips format then parse back to the same minor units', function (int $minor): void {
    expect(MoneyInput::tryToMinor(MoneyInput::formatMinor($minor)))->toBe($minor);
})->with([0, 5, 1250, -5000, 123456, -1]);
