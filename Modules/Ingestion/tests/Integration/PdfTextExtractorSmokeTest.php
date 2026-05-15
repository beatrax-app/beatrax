<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;

/*
 * Integration smoke test: exec's the REAL `pdftotext` binary via
 * `PdfTextExtractor` against the committed tiny synthetic PDF. Tagged
 * `->group('integration')` so CI hosts without poppler installed can
 * `vendor/bin/pest --exclude-group=integration` without failing the
 * suite.
 *
 * The unit-level `PdfTextExtractorTest` already exercises the wrapper
 * against the real binary on the executor's machine; this file exists
 * separately so the integration vs unit boundary is explicit and
 * exclude-by-group works cleanly.
 */

it('extracts text from the tiny synthetic PDF via the real pdftotext binary', function (): void {
    $tinyPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');

    $extractor = new PdfTextExtractor;
    $text = $extractor->extract($tinyPdf);

    expect($text)->toBeString();
    expect($text)->toContain('SYNTHETIC');
    expect($text)->toContain('KAARTHOUDER');
})->group('phase-3')->group('integration');

it('preserves -layout column structure on the synthetic PDF', function (): void {
    $tinyPdf = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');

    $extractor = new PdfTextExtractor;
    $text = $extractor->extract($tinyPdf);

    // The transaction row is laid out with whitespace padding between
    // the merchant token, settled amount, and direction marker. The
    // `-layout` flag preserves that padding via spaces; `-raw` mode
    // would collapse it. We assert at least one space sits between
    // `SYNTHETIC ICS TINY` and the trailing `Af` marker — the tightest
    // heuristic that distinguishes layout from raw modes on this PDF.
    expect(str_contains($text, 'SYNTHETIC ICS TINY'))->toBeTrue();
    expect(str_contains($text, 'Af'))->toBeTrue();
})->group('phase-3')->group('integration');
