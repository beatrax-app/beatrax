<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use RuntimeException;

// The UniqueConstraintViolationException that routed here proves the row
// was committed, so its absence on an immediate re-read is a genuine
// invariant break rather than a routine miss — raised distinctly so it
// is never swallowed by the generic upload-failure handler.
/**
 * @link ../../../../.docs/features/import/architecture.md#runimport-preview-idempotency--race-recovery
 */
final class RacedImportRunVanishedException extends RuntimeException
{
    public function __construct(
        public readonly int $userId,
        public readonly string $sha256,
    ) {
        parent::__construct(
            'RunImport: import_runs row for the raced key vanished immediately after a unique-constraint violation.',
        );
    }
}
