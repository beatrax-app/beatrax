<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Exceptions;

use RuntimeException;

/**
 * Thrown by `DiscardMigrationRun` when the caller attempts to discard a run
 * that has already reached status 'confirmed'. Discarding after confirmation
 * would leave the audit row marked 'discarded' while the domain rows it
 * promoted remain — orphaned audit history. Mirrors
 * `Modules\Import\Public\Exceptions\ImportAlreadyConfirmedException`'s
 * identical guard.
 */
final class MigrationAlreadyConfirmedException extends RuntimeException
{
    public function __construct(public readonly int $migrationRunId)
    {
        parent::__construct(sprintf(
            'Migration run %d is already confirmed; discarding would orphan its promoted domain rows.',
            $migrationRunId,
        ));
    }
}
