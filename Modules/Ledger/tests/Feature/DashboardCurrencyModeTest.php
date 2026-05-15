<?php

declare(strict_types=1);

/*
 * Failing scaffolds for the dashboard GROUP-BY-currency mode. Driven
 * Green by plan 03-06. Covers MC-02 dashboard half — per-currency
 * KPI tile rows in original mode and the preserved EUR-only summary
 * path.
 */

it('returns one tile-row per currency in original mode for a mixed EUR/USD month', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-06');
})->group('phase-3');

it('collapses an EUR-only month to one tile-row in original mode', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-06');
})->group('phase-3');

it('omits zero-activity currencies from the original-mode tile rows', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-06');
})->group('phase-3');

it('returns one summary in eur_only mode (settled-EUR sum)', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-06');
})->group('phase-3');

it('orders the original-mode tile rows by settled_currency alphabetically', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-06');
})->group('phase-3');
