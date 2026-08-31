<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Modules\Core\Public\Support\UploadLimits;
use Modules\Ingestion\Internal\Exceptions\PdfExtractionFailed;
use Modules\Ingestion\Public\Exceptions\PdfHasNoTextLayerException;
use Modules\Ingestion\Public\Exceptions\PdfPasswordProtectedException;
use Modules\Ingestion\Public\Exceptions\PdfReaderUnavailableException;
use Spatie\PdfToText\Exceptions\CouldNotExtractText;
use Spatie\PdfToText\Exceptions\PdfNotFound;
use Spatie\PdfToText\Pdf;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * @link ../../../../../.docs/features/ingestion/ics-pdf-text-extraction.md
 */
class PdfTextExtractor
{
    public const int MAX_BYTES = UploadLimits::MAX_BYTES;

    private const string BINARY_NAME = 'pdftotext';

    /** @var list<string> */
    private const array PDFTOTEXT_OPTIONS = ['layout', 'enc UTF-8', 'eol unix', 'nopgbrk'];

    // Searched on top of PATH, because a desktop launched from the dock or from
    // a NativePHP bundle inherits a PATH that has none of these on it.
    /** @var list<string> */
    private const array EXTRA_BINARY_DIRS = [
        '/usr/bin',
        '/usr/local/bin',
        '/opt/homebrew/bin',
        '/opt/local/bin',
        'C:\\Program Files\\xpdf-tools-win\\bin64',
    ];

    private readonly PdfTextLayoutReader $textLayer;

    public function __construct(
        private readonly ?string $binaryPath = null,
        ?PdfTextLayoutReader $textLayer = null,
    ) {
        $this->textLayer = $textLayer ?? new PdfTextLayoutReader;
    }

    /**
     * @throws PdfReaderUnavailableException When this build carries no PDF reader at all.
     * @throws PdfPasswordProtectedException When the file is encrypted.
     * @throws PdfHasNoTextLayerException When the pages carry no text to read.
     * @throws PdfExtractionFailed When the input cannot be extracted.
     */
    public function extract(string $pdfPath): string
    {
        $this->guardInput($pdfPath);

        $binary = $this->popplerBinary();

        try {
            $text = $binary === null
                ? $this->viaTextLayer($pdfPath)
                : $this->viaPoppler($binary, $pdfPath);
        } catch (PdfExtractionFailed $e) {
            throw $this->reclassify($pdfPath, $e);
        }

        // A scan and a statement both parse; only one of them has words in it.
        // Left unanswered here, an image-only PDF reached the screen as a file
        // whose rows were all unreadable, which sent the reader to their bank.
        if (trim($text) === '') {
            throw new PdfHasNoTextLayerException(
                'The PDF carries no text layer; it is an image or a scan.',
            );
        }

        return $text;
    }

    /**
     * @throws PdfExtractionFailed
     */
    private function guardInput(string $pdfPath): void
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
    }

    // Asked of both readers and only after one has already failed. Consulted
    // before the attempt, the same eight bytes appearing inside an uncompressed
    // content stream would refuse a statement that reads perfectly well.
    private function reclassify(string $pdfPath, PdfExtractionFailed $failure): PdfExtractionFailed|PdfPasswordProtectedException
    {
        $bytes = @file_get_contents($pdfPath);

        if (is_string($bytes) && str_contains($bytes, '/Encrypt')) {
            return new PdfPasswordProtectedException(
                'The PDF declares encryption and opens for nobody without its password.',
                0,
                $failure,
            );
        }

        return $failure;
    }

    // The one place the app asks whether poppler is here. A phone answers null
    // and always will; a desktop that has it answers a path. Both platforms
    // then follow the same two branches, so the phone's is reachable in a test
    // by naming a path that cannot exist.
    private function popplerBinary(): ?string
    {
        if ($this->binaryPath !== null) {
            return is_executable($this->binaryPath) ? $this->binaryPath : null;
        }

        return (new ExecutableFinder)->find(self::BINARY_NAME, null, self::EXTRA_BINARY_DIRS);
    }

    /**
     * @throws PdfExtractionFailed
     */
    private function viaPoppler(string $binary, string $pdfPath): string
    {
        try {
            return new Pdf($binary)
                ->setPdf($pdfPath)
                ->setOptions(self::PDFTOTEXT_OPTIONS)
                ->text();
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

    /**
     * @throws PdfReaderUnavailableException
     */
    private function viaTextLayer(string $pdfPath): string
    {
        if (! $this->textLayer->available()) {
            throw new PdfReaderUnavailableException(
                'This build carries neither pdftotext nor the in-app PDF reader.',
            );
        }

        return $this->textLayer->read($pdfPath);
    }
}
