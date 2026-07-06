<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Migration\Internal\Pipeline\PromoteResult;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Dto\MigrationConfirmResult;

/**
 * Confirms a migration run: promotes the staged IR into the user's real
 * domain tables via `PromoteStagingToDomain`, then flips the run to
 * 'confirmed'. Mirrors `Modules\Import\Public\Actions\ConfirmImport`'s
 * bounded-not-atomic discipline exactly (the single most load-bearing analog
 * this plan follows):
 *
 *  1. User-scoped `firstOrFail` lookup + already-confirmed short-circuit —
 *     re-confirming an already-confirmed run returns a zero-action result
 *     built from the counts persisted on the run row, never re-promoting
 *     (T-13.5-20's no-write-before-confirm invariant extends to "no
 *     re-write-after-confirm" for the same reason: a refresh/back-button in
 *     the wizard must not double-promote).
 *  2. `PromoteStagingToDomain::promote()` runs OUTSIDE any transaction — a
 *     multi-year promote must not be one unbounded DB transaction; its own
 *     per-entity-type writes already commit in bounded chunks (mirrors
 *     `RecordTransactions`'s `CHUNK_SIZE = 500` convention).
 *  3. ONLY the status flip + persisted summary counts are wrapped in one
 *     `$db->connection()->transaction()`, so a committed `confirmed` status
 *     ALWAYS implies this attempt's counts were durably recorded.
 *
 * A crash mid-promote leaves whatever chunks already committed; a re-run is
 * safe because `PromoteStagingToDomain` gates every step behind
 * `SourceMapWriter::resolve()` (already-promoted entities are skipped, never
 * re-created) — the SAME idempotency that makes Req 9's "re-run yields zero
 * new rows" hold for a genuinely repeated confirm, not just a repeated
 * top-level export.
 */
final class ConfirmMigration
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly PromoteStagingToDomain $promoter,
    ) {}

    public function __invoke(int $migrationRunId, User $user): MigrationConfirmResult
    {
        /** @var MigrationRun $run */
        $run = MigrationRun::query()
            ->where('id', $migrationRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($run->status === 'confirmed') {
            return new MigrationConfirmResult(
                migrationRunId: $migrationRunId,
                categoriesCreated: $run->categories_count,
                accountsCreated: $run->accounts_count,
                transactionsInserted: $run->transactions_inserted_count,
                transactionsSkipped: $run->transactions_skipped_count,
                splitsCreated: $run->splits_count,
                transfersPaired: $run->transfers_paired_count,
                counterpartiesResolved: $run->counterparties_resolved_count,
                goalsCreated: $run->goals_created_count,
            );
        }

        // Promote WITHOUT an outer transaction — PromoteStagingToDomain's own
        // per-entity-type writes are already bounded/chunked; wrapping them
        // here would demote those independent commits to savepoints and
        // re-form the unbounded transaction this discipline exists to avoid.
        $promoteResult = $this->promoter->promote($migrationRunId, $user);

        return $this->db->connection()->transaction(
            fn (): MigrationConfirmResult => $this->flipToConfirmed($run, $promoteResult),
        );
    }

    private function flipToConfirmed(MigrationRun $run, PromoteResult $promoteResult): MigrationConfirmResult
    {
        $run->update([
            'status' => 'confirmed',
            'confirmed_at' => $this->clock->now(),
            'categories_count' => $promoteResult->categoriesCreated,
            'accounts_count' => $promoteResult->accountsCreated,
            'transactions_inserted_count' => $promoteResult->transactionsInserted,
            'transactions_skipped_count' => $promoteResult->transactionsSkipped,
            'splits_count' => $promoteResult->splitsCreated,
            'transfers_paired_count' => $promoteResult->transfersPaired,
            'counterparties_resolved_count' => $promoteResult->counterpartiesResolved,
            'goals_created_count' => $promoteResult->goalsCreated,
        ]);

        return new MigrationConfirmResult(
            migrationRunId: $run->id,
            categoriesCreated: $promoteResult->categoriesCreated,
            accountsCreated: $promoteResult->accountsCreated,
            transactionsInserted: $promoteResult->transactionsInserted,
            transactionsSkipped: $promoteResult->transactionsSkipped,
            splitsCreated: $promoteResult->splitsCreated,
            transfersPaired: $promoteResult->transfersPaired,
            counterpartiesResolved: $promoteResult->counterpartiesResolved,
            goalsCreated: $promoteResult->goalsCreated,
        );
    }
}
