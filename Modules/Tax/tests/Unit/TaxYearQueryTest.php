<?php

declare(strict_types=1);

use Modules\Tax\Public\Dto\TaxYearData;
use Modules\Tax\Public\Services\TaxYearQuery;

/*
 * Unit tests for TaxYearQuery — the grouped tax-year query service.
 *
 * Status: RED — skipped. Full implementation ships in Plan 02.
 *
 * These tests encode the behaviour requirements for TAX-01 (year summary)
 * and TAX-02 (category grouping) so that Plan 02 can drive its implementation
 * against this spec rather than discovering it from scratch.
 */

it('returns a TaxYearSummary list for all years the user has tags', function (): void {
    // TODO Plan 02: seed tagged transactions across two years, assert two summaries.
})->skip('built in Plan 02');

it('sums expense-type tagged transactions into deductionsTotalMinor', function (): void {
    // TODO Plan 02: seed expense-tagged + income-tagged; assert deductionsTotalMinor
    // contains only expense settled_amount_minor sum.
})->skip('built in Plan 02');

it('sums income-type tagged transactions into incomeTotalMinor', function (): void {
    // TODO Plan 02: seed income-tagged transactions; assert incomeTotalMinor sum.
})->skip('built in Plan 02');

it('groups rows into categories with subtotals', function (): void {
    // TODO Plan 02: seed two tagged transactions in different categories;
    // assert TaxYearData::$categories is grouped by category with subtotals.
})->skip('built in Plan 02');

it('applies COALESCE tax_year_override when determining the tax year for a row', function (): void {
    // TODO Plan 02: tag a transaction with tax_year_override=2024 on a 2026-booked
    // transaction; assert it appears in the 2024 tax year data, not 2026.
})->skip('built in Plan 02');

it('returns empty TaxYearData when the user has no tags for a year', function (): void {
    // TODO Plan 02: request year with no tags; expect itemCount=0, empty categories.
})->skip('built in Plan 02');

it('returns a TaxYearData DTO with the correct DTO contract shape', function (): void {
    // TODO Plan 02: assert the returned TaxYearData matches the interfaces block
    // from Plan 01 (field names, nullability, types).
})->skip('built in Plan 02');
