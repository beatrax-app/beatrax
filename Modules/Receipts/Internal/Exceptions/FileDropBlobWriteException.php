<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Exceptions;

use RuntimeException;

// Raised when an atomic .eml blob write fails partway — a refused chmod,
// a short or failed write, or a rename that could not complete. The
// writer unwinds its temp file before this surfaces, so a half-written
// blob is never mistaken for a stored receipt.
final class FileDropBlobWriteException extends RuntimeException
{
    public static function chmodDirectoryFailed(string $directory): self
    {
        return new self("FileDropEmlBlobStore: failed to chmod 0700 on newly-created directory {$directory}.");
    }

    public static function couldNotOpenTempFile(string $tmp): self
    {
        return new self("FileDropEmlBlobStore: could not open temp file at {$tmp}.");
    }

    public static function shortWrite(string $tmp): self
    {
        return new self("FileDropEmlBlobStore: short write to temp file at {$tmp}.");
    }

    public static function chmodTempFileFailed(string $tmp): self
    {
        return new self("FileDropEmlBlobStore: failed to chmod temp file at {$tmp}.");
    }

    public static function atomicRenameFailed(string $tmp, string $absolutePath): self
    {
        return new self("FileDropEmlBlobStore: atomic rename failed from {$tmp} to {$absolutePath}.");
    }

    public static function unexpectedFailure(string $absolutePath): self
    {
        return new self("FileDropEmlBlobStore: unexpected failure writing {$absolutePath}.");
    }
}
