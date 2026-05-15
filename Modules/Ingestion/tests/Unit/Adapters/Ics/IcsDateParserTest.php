<?php

declare(strict_types=1);

/*
 * Failing scaffolds for the nl_NL date parser used by the ICS PDF
 * adapter. Driven Green by plan 03-02. Empirical formats observed in
 * the redacted fixture: `dd MMM.` (abbreviated month with trailing
 * period, e.g. `23 jan.`, `01 feb.`) on transaction lines and `dd
 * MMMM YYYY` (full month + year, e.g. `15 februari 2026`) on the
 * statement header.
 */

it('parses the dd-mm-yyyy Dutch numeric format', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('parses the j M Y abbreviated-month format with Dutch abbreviations (mei, mrt)', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('rejects an invalid date string by throwing InvalidAmountException', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('returns a CarbonImmutable at startOfDay (00:00:00) to match the fingerprint composer', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('does not mutate global locale state', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');
