<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextLayoutReader;
use Modules\Ingestion\Internal\Exceptions\PdfExtractionFailed;
use Modules\Ingestion\Public\Exceptions\PdfHasNoTextLayerException;
use Modules\Ingestion\Public\Exceptions\PdfPasswordProtectedException;
use Modules\Ingestion\Public\Exceptions\PdfReaderUnavailableException;

function tinyPdfPathForExtractorTest(): string
{
    return base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');
}

// A path that cannot exist is how the phone's answer is reproduced on a host
// that has poppler: PdfTextExtractor resolves the binary in one place, and both
// "nothing on PATH" and "the configured one is not executable" leave it null.
function noPdftotextExtractor(?PdfTextLayoutReader $reader = null): PdfTextExtractor
{
    return new PdfTextExtractor('/usr/bin/this-binary-does-not-exist', $reader);
}

/** @param  array<int, string>  $objects */
function pdfFromObjectsForExtractorTest(array $objects, string $extraTrailer = ''): string
{
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number." 0 obj\n".$body."\nendobj\n";
    }

    $startxref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach (array_keys($objects) as $number) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
    }

    return $pdf."trailer\n<< /Size ".(count($objects) + 1).' /Root 1 0 R'.$extraTrailer." >>\n"
        ."startxref\n".$startxref."\n%%EOF\n";
}

/** @return array<int, string> */
function drawingOnlyPdfObjectsForExtractorTest(): array
{
    $content = "0 0 1 rg 10 10 120 120 re f\n";

    return [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>',
        4 => '<< /Length '.strlen($content).' >>'."\nstream\n".$content.'endstream',
    ];
}

function writeTempPdfForExtractorTest(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'ics-edge').'.pdf';
    file_put_contents($path, $bytes);

    return $path;
}

it('returns the extracted text through whichever reader this machine has', function (): void {
    // No binary path given, so the extractor picks the reader itself — poppler
    // on a host that has it and the in-app one elsewhere. What the caller is
    // promised is the text, and that promise does not vary by platform.
    $extractor = new PdfTextExtractor;

    $text = $extractor->extract(tinyPdfPathForExtractorTest());

    expect($text)->toBeString();
    expect($text)->not->toBe('');
    expect($text)->toContain('SYNTHETIC');
})->group('phase-3');

it('reads the statement with the in-app reader when there is no binary to run', function (): void {
    // The device case. Answering it with a refusal is what made a PDF import
    // impossible on a phone, where the binary is absent and cannot be added.
    $text = noPdftotextExtractor()->extract(tinyPdfPathForExtractorTest());

    expect($text)->toContain('SYNTHETIC');
    expect($text)->toContain('KAARTHOUDER');
})->group('phase-3');

it('throws PdfReaderUnavailableException only when neither reader is present', function (): void {
    $withoutTextLayer = new class extends PdfTextLayoutReader
    {
        public function available(): bool
        {
            return false;
        }
    };

    expect(fn () => noPdftotextExtractor($withoutTextLayer)->extract(tinyPdfPathForExtractorTest()))
        ->toThrow(PdfReaderUnavailableException::class);
})->group('phase-3');

it('throws PdfExtractionFailed when the binary runs and exits non-zero', function (): void {
    $extractor = new PdfTextExtractor('/usr/bin/false');

    expect(fn () => $extractor->extract(tinyPdfPathForExtractorTest()))
        ->toThrow(PdfExtractionFailed::class);
})->group('phase-3');

it('names a drawing-only PDF for what it is, whichever reader read it', function (): void {
    // A scan reaches both readers as a page with no words on it. Reported as a
    // missing program it sends the reader after software that would change
    // nothing; reported as unreadable rows it sends them back to their bank.
    $path = writeTempPdfForExtractorTest(pdfFromObjectsForExtractorTest(drawingOnlyPdfObjectsForExtractorTest()));

    try {
        expect(fn () => (new PdfTextExtractor)->extract($path))
            ->toThrow(PdfHasNoTextLayerException::class);
        expect(fn () => noPdftotextExtractor()->extract($path))
            ->toThrow(PdfHasNoTextLayerException::class);
    } finally {
        @unlink($path);
    }
})->group('phase-3');

it('names an encrypted PDF for what it is, whichever reader read it', function (): void {
    $objects = drawingOnlyPdfObjectsForExtractorTest();
    $objects[5] = '<< /Filter /Standard /V 1 /R 2 /O <'.str_repeat('61', 32)
        .'> /U <'.str_repeat('62', 32).'> /P -1 >>';
    $trailer = ' /Encrypt 5 0 R /ID [<'.str_repeat('63', 16).'> <'.str_repeat('63', 16).'>]';
    $path = writeTempPdfForExtractorTest(pdfFromObjectsForExtractorTest($objects, $trailer));

    try {
        expect(fn () => (new PdfTextExtractor)->extract($path))
            ->toThrow(PdfPasswordProtectedException::class);
        expect(fn () => noPdftotextExtractor()->extract($path))
            ->toThrow(PdfPasswordProtectedException::class);
    } finally {
        @unlink($path);
    }
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
