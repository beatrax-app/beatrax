<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use RuntimeException;

// Thrown when the preview cache entry has been evicted (TTL expired,
// flushed, non-persistent cache driver restart) — distinguishes "cache
// miss" from "cache hit with an empty batch" (the latter is a
// legitimate all-duplicates import, not data loss).
final class PreviewExpiredException extends RuntimeException
{
    public function __construct(public readonly int $importRunId)
    {
        parent::__construct(sprintf(
            'Preview cache for import run %d has expired. Re-upload the file to confirm.',
            $importRunId,
        ));
    }
}
