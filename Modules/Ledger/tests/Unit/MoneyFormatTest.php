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
 * mobile PHP build's ext-intl cannot always construct — and it reports that
 * two ways: IntlException when intl error-exceptions are enabled, ValueError
 * from the constructor when it rejects the locale outright. Every rendered
 * amount in the product funnels through format(), so an uncaught one is a
 * 500 on any page showing money. A locale ICU refuses stands in for the
 * device condition, which cannot be reproduced on a host with full ICU data.
 */

it('falls back to a plain format when the locale cannot build a NumberFormatter', function (): void {
    $formatted = Money::ofMinor(123456, 'EUR')->format('xx_XX_INVALID');

    expect($formatted)->toBe('EUR 1234.56');
})->group('phase-3');

it('keeps the fallback legible for negatives and non-EUR currencies', function (): void {
    expect(Money::ofMinor(-7443, 'USD')->format('!!bogus!!'))->toBe('USD -74.43');
})->group('phase-3');

it('pads the fallback to a fixed two-decimal scale', function (): void {
    // Whole amounts must not render as "EUR 12" — a money column that
    // sometimes shows decimals and sometimes does not is harder to scan
    // than one that always does.
    expect(Money::ofMinor(1200, 'EUR')->format('xx_XX_INVALID'))->toBe('EUR 12.00');
})->group('phase-3');

it('never reaches the fallback when the locale is usable', function (): void {
    // Guards the catch from widening into a silent swallow of working
    // formatting: a real locale must still produce localised output.
    expect(Money::ofMinor(1200, 'EUR')->format('nl_NL'))->not->toBe('EUR 12.00');
})->group('phase-3');
