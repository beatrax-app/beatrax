<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use RuntimeException;

/**
 * Thrown when ConfirmImport is asked to confirm an import whose preview
 * cache has been evicted (TTL expired, cache flushed, server restart with
 * a non-persistent cache driver). Carries the import-run id so the UI can
 * point the user at a re-upload path keyed to the same row.
 *
 * The exception exists to distinguish "preview cache miss" from "preview
 * cache hit with an empty batch" — the latter is a legitimate import of a
 * file whose every row was a duplicate; the former is data loss waiting to
 * happen and must surface to the user.
 */
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
