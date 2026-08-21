<?php

declare(strict_types=1);

use Modules\Ledger\Public\ValueObjects\Money;

it('formats EUR in the Dutch convention', function (): void {
    $formatted = Money::ofMinor(6886, 'EUR')->format();

    expect($formatted)->toContain('€');
    expect($formatted)->toContain('68,86');
})->group('phase-3');

it('formats USD in the US English convention', function (): void {
    $formatted = Money::ofMinor(7443, 'USD')->format();

    expect($formatted)->toContain('$');
    expect($formatted)->toContain('74.43');
})->group('phase-3');

it('formats negative USD with leading minus', function (): void {
    $formatted = Money::ofMinor(-7443, 'USD')->format();

    expect($formatted)->toContain('-');
    expect($formatted)->toContain('74.43');
    // Negatives never render in parentheses.
    expect($formatted)->not->toContain('(');
    expect($formatted)->not->toContain(')');
})->group('phase-3');

it('never renders a non-EUR amount in Dutch separators', function (): void {
    // Why format() takes no locale: nl_NL renders a dollar amount as
    // "US$ -1.245,67", which is not how a card statement reads in any language.
    expect(Money::ofMinor(-124567, 'USD')->format())->toBe('-$1,245.67');
    expect(Money::ofMinor(98750, 'GBP')->format())->toBe('£987.50');
})->group('phase-3');

it('renders EUR in the Dutch convention without ICU', function (): void {
    expect(Money::ofMinor(123456, 'EUR')->formatWithoutIcu())->toBe("€\u{00A0}1.234,56");
})->group('phase-3');

it('signs the digits rather than the symbol for EUR without ICU', function (): void {
    expect(Money::ofMinor(-123450, 'EUR')->formatWithoutIcu())->toBe("€\u{00A0}-1.234,50");
})->group('phase-3');

it('renders non-EUR in the US English convention without ICU', function (): void {
    expect(Money::ofMinor(-7443, 'USD')->formatWithoutIcu())->toBe('-$74.43');
})->group('phase-3');

it('falls back to the currency code for a currency with no symbol', function (): void {
    expect(Money::ofMinor(385000, 'CHF')->formatWithoutIcu())->toBe("CHF\u{00A0}3,850.00");
})->group('phase-3');

it('keeps the two-decimal scale without ICU', function (): void {
    // A money column that sometimes shows decimals is harder to scan.
    expect(Money::ofMinor(1200, 'EUR')->formatWithoutIcu())->toBe("€\u{00A0}12,00");
})->group('phase-3');

it('renders identically with and without ICU', function (int $minor, string $currency): void {
    // The host has full ICU data, so format() takes the ICU path while the
    // fallback takes the transcribed one; mobile must not read differently.
    expect(Money::ofMinor($minor, $currency)->formatWithoutIcu())
        ->toBe(Money::ofMinor($minor, $currency)->format());
})->with([
    [123456, 'EUR'],
    [-123450, 'EUR'],
    [5, 'EUR'],
    [123456789, 'EUR'],
    [-7443, 'USD'],
    [123450, 'GBP'],
    [385000, 'CHF'],
])->group('phase-3');

it('uses ICU when ICU is there rather than always taking the fallback', function (): void {
    // Guards the catch against widening into a silent swallow: the paths agree
    // on every currency the product deals in, so the proof needs one they
    // cannot — ICU knows the rupee sign, the transcribed table does not.
    expect(Money::ofMinor(123456, 'INR')->format())->toBe('₹1,234.56');
    expect(Money::ofMinor(123456, 'INR')->formatWithoutIcu())->toBe("INR\u{00A0}1,234.56");
})->group('phase-3');
