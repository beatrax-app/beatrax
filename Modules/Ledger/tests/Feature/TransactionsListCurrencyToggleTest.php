<?php

declare(strict_types=1);

/*
 * Failing scaffolds for the `/transactions` currency-view toggle UX.
 * Driven Green by plan 03-05. Covers MC-02 list half and UI-06 dual-
 * currency two-line stack.
 */

it('defaults the currency property to the user default_currency_view preference when no URL override is present', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-05');
})->group('phase-3');

it('overrides the user preference when ?currency=eur is present in the URL', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-05');
})->group('phase-3');

it('overrides the user preference when ?currency=original is present in the URL', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-05');
})->group('phase-3');

it('renders two lines for a foreign-currency row in original mode', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-05');
})->group('phase-3');

it('renders one line for a foreign-currency row in eur mode', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-05');
})->group('phase-3');

it('renders one line for an EUR-native row in original mode', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-05');
})->group('phase-3');

it('keeps the URL clean when the toggle is on the default value', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-05');
})->group('phase-3');
