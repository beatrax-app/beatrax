<?php

declare(strict_types=1);

/*
 * Feature tests for TaxPdfRenderer — the PDF export for a tax year.
 *
 * Status: RED — skipped. Full implementation ships in Plan 03.
 *
 * These tests encode the requirements for TAX-02 (PDF export) so Plan 03
 * can implement against this spec.
 */

it('generates a PDF response with Content-Type application/pdf', function (): void {
    // TODO Plan 03: request /tax/export/{year}/pdf; assert Content-Type.
})->skip('built in Plan 03');

it('PDF contains the tax year in the document title or header', function (): void {
    // TODO Plan 03: render PDF, assert year appears in title or intro.
})->skip('built in Plan 03');

it('PDF contains one section per deduction category with a subtotal', function (): void {
    // TODO Plan 03: seed tagged transactions in two categories; render PDF;
    // assert both category names and their subtotals are present.
})->skip('built in Plan 03');

it('PDF is accessible only to the authenticated owner', function (): void {
    // TODO Plan 03: actingAs(partner) requesting owner's PDF year → 404.
})->skip('built in Plan 03');
