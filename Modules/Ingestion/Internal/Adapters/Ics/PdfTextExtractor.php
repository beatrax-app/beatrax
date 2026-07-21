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
 * @link ../../../../../.docs/features/ingestion/architecture.md
 */
class PdfTextExtractor
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    private const PDFTOTEXT_OPTIONS = ['layout', 'enc UTF-8', 'eol unix', 'nopgbrk'];

    public function __construct(
        private readonly ?string $binaryPath = null,
    ) {}

    /**
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

        // Defence-in-depth for callers that bypass the upload wizard's
        // HeaderSniffer; Pdf::text() itself invokes Symfony Process with an
        // argv array so $pdfPath never enters a shell-string regardless.
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
