<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Support;

use Modules\Core\Public\Support\PatternScan;
use Modules\Ingestion\Internal\Exceptions\ReadCeilingExceededException;

// A byte cap on the upload is a cap on the file, not on what the file expands
// into, and the readers below expand by a different multiple each. Both checks
// stream: neither holds more than one chunk, so measuring a file costs nothing
// like reading it.
/**
 * @link ../../../../.docs/features/ingestion/what-a-file-expands-into.md
 */
final class SourceFileCeilings
{
    // fgetcsv holds one whole line as an array of cells, so the longest line is
    // what decides the read's peak — a 3.8 MB line of two million cells
    // exhausted a phone's heap before any row count was consulted. A shipped
    // bank row runs to a few hundred bytes.
    public const int MAX_CSV_LINE_BYTES = 65_536;

    // genkgo builds the whole statement before the adapter yields its first
    // row, at roughly 1.5 KB of heap per booked entry. A busy account books
    // about 2,700 a year, so this is seven years of one; 62,497 of them in a
    // 9.5 MB file — inside the 10 MB upload cap — was a fatal.
    public const int MAX_CAMT_ENTRIES = 20_000;

    private const int SCAN_CHUNK_BYTES = 262_144;

    // Anchored on the whole tag: `<Ntry` is also the opening of `<NtryDtls>`
    // and `<NtryRef>`, and counting those would refuse a statement for the
    // detail blocks inside its own entries. A prefixed spelling is not covered
    // because genkgo refuses one outright — "cannot find message format".
    private const string CAMT_ENTRY_PATTERN = '/<Ntry[\s>\/]/';

    /**
     * @throws ReadCeilingExceededException
     */
    public static function refuseLongCsvLine(string $localPath): void
    {
        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            while (($line = stream_get_line($handle, self::MAX_CSV_LINE_BYTES + 1, "\n")) !== false) {
                if (strlen($line) > self::MAX_CSV_LINE_BYTES) {
                    throw ReadCeilingExceededException::csvLine(self::MAX_CSV_LINE_BYTES);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @throws ReadCeilingExceededException
     */
    public static function refuseCamtEntryCount(string $localPath): void
    {
        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            return;
        }

        // Shorter than the shortest match, so no whole match can sit inside the
        // carry alone: every match this counts either lies in the chunk it was
        // counted with or straddles the boundary, and none is counted twice.
        $carryBytes = 5;
        $carry = '';
        $found = 0;

        try {
            while (($chunk = fread($handle, self::SCAN_CHUNK_BYTES)) !== false && $chunk !== '') {
                $found += PatternScan::count(self::CAMT_ENTRY_PATTERN, $carry.$chunk);
                if ($found > self::MAX_CAMT_ENTRIES) {
                    throw ReadCeilingExceededException::camtEntries(self::MAX_CAMT_ENTRIES);
                }
                $carry = substr($chunk, -$carryBytes);
            }
        } finally {
            fclose($handle);
        }
    }
}
