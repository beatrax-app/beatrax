<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Modules\Ingestion\Public\Exceptions\PdfExtractionFailed;
use Spatie\PdfToText\Exceptions\BinaryNotFoundException;
use Spatie\PdfToText\Exceptions\CouldNotExtractText;
use Spatie\PdfToText\Exceptions\PdfNotFound;
use Spatie\PdfToText\Pdf;
use Throwable;

/**
 * Injectable wrapper around the `spatie/pdf-to-text` exec() boundary.
 * Isolates the subprocess call so the ICS PDF adapter is unit-testable
 * against a committed redacted text fixture without ever shelling out;
 * the integration smoke test exercises the real binary against the tiny
 * synthetic PDF.
 *
 * Flags applied uniformly via `Pdf::setOptions()`:
 *
 *   - `layout`    — preserve the statement's tabular column structure via
 *                   whitespace padding (load-bearing for the regex-anchor
 *                   parser inside `IcsPdfAdapter`);
 *   - `enc UTF-8` — force UTF-8 output regardless of the host's locale;
 *   - `eol unix`  — LF line terminators so line-anchored regexes match
 *                   cleanly across hosts;
 *   - `nopgbrk`   — strip form-feed characters between pages so per-page
 *                   noise stripping does not have to special-case `\f`.
 *
 * Path-argument safety: the underlying `Spatie\PdfToText\Pdf::text()`
 * invokes Symfony Process with an argv array (each argument escaped),
 * so the input path never enters a shell-string. The `.pdf` suffix
 * regex below is a defence-in-depth check for callers that bypass the
 * upload-wizard's HeaderSniffer.
 *
 * Size cap: 10 MiB matches the wizard's existing `max:10240` Livewire
 * upload rule (kilobytes), so an over-sized upload is rejected at the
 * extractor boundary regardless of which entry point invoked it.
 *
 * Subclassable for unit-test substitution: the ICS PDF adapter is type-
 * hinted on this concrete class (no interface exists for a single
 * subprocess wrapper) so unit tests extend it with an anonymous class
 * that overrides `extract()` to return committed fixture text. The
 * class is not marked `final` for that reason.
 */
class PdfTextExtractor
{
    /** 10 MiB — mirrors the Livewire upload `max:10240` (KB) rule. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** @var list<string> Locked flag set; mirrored by the integration smoke test. */
    private const PDFTOTEXT_OPTIONS = ['layout', 'enc UTF-8', 'eol unix', 'nopgbrk'];

    public function __construct(
        private readonly ?string $binaryPath = null,
    ) {}

    /**
     * Extracts the text content of a PDF file at $pdfPath.
     *
     * @throws PdfExtractionFailed When the input cannot be extracted.
     */
    public function extract(string $pdfPath): string
    {
        if (! is_file($pdfPath) || ! is_readable($pdfPath)) {
            throw new PdfExtractionFailed(sprintf(
                'PDF file not readable: %s',
                $pdfPath,
            ));
        }

        if (preg_match('/\.pdf$/i', $pdfPath) !== 1) {
            throw new PdfExtractionFailed(
                'PDF extraction requires a .pdf file.',
            );
        }

        $size = filesize($pdfPath);
        if ($size === false) {
            throw new PdfExtractionFailed(sprintf(
                'Could not determine size of PDF: %s',
                $pdfPath,
            ));
        }
        if ($size > self::MAX_BYTES) {
            throw new PdfExtractionFailed(sprintf(
                'PDF exceeds the %d-byte size cap.',
                self::MAX_BYTES,
            ));
        }

        try {
            $pdf = $this->binaryPath !== null
                ? new Pdf($this->binaryPath)
                : new Pdf;

            return $pdf
                ->setPdf($pdfPath)
                ->setOptions(self::PDFTOTEXT_OPTIONS)
                ->text();
        } catch (BinaryNotFoundException|PdfNotFound|CouldNotExtractText $e) {
            throw new PdfExtractionFailed(
                sprintf('Could not extract PDF text: %s', $e->getMessage()),
                0,
                $e,
            );
        } catch (Throwable $e) {
            // Any underlying Symfony Process or other I/O error surfaces as
            // PdfExtractionFailed so the upload pipeline can render a single
            // user-facing message rather than a stack trace.
            throw new PdfExtractionFailed(
                sprintf('PDF extraction failed: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }
}
