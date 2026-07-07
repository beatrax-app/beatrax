<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Exceptions;

use RuntimeException;

/**
 * Thrown by `ConfirmMigration` when the caller attempts to confirm a run
 * that has already reached status 'discarded'. `DiscardMigrationRun`
 * truncates every `migration_staging_*` row for the run, so a subsequent
 * confirm would silently flip the run's own audit trail back to
 * 'confirmed' with all-zero counts (no real domain writes happen, since
 * staging is already empty) — corrupting the run's state-machine invariant
 * and making the phantom "confirmed" run eligible as a `CheckForUpdates`
 * reconciliation target. Mirrors `MigrationAlreadyConfirmedException`'s
 * symmetric guard at the other end of the state machine.
 */
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
