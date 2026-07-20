<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use RuntimeException;
use Throwable;

// Thrown when the preview cache key exists but the payload is malformed
// (not the expected JSON string, or doesn't decode into the expected
// DTO shape) — distinct from PreviewExpiredException (key absent / TTL
// elapsed) so a routine eviction is never confused with a cache regression.
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
