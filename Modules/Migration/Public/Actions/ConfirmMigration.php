<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Migration\Internal\Pipeline\ConflictRow;
use Modules\Migration\Internal\Pipeline\ConflictValueCodec;
use Modules\Migration\Internal\Pipeline\EntityChangeApplier;
use Modules\Migration\Internal\Pipeline\PromoteResult;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Dto\MigrationConfirmResult;
use Modules\Migration\Public\Exceptions\MigrationAlreadyDiscardedException;
use stdClass;

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
 *
 * 13.5-HUMAN-UAT.md Test 3c gap-fix: this is now the ONLY place a 3-way-merge
 * conflict's resolution is actually applied — `CheckForUpdates` records the
 * conflict (entity identity, field, and the local/source/baseline value
 * triple) but leaves `resolution` NULL, deferring the decision to whatever
 * the user last chose on the preview page (`PreviewMigration::
 * resolveConflict()` persists it onto the SAME `migration_staging_
 * unmapped_items` row). Confirming a `needs_attention` run now:
 *
 *   1. Reads every conflict row for this run. A `budget_assignment` conflict
 *      resolved `'take_source'` is EXCLUDED from the skip-list handed to
 *      `promote()` — `PromoteStagingToDomain::promoteBudgetAssignments()`'s
 *      own unconditional-apply-by-value path then applies the newly-staged
 *      (source) amount via `EnvelopeWriter::setAssigned()` and advances the
 *      baseline itself, exactly as it already does for a non-conflicting
 *      change. Every OTHER resolution (`'keep_local'`/NULL) stays
 *      skip-listed — the CR-01 guarantee this skip-list exists for.
 *   2. Every OTHER conflicted entity kind (category/account/transaction)
 *      resolved `'take_source'` is applied explicitly via the SAME
 *      `EntityChangeApplier::apply()` `CheckForUpdates` itself uses for its
 *      non-conflicting apply-set — one writer per entity kind, not two.
 *      `'keep_local'`/NULL rows are left untouched entirely (`promote()`'s
 *      own per-entity resolve-gate already never revisits an already-mapped
 *      category/account/transaction row).
 *   3. Every conflict's baseline is advanced to whichever value actually
 *      ended up as the beatrax value (local for keep-local, source for
 *      take-source) — for budget_assignment/non-budget take-source rows
 *      this already happened as a side effect of step 1/2's writer calls;
 *      only the keep-local/NULL rows need an explicit
 *      `EntityChangeApplier::advanceBaseline()` call here, since nothing
 *      else touches them.
 *
 * The generic promote() pass continues to skip every conflict NOT resolved
 * `'take_source'` (T-13.5-27) — it never double-applies a take-source
 * decision (budget_assignment's OWN unconditional path is what applies it,
 * exactly once) and never clobbers a keep-local decision.
 */
