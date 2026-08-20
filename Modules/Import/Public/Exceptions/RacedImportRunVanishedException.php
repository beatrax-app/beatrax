<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use RuntimeException;

// The UniqueConstraintViolationException that routed here proves the row
// committed, so its absence on the re-read is an invariant break. Typed so
// the generic upload-failure handler cannot swallow it.
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
