<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when ConfirmImport finds the preview cache entry present but
 * malformed: the key exists, but the payload is not the expected JSON
 * string, or its JSON does not decode into the expected DTO shape.
 *
 * This is distinct from `PreviewExpiredException` (cache key absent /
 * TTL elapsed) so the wizard can render a more specific message ("the
 * cached preview is unreadable — please re-upload") and an operator
 * inspecting the logs can tell a routine TTL eviction apart from a
 * cache-backend regression.
 */
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
