<?php

declare(strict_types=1);

/*
 * Failing scaffolds for the new minimal Settings page. Driven Green by
 * plan 03-04. Covers MC-02's storage half (the round-trip of
 * `default_currency_view` into a Livewire mode) and discharges Phase
 * 1's deferred `period_start_day` Settings surface.
 */

it('renders the Settings page with the user current preferences pre-filled', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-04');
})->group('phase-3');

it('persists default_currency_view when changed via the toggle', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-04');
})->group('phase-3');

it('persists period_start_day when changed', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-04');
})->group('phase-3');

it('rejects period_start_day outside 1..28', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-04');
})->group('phase-3');

it('rejects default_currency_view outside {eur_only, original}', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-04');
})->group('phase-3');

it('round-trips default_currency_view = original into the TransactionsList default mode', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-04');
})->group('phase-3');