final class ConfirmMigration
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly PromoteStagingToDomain $promoter,
        private readonly EntityChangeApplier $applier,
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
        // every conflict recorded as a `migration_staging_unmapped_items`
        // ('conflict') row, resolution NULL until the user's toggle choice
        // persists one (Test 3c gap-fix — see class docblock). Loading them
        // here — rather than re-deriving an empty/all-skip default — is
        // what keeps a keep-local decision from being silently overwritten
        // AND is what makes a take-source decision actually apply, exactly
        // once, via the correct writer.
        $conflicts = $this->loadConflicts($migrationRunId, $user);

        $skipBudgetAssignmentKeys = [];
        foreach ($conflicts as $conflict) {
            if ($conflict->entityType === 'budget_assignment' && $conflict->resolution !== 'take_source' && $conflict->sourceExternalId !== null) {
                $skipBudgetAssignmentKeys[] = $conflict->sourceExternalId;
            }
        }

        // Promote WITHOUT an outer transaction — PromoteStagingToDomain's own
        // per-entity-type writes are already bounded/chunked; wrapping them
        // here would demote those independent commits to savepoints and
        // re-form the unbounded transaction this discipline exists to avoid.
        // A `budget_assignment` conflict resolved 'take_source' is NOT in
        // the skip-list above, so this call applies its newly-staged
        // (source) value unconditionally, exactly like a non-conflicting
        // change — no separate apply step is needed for that entity kind.
        $promoteResult = $this->promoter->promote($migrationRunId, $user, $skipBudgetAssignmentKeys);

        $this->applyTakeSourceConflicts($conflicts, $user, $run->source_product);
        $this->advanceKeepLocalConflictBaselines($conflicts, $user, $run->source_product);

        return $this->db->connection()->transaction(
            fn (): MigrationConfirmResult => $this->flipToConfirmed($run, $promoteResult),
        );
    }

    /**
     * @return list<ConflictRow>
     */
    private function loadConflicts(int $migrationRunId, User $user): array
    {
        $rows = $this->db->connection()->table('migration_staging_unmapped_items')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $migrationRunId)
            ->where('item_type', 'conflict')
            ->whereNotNull('entity_type')
            ->get(['entity_type', 'source_external_id', 'field_name', 'local_value', 'source_value', 'resolution']);

        $conflicts = [];
        /** @var stdClass $row */
        foreach ($rows as $row) {
            if (! is_string($row->entity_type) || ! is_string($row->field_name)) {
                continue; // defensive: only a fully-structured conflict row (post gap-fix) is actionable here.
            }

            $conflicts[] = new ConflictRow(
                entityType: $row->entity_type,
                sourceExternalId: is_string($row->source_external_id) ? $row->source_external_id : null,
                fieldName: $row->field_name,
                localValue: is_string($row->local_value) ? $row->local_value : null,
                sourceValue: is_string($row->source_value) ? $row->source_value : null,
                // NULL and 'keep_local' are equivalent (D-14 default) — the
                // user simply never touched the toggle.
                resolution: is_string($row->resolution) ? $row->resolution : 'keep_local',
            );
        }

        return $conflicts;
    }

    /**
     * Applies every non-`budget_assignment` conflict resolved 'take_source'
     * to the domain via the SAME `EntityChangeApplier` `CheckForUpdates`
     * uses for its own non-conflicting apply-set — one writer per entity
     * kind. `budget_assignment` is deliberately excluded here: its
     * take-source application already happened inside `promote()` above
     * (see `__invoke()`'s skip-list construction).
     *
     * @param  list<ConflictRow>  $conflicts
     */
    private function applyTakeSourceConflicts(array $conflicts, User $user, string $sourceProduct): void
    {
        foreach ($conflicts as $conflict) {
            if ($conflict->entityType === 'budget_assignment' || $conflict->resolution !== 'take_source') {
                continue;
            }
            if ($conflict->sourceExternalId === null) {
                continue;
            }

            $value = ConflictValueCodec::fromStorage($conflict->sourceValue, $conflict->fieldName);
            $this->applier->apply($user, $sourceProduct, $conflict->entityType, $conflict->sourceExternalId, [$conflict->fieldName => $value]);
        }
    }

    /**
     * Pins the baseline to the LOCAL value for every conflict NOT resolved
     * 'take_source' (i.e. 'keep_local' or the NULL default) — covers EVERY
     * entity kind, including `budget_assignment` (whose take-source
     * counterpart already got its baseline advanced automatically by
     * `promoteBudgetAssignments()`'s own `SourceMapWriter::record()` call,
     * so it is correctly excluded here by the resolution check alone).
     * Without this, the identical already-decided divergence would
     * re-surface as a fresh conflict on the NEXT reconciliation run.
     *
     * @param  list<ConflictRow>  $conflicts
     */
    private function advanceKeepLocalConflictBaselines(array $conflicts, User $user, string $sourceProduct): void
    {
        foreach ($conflicts as $conflict) {
            if ($conflict->resolution === 'take_source') {
                continue;
            }

            $value = ConflictValueCodec::fromStorage($conflict->localValue, $conflict->fieldName);
            $this->applier->advanceBaseline($user, $sourceProduct, $conflict->entityType, $conflict->sourceExternalId, $conflict->fieldName, $value);
        }
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
