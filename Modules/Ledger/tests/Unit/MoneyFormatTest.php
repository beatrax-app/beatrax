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
