<?php

declare(strict_types=1);

/*
 * Failing scaffolds for the `/transactions/{id}` detail-page FX rate
 * surface. Driven Green by plan 03-07. Covers UI-06 detail half — the
 * "Effective rate" `<dl>` row and the "Includes any ICS markup."
 * helper text below it.
 */

it('renders the Effective rate line when fx_rate_used is populated', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-07');
})->group('phase-3');

it('omits the Effective rate line when fx_rate_used is NULL', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-07');
})->group('phase-3');

it('formats the rate as EUR-per-unit-native with 3 decimal places (€0.929 / USD)', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-07');
})->group('phase-3');

it('renders the Includes any ICS markup helper text below the rate', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-07');
})->group('phase-3');
