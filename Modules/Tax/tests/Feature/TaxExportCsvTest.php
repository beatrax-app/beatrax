<?php

declare(strict_types=1);

/*
 * Feature tests for TaxCsvExporter — the CSV export for a tax year.
 *
 * Status: RED — skipped. Full implementation ships in Plan 03.
 *
 * These tests encode the requirements for TAX-02 (CSV export) so Plan 03
 * can implement against this spec.
 */

it('generates a CSV response with the correct Content-Type header', function (): void {
    // TODO Plan 03: request /tax/export/{year}/csv; assert response headers.
})->skip('built in Plan 03');

it('CSV contains one row per tagged transaction for the requested year', function (): void {
    // TODO Plan 03: seed 3 tagged transactions in 2025; assert CSV has 3 data rows.
})->skip('built in Plan 03');

it('CSV rows include category name, settled amount, and note columns', function (): void {
    // TODO Plan 03: seed a tagged transaction with note; assert CSV column values.
})->skip('built in Plan 03');

it('CSV uses COALESCE tax_year_override to assign rows to the correct year', function (): void {
    // TODO Plan 03: seed a 2026-booked transaction tagged with tax_year_override=2025;
    // assert it appears in the 2025 CSV, not the 2026 CSV.
})->skip('built in Plan 03');

it('returns a 404 response for a year with no tagged transactions', function (): void {
    // TODO Plan 03: request CSV for a year with no tags; assert 404 or empty file.
})->skip('built in Plan 03');
