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
use Modules\Migration\Public\Exceptions\MigrationAlreadyDiscardedException;

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

        // WR-02: symmetric guard to DiscardMigrationRun's own
        // already-confirmed check. Staging for a discarded run is already
        // truncated, so letting this fall through to promote() would
        // "succeed" with all-zero counts and silently flip the run's
        // status back to 'confirmed' — corrupting the audit trail and
        // making the phantom run eligible as a CheckForUpdates
        // reconciliation target (see MigrationAlreadyDiscardedException's
        // docblock).
        if ($run->status === 'discarded') {
            throw new MigrationAlreadyDiscardedException($migrationRunId);
        }

        // CR-01: a run left in 'needs_attention' by CheckForUpdates has
        // already had its conflicted budget-assignment rows recorded as
        // `migration_staging_unmapped_items` ('conflict') rows AND skipped
        // once by CheckForUpdates's own promote() call. Re-deriving and
        // re-supplying that SAME skip-list here (rather than promote()'s
        // empty default) is what keeps the user's just-protected "keep
        // local" decision from being silently overwritten the moment they
        // click Confirm on such a run — without this, the second promote()
        // call would unconditionally re-apply the conflicting source value
        // over the local one (D-14's keep-local default defeated).
        $skipBudgetAssignmentKeys = $this->conflictedBudgetAssignmentSkipKeys($migrationRunId, $user);

        // Promote WITHOUT an outer transaction — PromoteStagingToDomain's own
        // per-entity-type writes are already bounded/chunked; wrapping them
        // here would demote those independent commits to savepoints and
        // re-form the unbounded transaction this discipline exists to avoid.
        $promoteResult = $this->promoter->promote($migrationRunId, $user, $skipBudgetAssignmentKeys);

        return $this->db->connection()->transaction(
            fn (): MigrationConfirmResult => $this->flipToConfirmed($run, $promoteResult),
        );
    }

    /**
     * Re-derives the `{categoryExternalId}|{period_start}` composite skip
     * keys for every budget-assignment conflict `CheckForUpdates` recorded
     * against this run (`migration_staging_unmapped_items` rows with
     * `item_type = 'conflict'`). `CheckForUpdates::recordConflict()` writes
     * ONE such row per conflict of ANY entity kind (category/account/
     * transaction/budget_assignment), all sharing `display_label = ucfirst
     * ($entityType).' '.$fieldName`; only the `budget_assignment` ones are
     * relevant here (the composite key shape `promoteBudgetAssignments()`
     * compares against), so the query is scoped to that prefix rather than
     * pulling every conflict's `source_external_id` indiscriminately.
     *
     * @return list<string>
     */
    private function conflictedBudgetAssignmentSkipKeys(int $migrationRunId, User $user): array
    {
        $rows = $this->db->connection()->table('migration_staging_unmapped_items')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->where('item_type', 'conflict')
            ->where('display_label', 'like', 'Budget_assignment %')
            ->pluck('source_external_id');

        /** @var list<string> $keys */
        $keys = [];
        foreach ($rows as $value) {
            if (is_string($value) && $value !== '') {
                $keys[] = $value;
            }
        }

        return $keys;
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
