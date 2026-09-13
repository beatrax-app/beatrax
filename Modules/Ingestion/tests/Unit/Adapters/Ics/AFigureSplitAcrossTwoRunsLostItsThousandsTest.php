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

// PdfTextLayoutReader::renderRow() never lets two text runs touch — a run is
// placed at its own column or one space past what is already written,
// whichever is further right. So a report generator that draws a grouped
// figure as two runs, which is what kerning a thousands separator looks like
// in a content stream, hands the adapter "1. 197,44" for 1.197,44. The old
// anchor's character class held no space, so it stopped at the group mark:
// the row booked 197,44 and kept the thousand as the last word of its
// description. Every figure on an ICS statement that reaches four digits is
// grouped, which includes all four statement totals and both limit figures.

/** The committed statement, with one row's amount grouped so it has a thousand to lose. */
function aSplitRunStatementText(): string
{
    $source = file_get_contents(base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt'));
    if ($source === false) {
        throw new RuntimeException('Could not read the redacted ICS text fixture.');
    }

    return str_replace('197,44', '1.197,44', $source);
}

/** The same statement drawn cell by cell, every grouped figure split at its group mark. */
function aSplitRunStatementPdf(): string
{
    $fontSize = 5.0;
    $advance = 0.5 * $fontSize;
    $content = '';
    $y = 820.0;

    foreach (explode("\n", rtrim(aSplitRunStatementText(), "\n")) as $line) {
        $column = 0;
        foreach (preg_split('/(\s{2,})/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $index => $cell) {
            if ($index % 2 === 0 && trim($cell) !== '') {
                $offset = 0;
                foreach (aSplitRunCellRuns($cell) as $run) {
                    $content .= sprintf(
                        "BT /F1 %.1f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n",
                        $fontSize,
                        20 + ($column + $offset) * $advance,
                        $y,
                        strtr((string) mb_convert_encoding($run, 'Windows-1252', 'UTF-8'), [
                            '\\' => '\\\\', '(' => '\\(', ')' => '\\)',
                        ]),
                    );
                    $offset += mb_strlen($run);
                }
            }
            $column += mb_strlen($cell);
        }
        $y -= 7.4;
    }

    return aSplitRunPdfAround($content);
}

/**
 * The runs one cell is drawn as: two where it carries a grouped figure, cut
 * just past the group mark, and one everywhere else.
 *
 * @return list<string>
 */
function aSplitRunCellRuns(string $cell): array
{
    if (preg_match('/\d{1,3}\.\d{3},\d{2}/', $cell, $found, PREG_OFFSET_CAPTURE) !== 1) {
        return [$cell];
    }

    $mark = strpos($cell, '.', (int) $found[0][1]);
    if ($mark === false) {
        return [$cell];
    }

    return [substr($cell, 0, $mark + 1), substr($cell, $mark + 1)];
}

function aSplitRunPdfAround(string $content): string
{
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

function aSplitRunAdapter(PdfTextExtractor $extractor): IcsPdfAdapter
{
    return new IcsPdfAdapter(new HeaderSniffer, $extractor, new IcsAmountParser, new IcsStatementHeader(new IcsDateParser));
}

/** @return list<array<int, string|int|null>> */
function aSplitRunRows(PdfTextExtractor $extractor, string $pdfPath): array
{
    $accounts = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return new AccountResolution(accountId: null, iban: $iban);
        }
    };

    $rows = [];
    foreach (aSplitRunAdapter($extractor)->parse($pdfPath, $accounts) as $dto) {
        $rows[] = [$dto->postedAt->toDateString(), $dto->counterpartyName, $dto->amountMinor];
    }

    return $rows;
}

function aSplitRunCommittedTextExtractor(): PdfTextExtractor
{
    return new class extends PdfTextExtractor
    {
        public function extract(string $pdfPath): string
        {
            return aSplitRunStatementText();
        }
    };
}

beforeEach(function (): void {
    $this->statement = tempnam(sys_get_temp_dir(), 'ics-split-run').'.pdf';
    file_put_contents($this->statement, aSplitRunStatementPdf());
});

afterEach(function (): void {
    @unlink($this->statement);
});

it('puts a space inside a figure its generator drew as two runs', function (): void {
    $text = (new PdfTextExtractor('/usr/bin/this-binary-does-not-exist'))->extract($this->statement);

    // The mechanism, before any adapter reads it: the euro column reads
    // "1. 197,44" and the statement totals read "1. 416,50".
    expect($text)->toMatch('/\s1\.\s197,44\s+Af\b/')
        ->and($text)->toMatch('/€\s+1\.\s416,50\s+Af\b/');
})->group('phase-3');

it('books the whole grouped figure rather than the part after the space', function (): void {
    $groundTruth = aSplitRunRows(aSplitRunCommittedTextExtractor(), $this->statement);
    $viaTextLayer = aSplitRunRows(new PdfTextExtractor('/usr/bin/this-binary-does-not-exist'), $this->statement);

    expect($groundTruth)->toContain(['2026-01-30', 'CLAUDE.AI SUBSCRIPTION ANTHROPIC.COM', -119_744])
        ->and($viaTextLayer)->toBe($groundTruth);
})->group('phase-3');

it('reads the grouped totals and limits off a statement drawn the same way', function (): void {
    $accounts = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return new AccountResolution(accountId: null, iban: $iban);
        }
    };

    $adapter = aSplitRunAdapter(new PdfTextExtractor('/usr/bin/this-binary-does-not-exist'));
    iterator_to_array($adapter->parse($this->statement, $accounts), false);

    $summary = $adapter->statementMetadata();

    expect($summary?->closingBalanceMinor)->toBe(-141_650)
        ->and($summary?->extras['totalChargesMinor'] ?? null)->toBe(-141_650)
        ->and($summary?->extras['creditLimitMinor'] ?? null)->toBe(250_000)
        ->and($summary?->extras['minimumDueMinor'] ?? null)->toBe(141_650);
})->group('phase-3');

it('still reads the letterhead and the totals off a text layer holding a byte that is not UTF-8', function (): void {
    $accounts = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return new AccountResolution(accountId: null, iban: $iban);
        }
    };

    // One lone 0x92 — a Windows-1252 curly apostrophe nothing decoded — is all
    // it takes. A /u anchor over this subject makes preg_match return false,
    // and a reader testing `!== 1` cannot tell that from "the statement states
    // no totals and no due date". The figure grammar's raw-byte group marks
    // need these anchors to run without /u anyway.
    $extractor = new class extends PdfTextExtractor
    {
        public function extract(string $pdfPath): string
        {
            return str_replace('Postbus 23225', "Postbus 23225\x92", aSplitRunStatementText());
        }
    };

    $adapter = aSplitRunAdapter($extractor);
    iterator_to_array($adapter->parse($this->statement, $accounts), false);

    $summary = $adapter->statementMetadata();

    expect($summary?->closingBalanceMinor)->toBe(-141_650)
        ->and($summary?->paymentDueDate?->toDateString())->toBe('2026-03-08');
})->group('phase-3');
