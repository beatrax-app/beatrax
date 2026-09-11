<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Exceptions;

use Modules\Core\Public\Support\MessageNamesNoUserData;
use RuntimeException;

// The file is the format it says it is; it is larger than this build will read.
// Deliberately not a NamesAFormatMismatch, which would send the reader to check
// the bank and the format when there is nothing wrong with either: Import's
// default is FileStoppedShort, whose copy already names this case.
/**
 * @link ../../../../.docs/features/ingestion/what-a-file-expands-into.md
 */
final class ReadCeilingExceededException extends RuntimeException implements MessageNamesNoUserData
{
    public static function csvLine(int $maxBytes): self
    {
        return new self(sprintf(
            'A line of this CSV is longer than the %d bytes one row may take.',
            $maxBytes,
        ));
    }

    public static function camtEntries(int $max): self
    {
        return new self(sprintf(
            'This CAMT.053 statement books more than the %d entries one file may carry.',
            $max,
        ));
    }

    public static function pdfContent(int $maxBytes): self
    {
        return new self(sprintf(
            'This PDF lays out more than the %d bytes of page content one statement may carry.',
            $maxBytes,
        ));
    }

    public static function pdfPages(int $max): self
    {
        return new self(sprintf(
            'This PDF carries more than the %d pages one statement may have.',
            $max,
        ));
    }
}
