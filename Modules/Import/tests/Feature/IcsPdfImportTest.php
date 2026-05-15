<?php

declare(strict_types=1);

/*
 * Failing scaffolds for the ICS PDF import wire-level path. The first
 * seven cases are driven Green by plan 03-02 (adapter + extractor +
 * registry wiring); the trailing two ("name your account" wizard
 * branches) are driven Green by plan 03-03.
 */

it('imports every parsed row from the redacted .txt fixture on the first run via the test-double extractor', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('returns zero new rows when re-importing the same SHA-256 tiny PDF', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('returns zero new rows when re-importing a different SHA but identical content', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('persists settled_amount_minor and settled_currency for an EUR-native row', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('persists native + settled + fx_rate_used for a foreign-currency row', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('persists rawPayload.format = ics-pdf and a non-empty extractedText per row', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('never persists card-number text into transactions.raw_payload', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('prompts the user to name the ICS Account on the first ICS upload', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-03');
})->group('phase-3');

it('skips the name-your-account step on subsequent ICS uploads', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-03');
})->group('phase-3');
