<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Exceptions;

use RuntimeException;

// Raised when an mbox archive cannot be opened for streaming — a missing
// path or an unreadable file. Distinct from the blob-write failures:
// this is a read-side fault on the source archive, so no partial output
// has been produced when it surfaces.
final class MboxReadException extends RuntimeException
{
    public static function couldNotOpen(string $path): self
    {
        return new self("MboxIterator: cannot open mbox at {$path}.");
    }

    // Raised only for a caller that gave the iterator nowhere to record a
    // skipped message. One handed a ReceiptCaptureLog gets the messages it
    // could carve out and the ordinal of the one it could not; one that cannot
    // report a skip must not be handed a quietly shorter archive.
    public static function messageTooLarge(string $path): self
    {
        return new self("MboxIterator: a single message in {$path} exceeds the size cap.");
    }
}
