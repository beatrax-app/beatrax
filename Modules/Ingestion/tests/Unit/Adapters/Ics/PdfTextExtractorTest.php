<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Public\Exceptions\PdfExtractionFailed;

/*
 * Wire-level coverage for the exec() boundary that wraps `pdftotext` via
 * spatie/pdf-to-text. The four cases exercise: real-binary extraction
 * against the tiny synthetic PDF; typed exception when the binary path
 * is bogus; the locked flag set is applied uniformly; and a path
 * containing characters that would be unsafe in a shell string still
 * extracts cleanly (Symfony Process argv-array escaping verified
 * end-to-end).
 */

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
    // Source-level invariant: the extractor wires the four flags via a
    // private constant `PDFTOTEXT_OPTIONS`. Re-extracting them from the
    // source file is the cheapest correct gate; the integration smoke
    // test (Modules/Ingestion/tests/Integration/PdfTextExtractorSmokeTest.php)
    // exercises the real `pdftotext` binary end-to-end against the same
    // flag set.
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
    // Symfony Process invokes the binary with an argv array — each
    // argument is escaped before the syscall. We verify by copying the
    // tiny PDF to a path containing characters that would be unsafe in
    // a shell-string context (space, semicolon, ampersand) and asserting
    // the extraction still returns the SYNTHETIC anchor. A shell-string
    // injection regression would either fail to extract OR return text
    // from an unintended invocation.
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
