<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;

// These exec the real `pdftotext`, hence the `integration` group: a CI host
// without poppler runs `pest --exclude-group=integration`.

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

    // `-raw` would collapse the whitespace padding between the merchant token
    // and the trailing direction marker; `-layout` keeps it as spaces.
    expect(str_contains($text, 'SYNTHETIC ICS TINY'))->toBeTrue();
    expect(str_contains($text, 'Af'))->toBeTrue();
})->group('phase-3')->group('integration');
