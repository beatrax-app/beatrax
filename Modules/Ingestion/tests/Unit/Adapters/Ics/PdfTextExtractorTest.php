<?php

declare(strict_types=1);

/*
 * Failing scaffolds for the exec() boundary that wraps `pdftotext`.
 * Driven Green by plan 03-02 (PdfTextExtractor service + the
 * spatie/pdf-to-text wrapper).
 */

it('returns the extracted text via the spatie/pdf-to-text wrapper', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('throws PdfExtractionFailed when the binary is missing or non-zero exits', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('applies the locked flag set: -layout -enc UTF-8 -eol unix -nopgbrk', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('escapes the path argument before exec to defend against shell injection', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');
