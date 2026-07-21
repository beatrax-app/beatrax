<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Exceptions;

use RuntimeException;

// Thrown by ConfirmMigration when the caller attempts to confirm an
// already-discarded run — staging is already truncated, so a confirm would
// silently flip the run back to 'confirmed' with all-zero counts, corrupting
// the state machine (symmetric to MigrationAlreadyConfirmedException).
final class MigrationAlreadyDiscardedException extends RuntimeException
{
    public function __construct(public readonly int $migrationRunId)
    {
        parent::__construct(sprintf(
            'Migration run %d is already discarded; confirming would flip its status back to confirmed with no real promoted rows.',
            $migrationRunId,
        ));
    }
}
