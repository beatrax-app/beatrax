<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

/**
 * Outcome of one `PromoteStagingToDomain::promote()` call — the per-entity
 * counts `ConfirmMigration` persists onto `migration_runs` (so a re-confirm
 * of an already-confirmed run can return them without re-promoting) and
 * folds into the Public `MigrationConfirmResult`.
 */
final class PromoteResult
{
    public function __construct(
        public readonly int $categoriesCreated,
        public readonly int $accountsCreated,
        public readonly int $transactionsInserted,
        public readonly int $transactionsSkipped,
        public readonly int $splitsCreated,
        public readonly int $transfersPaired,
        public readonly int $counterpartiesResolved,
        public readonly int $goalsCreated,
    ) {}
}
