<?php

declare(strict_types=1);

/*
 * Feature tests for the TaxPage Livewire component.
 *
 * Status: RED — skipped. Full implementation ships in Plan 05.
 *
 * These tests encode the requirements for TAX-01 (page render, year
 * switching) so Plan 05 can implement against this spec.
 */

it('renders the /tax page for an authenticated user', function (): void {
    // TODO Plan 05: actingAs + get('/tax') → assertOk.
})->skip('built in Plan 05');

it('shows the current tax year by default', function (): void {
    // TODO Plan 05: assert the active year heading matches the current year.
})->skip('built in Plan 05');

it('switches to a different year when the year selector is changed', function (): void {
    // TODO Plan 05: seed tagged transactions in 2024 and 2025; switch to 2024;
    // assert the 2024 totals are displayed.
})->skip('built in Plan 05');

it('shows tagged transactions grouped by deduction category', function (): void {
    // TODO Plan 05: seed tagged transactions; assert category groups visible.
})->skip('built in Plan 05');

it('redirects unauthenticated requests to the login page', function (): void {
    // TODO Plan 05: get('/tax') without authentication → redirect to /login.
})->skip('built in Plan 05');
