<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Ics\IcsAmountParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsDateParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Ics\IcsStatementHeader;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Services\HeaderSniffer;

// The committed scenario-1 PDF writes each row as one string, which is the easy
// case for any reader. A real issuer's report generator writes each CELL at its
// own position, in a Flate-compressed stream — so this file renders the real
// anonymised statement into that shape and reads it back.
function columnLayoutStatementPdf(): string
{
    $source = file_get_contents(base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt'));
    if ($source === false) {
        throw new RuntimeException('Could not read the redacted ICS text fixture.');
    }

    $fontSize = 5.0;
    $advance = 0.5 * $fontSize;
    $content = '';
    $y = 820.0;

    foreach (explode("\n", rtrim($source, "\n")) as $line) {
        $column = 0;
        foreach (preg_split('/(\s{2,})/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $index => $cell) {
            if ($index % 2 === 0 && trim($cell) !== '') {
                $content .= sprintf(
                    "BT /F1 %.1f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n",
                    $fontSize,
                    20 + $column * $advance,
                    $y,
                    strtr((string) mb_convert_encoding($cell, 'Windows-1252', 'UTF-8'), [
                        '\\' => '\\\\', '(' => '\\(', ')' => '\\)',
                    ]),
                );
            }
            $column += mb_strlen($cell);
        }
        $y -= 7.4;
    }

    $stream = gzcompress($content, 9);
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 900] /Resources '
            .'<< /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        4 => '<< /Length '.strlen($stream).' /Filter /FlateDecode >>'."\nstream\n".$stream."\nendstream",
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
    ];

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

/** @return list<array<int, string|int|null>> */
function columnLayoutParsedRows(PdfTextExtractor $extractor, string $pdfPath): array
{
    $accounts = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return new AccountResolution(accountId: null, iban: $iban);
        }
    };

    $adapter = new IcsPdfAdapter(new HeaderSniffer, $extractor, new IcsAmountParser, new IcsStatementHeader(new IcsDateParser));

    $rows = [];
    foreach ($adapter->parse($pdfPath, $accounts) as $dto) {
        $rows[] = [
            $dto->bookedAt->toDateString(),
            $dto->postedAt->toDateString(),
            $dto->counterpartyName,
            $dto->currency,
            $dto->amountMinor,
            $dto->settledCurrency,
            $dto->settledAmountMinor,
        ];
    }

    return $rows;
}

beforeEach(function (): void {
    $this->statement = tempnam(sys_get_temp_dir(), 'ics-columns').'.pdf';
    file_put_contents($this->statement, columnLayoutStatementPdf());
});

afterEach(function (): void {
    @unlink($this->statement);
});

it('recovers the real statement from a per-cell PDF exactly as the committed text does', function (): void {
    // The committed .txt is a real pdftotext -layout extraction of a real
    // statement, so it is the answer the desktop gives. The device path has to
    // reach the same 38 rows from the same statement rendered as coordinates.
    $groundTruth = columnLayoutParsedRows(
        new class extends PdfTextExtractor
        {
            public function extract(string $pdfPath): string
            {
                $text = file_get_contents(base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt'));
                if ($text === false) {
                    throw new RuntimeException('Could not read the redacted ICS text fixture.');
                }

                return $text;
            }
        },
        $this->statement,
    );

    $viaTextLayer = columnLayoutParsedRows(
        new PdfTextExtractor('/usr/bin/this-binary-does-not-exist'),
        $this->statement,
    );

    expect($groundTruth)->toHaveCount(38);
    expect($viaTextLayer)->toBe($groundTruth);
})->group('phase-3');

it('keeps a space between the settled amount and its direction marker', function (): void {
    // smalot's own getText() renders these two cells as `5,17Af`, and
    // looksLikeTransactionRow() then does not see a transaction at all — the
    // purchase leaves the import with no row and no error. The reader's
    // one-space floor between columns is what stands in the way.
    $text = (new PdfTextExtractor('/usr/bin/this-binary-does-not-exist'))->extract($this->statement);

    expect($text)->toMatch('/\s5,17\s+Af\b/');
    expect($text)->toMatch('/\s21,78\s+Af\b/');
    expect($text)->not->toMatch('/\d,\d{2}(Af|Bij)/');
})->group('phase-3');
