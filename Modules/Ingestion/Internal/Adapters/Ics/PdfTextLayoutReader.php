<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Modules\Ingestion\Internal\Exceptions\PdfExtractionFailed;
use Modules\Ingestion\Internal\Exceptions\ReadCeilingExceededException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * @link ../../../../../.docs/features/ingestion/ics-pdf-text-extraction.md#reading-a-statement-without-poppler
 */
class PdfTextLayoutReader
{
    // A nominal glyph advance in points, used only to turn an x coordinate into
    // a column index. Every column downstream is matched with \s+, so this
    // figure decides how wide the padding looks and never whether a row reads.
    private const float COLUMN_WIDTH_POINTS = 4.8;

    // Two cells of one visual row can sit a fraction of a point apart when the
    // generator rounds each baseline on its own. Wider than this and the row
    // below joins the row above; narrower and one row splits into several.
    private const float LINE_TOLERANCE_POINTS = 2.0;

    // A run's own newlines are laid out by the generator, not by us, so each
    // part is nudged below the last by a hair — far under the line tolerance,
    // which is what keeps the parts in their written order and off one line.
    private const float INTRA_RUN_LINE_STEP = 0.01;

    // What a document may cost to lay out, in decoded content-stream bytes.
    // Laying out a page costs the square of the runs on it inside the parser:
    // this much is 1.7s, twice it is 7s, and a 12 KB file carrying 5 MB of it
    // ran for three quarters of an hour. A shipped statement spends 2,643.
    private const int MAX_CONTENT_BYTES = 131_072;

    // Pages of paper, not pages of a cursor — qualified for the same reason
    // DiscoveryScanJob qualifies its own. The sum above already answers for a
    // document whose pages carry text; this is here for the one that carries
    // none, whose hundred thousand empty pages still build as many objects.
    private const int MAX_STATEMENT_PAGES = 100;

    // An x coordinate is the page's own, and a crafted text matrix asked for
    // twenty gigabytes of padding on one line. A page is 612 points wide and
    // every real column lands under 60.
    private const int MAX_COLUMNS = 4_096;

    public function available(): bool
    {
        return class_exists(Parser::class);
    }

    /**
     * @throws PdfExtractionFailed When the file cannot be parsed at all.
     */
    public function read(string $pdfPath): string
    {
        try {
            $lines = [];
            $contentBytes = 0;

            foreach ($this->pages($pdfPath) as $page) {
                // Not `>`: the decoder is handed the same ceiling, so a stream
                // that reaches it exactly is one it cut, and laying out half a
                // statement is worse than refusing the whole.
                $contentBytes += PdfContentSize::ofPage($page);
                if ($contentBytes >= self::MAX_CONTENT_BYTES) {
                    throw ReadCeilingExceededException::pdfContent(self::MAX_CONTENT_BYTES);
                }

                foreach ($this->assembleLines($this->placeRuns($page->getDataTm())) as $line) {
                    $lines[] = $line;
                }

                // poppler's -nopgbrk drops the form feed but keeps the blank
                // line between pages, and the ICS adapter reads a blank line as
                // the end of the transaction table.
                $lines[] = '';
            }

            return implode("\n", $lines)."\n";
        } catch (ReadCeilingExceededException $refused) {
            throw $refused;
        } catch (Throwable $e) {
            throw new PdfExtractionFailed(
                sprintf('Could not read the PDF text layer: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * @return list<Page>
     *
     * @throws ReadCeilingExceededException
     */
    private function pages(string $pdfPath): array
    {
        $config = new Config;
        // The ceiling reaches the decoder too, because a stream that inflates
        // past it has already spent the memory by the time read() can sum it:
        // a 49 KB file whose one stream inflates to 50 MB exhausted a 128 MB
        // heap inside the vendor, where E_ERROR is not a Throwable to catch.
        $config->setDecodeMemoryLimit(self::MAX_CONTENT_BYTES);
        $config->setRetainImageContent(false);

        $pages = array_values(new Parser([], $config)->parseFile($pdfPath)->getPages());

        if (count($pages) > self::MAX_STATEMENT_PAGES) {
            throw ReadCeilingExceededException::pdfPages(self::MAX_STATEMENT_PAGES);
        }

        return $pages;
    }

    /**
     * @param  array<array-key, mixed>  $dataTm
     * @return list<array{x: float, y: float, text: string}>
     */
    private function placeRuns(array $dataTm): array
    {
        $runs = [];

        foreach ($dataTm as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $matrix = $entry[0] ?? null;
            $text = $entry[1] ?? null;
            if (! is_array($matrix) || ! is_string($text)) {
                continue;
            }

            $x = self::coordinate($matrix[4] ?? null);
            $y = self::coordinate($matrix[5] ?? null);

            foreach (explode("\n", $text) as $index => $part) {
                if (trim($part) === '') {
                    continue;
                }

                $runs[] = [
                    'x' => $x,
                    'y' => $y - $index * self::INTRA_RUN_LINE_STEP,
                    'text' => trim($part),
                ];
            }
        }

        usort($runs, static fn (array $a, array $b): int => [$b['y'], $a['x']] <=> [$a['y'], $b['x']]);

        return $runs;
    }

    // The text matrix arrives as six numeric strings, and a malformed object
    // graph can put anything at all in one of the six. A run whose coordinate
    // will not read is placed at the origin rather than dropped: it is still
    // text on the page, and the reader would otherwise lose it silently.
    private static function coordinate(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param  list<array{x: float, y: float, text: string}>  $runs
     * @return list<string>
     */
    private function assembleLines(array $runs): array
    {
        $lines = [];
        $row = [];
        $baseline = null;

        foreach ($runs as $run) {
            if ($baseline !== null && abs($baseline - $run['y']) > self::LINE_TOLERANCE_POINTS) {
                $lines[] = $this->renderRow($row);
                $row = [];
            }

            if ($row === []) {
                $baseline = $run['y'];
            }

            $row[] = $run;
        }

        if ($row !== []) {
            $lines[] = $this->renderRow($row);
        }

        return $lines;
    }

    /**
     * @param  list<array{x: float, y: float, text: string}>  $row
     */
    private function renderRow(array $row): string
    {
        usort($row, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);

        $rendered = '';

        foreach ($row as $run) {
            $written = mb_strlen($rendered);

            // The column the coordinate asks for, clamped, and then never
            // closer than one space to what is already written. Clamping after
            // the floor instead put a second clamped run BEHIND the first, and
            // str_repeat refuses a negative count.
            $column = max(
                min(self::MAX_COLUMNS, (int) round($run['x'] / self::COLUMN_WIDTH_POINTS)),
                $written === 0 ? 0 : $written + 1,
            );

            $rendered .= str_repeat(' ', $column - $written).$run['text'];
        }

        return rtrim($rendered);
    }
}
