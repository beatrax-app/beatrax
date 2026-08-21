<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Exceptions;

use RuntimeException;

// Raised when the watched-folder scanner rejects a candidate before
// RecordReceipt ever parses it. The scan catches this per file and moves that
// one entry to failed/, so the message is also what an operator reads back out
// of the sibling .error.txt.
final class InboxDropScanException extends RuntimeException
{
    public static function emlTooLarge(string $filename): self
    {
        return new self("inbox-drop .eml exceeds the size cap: {$filename}");
    }
}
