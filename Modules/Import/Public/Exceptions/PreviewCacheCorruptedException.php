<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use RuntimeException;
use Throwable;

// The key exists but the payload will not decode. Distinct from
// PreviewExpiredException so a routine eviction never reads as a regression.
final class PreviewCacheCorruptedException extends RuntimeException
{
    public function __construct(
        public readonly int $importRunId,
        public readonly string $cacheKey,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Preview cache entry for import run %d at key "%s" is malformed. Re-upload the file to confirm.',
            $importRunId,
            $cacheKey,
        ), 0, $previous);
    }
}
