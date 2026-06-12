<?php

declare(strict_types=1);

/*
 * Feature tests for Tax settings — country code configuration and
 * deduction category management.
 *
 * Status: RED — skipped. Full implementation ships in Plan 04.
 *
 * These tests encode the requirements for TAX-03 (settings) so Plan 04
 * can implement against this spec.
 */

it('renders the Tax settings page for an authenticated user', function (): void {
    // TODO Plan 04: actingAs + get the settings page → assertOk.
})->skip('built in Plan 04');

it('saves the tax_country_code preference for the user', function (): void {
    // TODO Plan 04: post/update country code NL; assert users.tax_country_code=NL.
})->skip('built in Plan 04');

it('seeding the corpus for a new country code creates the expected categories', function (): void {
    // TODO Plan 04: set country NL and trigger corpus seed; assert NL categories exist.
})->skip('built in Plan 04');

it('the user can create a custom deduction category', function (): void {
    // TODO Plan 04: create category via settings action; assert row in DB.
})->skip('built in Plan 04');

it('the user can delete a custom deduction category', function (): void {
    // TODO Plan 04: create then delete category; assert row deleted.
})->skip('built in Plan 04');

it('deleting a category nullifies the category id on existing tags (nullOnDelete)', function (): void {
    // TODO Plan 04: seed a tag with a category, delete the category, assert
    // tax_transaction_tags.deduction_category_id is NULL (not deleted).
})->skip('built in Plan 04');
