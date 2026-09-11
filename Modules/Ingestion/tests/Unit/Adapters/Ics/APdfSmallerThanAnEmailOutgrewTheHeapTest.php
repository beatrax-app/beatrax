<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\PdfContentSize;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextLayoutReader;
use Modules\Ingestion\Internal\Exceptions\ReadCeilingExceededException;
use Smalot\PdfParser\Parser;

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

function twoStreamPagePdfForCeilingTest(string $first, string $second): string
{
    return pdfFromObjectsForCeilingTest([
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
            .'/Resources << /Font << /F1 5 0 R >> >> /Contents [4 0 R 6 0 R] >>',
        4 => '<< /Length '.strlen($first)." >>\nstream\n".$first.'endstream',
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        6 => '<< /Length '.strlen($second)." >>\nstream\n".$second.'endstream',
    ]);
}

// Every page shares one tiny content stream, so a hundred of them spend well
// under the byte ceiling and the page count is the only thing being asked.
function manyPagePdfForCeilingTest(int $pages): string
{
    $content = "BT\n/F1 12 Tf\n1 0 0 1 10 700 Tm\n(Row) Tj\nET\n";

    $kids = [];
    for ($i = 1; $i <= $pages; $i++) {
        $kids[] = (4 + $i).' 0 R';
    }

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        4 => '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream',
    ];
    for ($i = 1; $i <= $pages; $i++) {
        $objects[4 + $i] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
            .'/Resources << /Font << /F1 3 0 R >> >> /Contents 4 0 R >>';
    }

    return pdfFromObjectsForCeilingTest($objects);
}

// A page's /Contents is often an ARRAY of stream objects rather than one, and
// every byte of it is concatenated before a run is read. Measured per stream
// instead of summed, a page split in two slips past the ceiling at twice it.
it('measures a page whose content arrives as several streams as their sum', function (): void {
    $half = str_repeat("1 0 0 1 10 700 Tm\n(a) Tj\n", 4_000);
    $path = pdfWrittenForCeilingTest(twoStreamPagePdfForCeilingTest(
        "BT\n/F1 12 Tf\n".$half."ET\n",
        "BT\n/F1 12 Tf\n".$half."ET\n",
    ));

    expect(fn () => new PdfTextLayoutReader()->read($path))
        ->toThrow(ReadCeilingExceededException::class);
});

it('reads both streams of a page that carries two, when they fit', function (): void {
    $path = pdfWrittenForCeilingTest(twoStreamPagePdfForCeilingTest(
        "BT\n/F1 12 Tf\n1 0 0 1 10 700 Tm\n(FirstStream) Tj\nET\n",
        "BT\n/F1 12 Tf\n1 0 0 1 10 680 Tm\n(SecondStream) Tj\nET\n",
    ));

    expect(new PdfTextLayoutReader()->read($path))
        ->toContain('FirstStream')
        ->toContain('SecondStream');
});

// The byte ceiling cannot see this one: a page carrying no content stream at
// all spends none of it, and a hundred thousand of them still build a hundred
// thousand page objects.
it('refuses a document carrying more pages than a statement has', function (): void {
    $path = pdfWrittenForCeilingTest(manyPagePdfForCeilingTest(101));

    expect(fn () => new PdfTextLayoutReader()->read($path))
        ->toThrow(ReadCeilingExceededException::class, 'pages one statement may have');
});

it('reads a document sitting on the page ceiling rather than over it', function (): void {
    $path = pdfWrittenForCeilingTest(manyPagePdfForCeilingTest(100));

    expect(substr_count(new PdfTextLayoutReader()->read($path), 'Row'))->toBe(100);
});

// A page can carry no content stream at all, and the byte ceiling reads it as
// spending none — which is the honest answer, and why the page count above is
// a ceiling of its own rather than something the bytes could have covered.
it('measures a page carrying no content stream as spending none of the ceiling', function (): void {
    $path = pdfWrittenForCeilingTest(pdfFromObjectsForCeilingTest([
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>',
    ]));

    $page = new Parser()->parseFile($path)->getPages()[0];

    expect(PdfContentSize::ofPage($page))->toBe(0);
});

// Two runs whose x both clamp to the ceiling would land at the same column,
// and the second at one already written past. The one-space floor is applied
// after the clamp for exactly this, so str_repeat is never asked for a
// negative count and the two cells do not fuse.
it('keeps a run off the one beside it when both coordinates clamp to the ceiling', function (): void {
    $path = pdfWrittenForCeilingTest(onePagePdfForCeilingTest(
        "BT\n/F1 12 Tf\n"
        ."1 0 0 1 100000000000 700 Tm\n(Statement) Tj\n"
        ."1 0 0 1 100000000001 700 Tm\n(Total) Tj\n"
        .'ET'."\n"
    ));

    expect(new PdfTextLayoutReader()->read($path))->toContain('Statement Total');
});

// /Contents can also point at ONE object that is itself an array of stream
// references. Measured as a single stream it reads as the array's own length
// rather than the streams', so a page reached this way is counted at a
// fraction of what it lays out and walks through the ceiling.
it('measures a page whose content is reached through an indirect array of streams', function (): void {
    $first = "BT\n/F1 12 Tf\n1 0 0 1 10 700 Tm\n(Alpha) Tj\nET\n";
    $second = "BT\n/F1 12 Tf\n1 0 0 1 10 680 Tm\n(Beta) Tj\nET\n";

    $path = pdfWrittenForCeilingTest(pdfFromObjectsForCeilingTest([
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
            .'/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        4 => '[6 0 R 7 0 R]',
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        6 => '<< /Length '.strlen($first)." >>\nstream\n".$first.'endstream',
        7 => '<< /Length '.strlen($second)." >>\nstream\n".$second.'endstream',
    ]));

    $page = new Parser()->parseFile($path)->getPages()[0];

    expect(PdfContentSize::ofPage($page))->toBe(strlen($first) + strlen($second));
});
