<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Exceptions;

use RuntimeException;

// Raised when an mbox archive cannot be opened for streaming — a missing
// path or an unreadable file. Distinct from the blob-write failures:
// this is a read-side fault on the source archive, so no partial output
// has been produced when it surfaces.
/**
 * @link ../../../../.docs/features/receipts/architecture.md
 */
final class MboxReadException extends RuntimeException
{
    public static function couldNotOpen(string $path): self
    {
        return new self("MboxIterator: cannot open mbox at {$path}.");
    }

    public static function messageTooLarge(string $path): self
    {
        return new self("MboxIterator: a single message in {$path} exceeds the size cap.");
    }
}
