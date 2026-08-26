<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Modules\Core\Public\Support\UploadLimits;
use Modules\Ingestion\Internal\Exceptions\PdfExtractionFailed;
use Modules\Ingestion\Public\Exceptions\PdfReaderUnavailableException;
use Spatie\PdfToText\Exceptions\BinaryNotFoundException;
use Spatie\PdfToText\Exceptions\CouldNotExtractText;
use Spatie\PdfToText\Exceptions\PdfNotFound;
use Spatie\PdfToText\Pdf;
use Throwable;

/**
 * @link ../../../../../.docs/features/ingestion/ics-pdf-text-extraction.md
 */
class PdfTextExtractor
{
    public const int MAX_BYTES = UploadLimits::MAX_BYTES;

    /** @var list<string> */
    private const PDFTOTEXT_OPTIONS = ['layout', 'enc UTF-8', 'eol unix', 'nopgbrk'];

    public function __construct(
        private readonly ?string $binaryPath = null,
    ) {}

    /**
     * @throws PdfReaderUnavailableException When this install has no pdftotext.
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

        // Spatie only reports a missing binary when it does the finding, so a
        // configured path that is not executable would otherwise arrive as a
        // failed extraction. Same absence, same answer, either way it was set.
        if ($this->binaryPath !== null && ! is_executable($this->binaryPath)) {
            throw new PdfReaderUnavailableException(
                'pdftotext is not installed or not executable.',
            );
        }

        try {
            $pdf = $this->binaryPath !== null
                ? new Pdf($this->binaryPath)
                : new Pdf;

            return $pdf
                ->setPdf($pdfPath)
                ->setOptions(self::PDFTOTEXT_OPTIONS)
                ->text();
        } catch (BinaryNotFoundException $e) {
            // Not a property of the file, so it is not reported as one: a phone
            // ships no pdftotext and never can, and a server without poppler
            // needs it installed. Both are answered by naming the binary.
            throw new PdfReaderUnavailableException(
                sprintf('pdftotext is not installed or not executable: %s', $e->getMessage()),
                0,
                $e,
            );
        } catch (PdfNotFound|CouldNotExtractText $e) {
            throw new PdfExtractionFailed(
                sprintf('Could not extract PDF text: %s', $e->getMessage()),
                0,
                $e,
            );
        } catch (Throwable $e) {
            throw new PdfExtractionFailed(
                sprintf('PDF extraction failed: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }
}
