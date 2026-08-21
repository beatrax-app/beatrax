<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Exceptions;

use RuntimeException;

// The cache entry is gone. Distinct from a cache hit carrying an empty
// batch, which is a legitimate all-duplicates import rather than data loss.
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
