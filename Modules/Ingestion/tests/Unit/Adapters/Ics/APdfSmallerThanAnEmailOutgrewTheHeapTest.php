<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\PdfTextLayoutReader;
use Modules\Ingestion\Internal\Exceptions\ReadCeilingExceededException;

// The in-app reader is the phone's only PDF reader, and every ceiling it had
// was on the file: 10 MB, from the wizard. A PDF says how much text to lay out,
// where to lay it out, and how many bytes that text inflates to, and none of
// those is the file's size. Each case below is a file under 50 KB that ended
// the process -- an E_ERROR the read()'s own catch cannot see.

/** @param  array<int, string>  $objects */
function pdfFromObjectsForCeilingTest(array $objects): string
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

    return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n"
        ."startxref\n".$startxref."\n%%EOF\n";
}

function onePagePdfForCeilingTest(string $content, bool $deflated = false): string
{
    $stream = $deflated ? (string) gzcompress($content, 9) : $content;
    $filter = $deflated ? ' /Filter /FlateDecode' : '';

    return pdfFromObjectsForCeilingTest([
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
            .'/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        4 => '<< /Length '.strlen($stream).$filter." >>\nstream\n".$stream."\nendstream",
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ]);
}

function pdfWrittenForCeilingTest(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'pdf-ceiling-').'.pdf';
    file_put_contents($path, $bytes);
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });

    return $path;
}

// The column index is the run's own x, divided by a nominal glyph advance and
// handed to str_repeat. A text matrix naming 100,000,000,000 asked for twenty
// gigabytes of padding on one line, from a 602-byte file.
it('does not pad a line to the width an x coordinate names', function (): void {
    $path = pdfWrittenForCeilingTest(onePagePdfForCeilingTest(
        "BT\n/F1 12 Tf\n1 0 0 1 100000000000 700 Tm\n(Statement) Tj\nET\n"
    ));

    expect(filesize($path))->toBeLessThan(1_024);

    $text = new PdfTextLayoutReader()->read($path);

    expect(strlen($text))->toBeLessThan(8_192)
        ->and($text)->toContain('Statement');
});

// A content stream declares how long it is once inflated, and nothing was
// reading that number. 50 MB out of 49 KB exhausted a 128 MB heap inside the
// vendor's own str_replace, before this reader saw a single run.
it('refuses a content stream that inflates past what it will lay out', function (): void {
    $path = pdfWrittenForCeilingTest(onePagePdfForCeilingTest(
        "BT\n/F1 1 Tf\n1 0 0 1 10 700 Tm\n(".str_repeat('A', 5_000_000).") Tj\nET\n",
        deflated: true,
    ));

    expect(filesize($path))->toBeLessThan(64 * 1024);

    expect(fn () => new PdfTextLayoutReader()->read($path))
        ->toThrow(ReadCeilingExceededException::class);
});

// Laying out a page costs the square of the runs on it inside the parser, so
// the cheap axis is how many a file may carry, not how large it is: 200,000 of
// them in a 12 KB file ran for three quarters of an hour.
it('refuses a page carrying more text runs than it will lay out', function (): void {
    $path = pdfWrittenForCeilingTest(onePagePdfForCeilingTest(
        "BT\n/F1 12 Tf\n".str_repeat("1 0 0 1 10 700 Tm\n(a) Tj\n", 200_000)."ET\n",
        deflated: true,
    ));

    expect(filesize($path))->toBeLessThan(64 * 1024);

    expect(fn () => new PdfTextLayoutReader()->read($path))
        ->toThrow(ReadCeilingExceededException::class);
});

it('reads the shipped ICS statement, which spends 2,643 bytes of the ceiling', function (): void {
    $text = new PdfTextLayoutReader()->read(
        base_path('Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf')
    );

    expect($text)->toContain('Af');
});
