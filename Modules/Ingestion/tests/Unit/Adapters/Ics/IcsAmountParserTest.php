<?php

declare(strict_types=1);

/*
 * Failing scaffolds for the nl_NL amount parser used by the ICS PDF
 * adapter. Driven Green by plan 03-02. Empirical formats observed in
 * the redacted fixture: comma decimal separator (`50,00`), period
 * thousands separator (`1.416,50`), `€ ` prefix for EUR amounts, ISO
 * code suffix for foreign-currency amounts (`50,00 USD`).
 */

it('parses a positive EUR amount with comma decimal: € 22,75 → 2275', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('parses a negative EUR amount written with a minus prefix: -€ 22,75 → -2275', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('parses a USD amount with the ISO symbol: $ 12,99 → 1299', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('rejects a malformed amount string by throwing InvalidAmountException', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('does not mutate global locale state', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');
