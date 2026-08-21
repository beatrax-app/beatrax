<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Public\Exceptions\PdfExtractionFailed;

function tinyPdfPathForExtractorTest(): string
{
    return base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');
}

it('returns the extracted text via the spatie/pdf-to-text wrapper', function (): void {
    $extractor = new PdfTextExtractor;

    $text = $extractor->extract(tinyPdfPathForExtractorTest());

    expect($text)->toBeString();
    expect($text)->not->toBe('');
    expect($text)->toContain('SYNTHETIC');
})->group('phase-3');

it('throws PdfExtractionFailed when the binary is missing or non-zero exits', function (): void {
    $extractor = new PdfTextExtractor('/usr/bin/this-binary-does-not-exist');

    expect(fn () => $extractor->extract(tinyPdfPathForExtractorTest()))
        ->toThrow(PdfExtractionFailed::class);
})->group('phase-3');

it('applies the locked flag set: -layout -enc UTF-8 -eol unix -nopgbrk', function (): void {
    // The flags live in a private constant, so reading the source is the only
    // way to gate them without running the binary; the integration smoke test
    // covers the behavioural half.
    $source = file_get_contents(base_path('Modules/Ingestion/Internal/Adapters/Ics/PdfTextExtractor.php'));
    if ($source === false) {
        throw new RuntimeException('Could not read PdfTextExtractor source.');
    }

    expect($source)->toContain("'layout'");
    expect($source)->toContain("'enc UTF-8'");
    expect($source)->toContain("'eol unix'");
    expect($source)->toContain("'nopgbrk'");
})->group('phase-3');

it('escapes the path argument before exec to defend against shell injection', function (): void {
    // The space, semicolon and ampersand in the path below would all be live
    // in a shell string. Symfony Process passes an argv array instead, so a
    // regression to shell-string invocation either fails or extracts the
    // wrong thing.
    $tinyPdfPath = tinyPdfPathForExtractorTest();
    $tmpDir = sys_get_temp_dir();
    $hostilePath = $tmpDir.'/ics test;space&hostile.pdf';

    $bytes = file_get_contents($tinyPdfPath);
    if ($bytes === false) {
        throw new RuntimeException('Could not read tiny synthetic PDF fixture.');
    }
    file_put_contents($hostilePath, $bytes);
    try {
        $extractor = new PdfTextExtractor;
        $text = $extractor->extract($hostilePath);

        expect($text)->toContain('SYNTHETIC');
    } finally {
        @unlink($hostilePath);
    }
})->group('phase-3');
