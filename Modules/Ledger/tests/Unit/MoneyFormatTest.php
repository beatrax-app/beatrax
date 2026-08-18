<?php

declare(strict_types=1);

use Modules\Ledger\Public\ValueObjects\Money;

/*
 * Unit tests for the locale-aware `Money::format()` default. EUR amounts
 * render in nl_NL (Dutch — comma decimal, € with non-breaking space);
 * non-EUR amounts render in en_US (US English — period decimal, symbol
 * prefix). An explicit locale argument overrides the auto-selection.
 */

it('formats EUR with nl_NL locale by default', function (): void {
    $formatted = Money::ofMinor(6886, 'EUR')->format();

    expect($formatted)->toContain('€');
    expect($formatted)->toContain('68,86');
})->group('phase-3');

it('formats USD with en_US locale by default', function (): void {
    $formatted = Money::ofMinor(7443, 'USD')->format();

    expect($formatted)->toContain('$');
    expect($formatted)->toContain('74.43');
})->group('phase-3');

it('formats negative USD with leading minus', function (): void {
    $formatted = Money::ofMinor(-7443, 'USD')->format();

    expect($formatted)->toContain('-');
    expect($formatted)->toContain('74.43');
    // Calm-aesthetic guard: never parentheses for negatives.
    expect($formatted)->not->toContain('(');
    expect($formatted)->not->toContain(')');
})->group('phase-3');

it('honours an explicit locale argument for backward compat', function (): void {
    $eurUsLocale = Money::ofMinor(6886, 'EUR')->format('en_US');
    $eurNlLocale = Money::ofMinor(6886, 'EUR')->format('nl_NL');

    // Different locales must produce different output for the same Money;
    // backward-compat means the explicit argument wins over auto-selection.
    expect($eurUsLocale)->not->toBe($eurNlLocale);
})->group('phase-3');

/*
 * The no-ICU fallback. `formatToLocale()` builds a NumberFormatter, which the
 * mobile PHP build's ext-intl cannot always construct — its ICU ships locale
 * data for English only, and it reports the miss two ways: IntlException when
 * intl error-exceptions are enabled, ValueError from the constructor when it
 * rejects the locale outright. Every rendered amount in the product funnels
 * through format(), so an uncaught one is a 500 on any page showing money.
 * A locale ICU refuses stands in for the device condition, which cannot be
 * reproduced on a host with full ICU data.
 */

it('renders EUR in the Dutch convention without ICU', function (): void {
    expect(Money::ofMinor(123456, 'EUR')->format('xx_XX_INVALID'))->toBe("€\u{00A0}1.234,56");
})->group('phase-3');

it('signs the digits rather than the symbol for EUR without ICU', function (): void {
    expect(Money::ofMinor(-123450, 'EUR')->format('xx_XX_INVALID'))->toBe("€\u{00A0}-1.234,50");
})->group('phase-3');

it('renders non-EUR in the US English convention without ICU', function (): void {
    expect(Money::ofMinor(-7443, 'USD')->format('!!bogus!!'))->toBe('-$74.43');
})->group('phase-3');

it('falls back to the currency code for a currency with no symbol', function (): void {
    expect(Money::ofMinor(385000, 'CHF')->format('!!bogus!!'))->toBe("CHF\u{00A0}3,850.00");
})->group('phase-3');

it('keeps the two-decimal scale without ICU', function (): void {
    // Whole amounts must not render as "€ 12" — a money column that
    // sometimes shows decimals and sometimes does not is harder to scan
    // than one that always does.
    expect(Money::ofMinor(1200, 'EUR')->format('xx_XX_INVALID'))->toBe("€\u{00A0}12,00");
})->group('phase-3');

it('renders identically with and without ICU for the locales it anchors on', function (int $minor, string $currency): void {
    // The point of the fallback: mobile must not read differently from
    // desktop. This host has full ICU data, so the auto-selected locale
    // takes the ICU path and the rejected one takes the fallback.
    expect(Money::ofMinor($minor, $currency)->format('xx_XX_INVALID'))
        ->toBe(Money::ofMinor($minor, $currency)->format());
})->with([
    [123456, 'EUR'],
    [-123450, 'EUR'],
    [5, 'EUR'],
    [123456789, 'EUR'],
    [-7443, 'USD'],
    [123450, 'GBP'],
])->group('phase-3');

it('never reaches the fallback when the locale is usable', function (): void {
    // Guards the catch from widening into a silent swallow of working
    // formatting: German puts the symbol last, which the fallback — which
    // only knows the two locales this class anchors on — cannot produce.
    expect(Money::ofMinor(123450, 'EUR')->format('de_DE'))->toBe("1.234,50\u{00A0}€");
})->group('phase-3');
