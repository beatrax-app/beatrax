<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Services;

use Modules\Ingestion\Internal\Adapters\Asn\AsnCsvHeaderProfile;
use Modules\Ingestion\Public\Dto\SniffResult;
use Modules\Ingestion\Public\Exceptions\SniffMismatchException;

/**
 * Validates that a local file matches a declared source format before any
 * adapter starts parsing. Inspects the first 8 KB of bytes, the file
 * extension, and (for ASN CSV) the header row column count and signature.
 *
 * The exception messages are user-facing — the upload wizard renders them
 * verbatim per UI-SPEC §Error states.
 */
final class HeaderSniffer
{
    private const HEAD_BYTES = 8192;

    public function sniff(string $localPath, string $declaredFormat): SniffResult
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new SniffMismatchException(sprintf('File not readable: %s', $localPath));
        }

        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            throw new SniffMismatchException(sprintf('Could not open file: %s', $localPath));
        }

        try {
            $head = (string) fread($handle, self::HEAD_BYTES);
        } finally {
            fclose($handle);
        }

        return match ($declaredFormat) {
            AsnCsvHeaderProfile::FORMAT => $this->sniffAsnCsv($localPath, $head),
            default => throw new SniffMismatchException(sprintf(
                'Unsupported sniff target: %s',
                $declaredFormat,
            )),
        };
    }

    private function sniffAsnCsv(string $path, string $head): SniffResult
    {
        if (preg_match('/\.csv$/i', $path) !== 1) {
            throw new SniffMismatchException(
                "That file doesn't look like a CSV. Drop in the ASN CSV export you downloaded from the ASN portal."
            );
        }

        $firstLine = strtok($head, "\r\n");
        if ($firstLine === false) {
            throw new SniffMismatchException('The file is empty.');
        }

        $delim = AsnCsvHeaderProfile::DELIMITER;
        $columns = str_getcsv($firstLine, $delim, '"', '');

        if (count($columns) !== AsnCsvHeaderProfile::EXPECTED_COLUMN_COUNT) {
            throw new SniffMismatchException(sprintf(
                'Expected %d columns, got %d. This file does not match the ASN CSV layout.',
                AsnCsvHeaderProfile::EXPECTED_COLUMN_COUNT,
                count($columns),
            ));
        }

        $expected = AsnCsvHeaderProfile::HEADER_SIGNATURE;
        if ($columns[0] !== $expected[0] || $columns[1] !== $expected[1]) {
            throw new SniffMismatchException(sprintf(
                "This CSV doesn't match the expected ASN column layout (header starts with '%s,%s', got '%s,%s'). If ASN changed their export format, file an issue.",
                $expected[0],
                $expected[1],
                $columns[0],
                $columns[1],
            ));
        }

        return new SniffResult(
            format: AsnCsvHeaderProfile::FORMAT,
            delimiter: $delim,
            hasHeader: AsnCsvHeaderProfile::HAS_HEADER,
            encoding: AsnCsvHeaderProfile::SOURCE_ENCODING,
            columnCount: count($columns),
        );
    }
}
