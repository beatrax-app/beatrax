<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\ChainResolutionRun;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Exceptions\PreviewExpiredException;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Recurring\Public\Contracts\DispatchesRecurringDetection;

/**
 * Confirms a previewed import. Loads the cached canonical batch and the
 * cached pending-enrichments list, then:
 *
 *  1. Replays the canonical batch through `RecordsTransactions` (which
 *     silently drops fingerprint duplicates and counts them).
 *  2. Applies the pending-enrichments via `AppliesEnrichments` (which
 *     UPDATEs each existing transactions row with a stronger source_ref
 *     and appends a provenance entry to `enriched_from`).
 *  3. Updates the ImportRun row with the inserted / duplicate / enriched
 *     / error counts and flips `status` to `confirmed`.
 *
 * Bounded recorder + atomic enrichment/status flip (deliberately NOT one
 * giant transaction): a full-year confirm must not run as a single
 * unbounded DB transaction. The recorder therefore runs OUTSIDE any
 * transaction so its bounded per-chunk commits (idempotent via the
 * transaction fingerprint) stay independent and the year-sized batch never
 * collapses into one unbounded transaction. Only the enrichments + the
 * ImportRun status flip + the result assembly are wrapped in ONE
 * `DB::transaction()`, so a committed `confirmed` status ALWAYS implies the
 * enrichment writes for this attempt fully landed.
 *
 * Consequence: a crash mid-confirm can leave recorder rows committed while
 * the run keeps its previous status (the enrichment/status transaction
 * rolled back). Re-running is safe:
 *  - the recorder dedups already-committed rows via their fingerprint
 *    (`insertOrIgnore`), landing only the remainder;
 *  - the enrichment transaction re-applies cleanly — any enrichment writes
 *    from the failed attempt were rolled back with the un-flipped status,
 *    so the replay re-runs them from a clean slate inside a fresh
 *    transaction (and `AppliesEnrichments`'s own per-row rank/exact-ref
 *    short-circuits make even a partial-then-replay sequence a no-op for
 *    already-applied rows).
 * The status flip is atomic with the enrichment writes, and the fan-out
 * dispatch runs only after that transaction has committed.
 *
 * AFTER the transaction commits the action runs a two-stage post-commit
 * block. Re-confirms (status='confirmed' short-circuit above) skip the
 * block entirely.
 *
 *   Stage A — always runs when `$dispatchChain` is true, regardless
 *   of the recorder counts:
 *     1. `UpsertsCardStatements::upsertForImportRun(...)` promotes
 *        every ICS-kind `statement_summaries` row written under this
 *        import_run into a `card_statements` row (positive
 *        open_balance, state=open). The upsert is idempotent — its
 *        UNIQUE constraint is keyed by
 *        (user_id, account_id, period_start, period_end) so re-
 *        running for an all-fingerprint-duplicates import produces
 *        zero inserts, and re-running after a manually-deleted
 *        card_statements row recovers it. Decoupling from the
 *        inserted/enriched gate is what makes that recovery
 *        possible.
 *
 *   Stage B — gated on `$result->inserted > 0 || $result->enriched > 0`
 *   because the chain resolver + recurring sweep have nothing to do
 *   when no canonical rows changed:
 *     2. A `pending` `chain_resolution_runs` row is inserted so the
 *        wizard's wire:poll has a row to display on its first tick.
 *     3. `ResolveChainLinksJob` is dispatched for the chain resolver.
 *     4. `DispatchesRecurringDetection` is invoked for the recurring
 *        series sweep.
 *
 * Dispatching INSIDE the closure would let the queue worker pick up
 * the job before SQLite commits, letting it read stale state.
 *
 * Re-confirming an already-confirmed run returns a zero-action result
 * built from the persisted counts, so a refresh/back-button in the
 * wizard cannot double-import or double-enrich. The early-return
 * path skips the post-commit block entirely (no upsert, no dispatch).
 */
final class ConfirmImport implements ConfirmsImports
{
    public function __construct(
        private readonly RecordsTransactions $recorder,
        private readonly AppliesEnrichments $applyEnrichments,
        private readonly PreviewCache $cache,
        private readonly Clock $clock,
        private readonly DispatchesChainResolution $chainDispatcher,
        private readonly DispatchesRecurringDetection $recurringDispatcher,
        private readonly UpsertsCardStatements $cardStatementUpserter,
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(int $importRunId, User $user, bool $dispatchChain = true): ImportConfirmResult
    {
        /** @var ImportRun $importRun */
        $importRun = ImportRun::query()
            ->where('id', $importRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($importRun->status === 'confirmed') {
            // Re-confirm idempotent result. The SHA-256 short-circuit in
            // RunImport routes a same-file re-upload here, so the caller
            // expects "this attempt as a fresh import" semantics: zero
            // inserts (nothing new lands) and every row in the file
            // counted as a duplicate (it already lives in the ledger).
            // The original confirm persisted `inserted_count` rows and
            // skipped `duplicate_count` collisions, so the file's full
            // row count is the sum — that is the duplicate total a
            // hypothetical re-import would now observe.
            return new ImportConfirmResult(
                importRunId: $importRunId,
                inserted: 0,
                duplicates: $importRun->inserted_count + $importRun->duplicate_count,
                enriched: $importRun->enriched_count,
                errors: 0,
            );
        }

        $canonical = $this->cache->getCanonical($importRunId);
        $enrichments = $this->cache->getEnrichments($importRunId) ?? [];
        $preview = $this->cache->getPreview($importRunId);

        if ($canonical === null) {
            // Cache miss (TTL expired, cache flushed). Leave the import_run
            // row on its previous status so the file's SHA-256 idempotency
            // short-circuit does not lock the user out, and surface a
            // typed exception so the wizard can render a re-upload prompt
            // instead of silently confirming nothing.
            throw new PreviewExpiredException($importRunId);
        }

        $errorCount = 0;
        $previewDuplicateCount = 0;
        if ($preview !== null) {
            foreach ($preview->rows as $row) {
                if ($row->status === 'error') {
                    $errorCount++;
                } elseif ($row->status === 'duplicate') {
                    $previewDuplicateCount++;
                }
            }
        }

        // Promote WITHOUT an outer transaction. The recorder commits in
        // bounded per-chunk transactions (idempotent via the transaction
        // fingerprint); wrapping it here would demote those commits to
        // savepoints and re-form the year-sized transaction this change
        // exists to avoid. A crash after this point leaves committed rows
        // that a re-run safely (idempotently) completes.
        $recorderResult = ($this->recorder)($canonical, $user);

        // The pipeline filters fingerprint-duplicates out of `$canonical`
        // before the recorder runs (so the preview screen can render the
        // badge); the recorder's own `duplicates` only catches race-
        // condition collisions between preview and confirm. Total
        // duplicates = preview-detected + recorder-detected.
        $totalDuplicates = $previewDuplicateCount + $recorderResult->duplicates;

        // Wrap ONLY the enrichments + the status flip + the result assembly
        // in one transaction so a committed `confirmed` status ALWAYS implies
        // the enrichment writes for this attempt fully landed. If the process
        // fails mid-enrichment, this transaction rolls back its enrichment
        // writes together with the un-flipped status, so the run keeps its
        // previous status and a re-confirm replays the enrichments from a
        // clean slate — unconditionally safe even if AppliesEnrichments were
        // not internally idempotent. (`AppliesEnrichments` itself runs each
        // row in its own locked transaction; nested under this outer
        // transaction those become savepoints, which is fine — the
        // enrichment set is bounded by the preview's pending-enrichment list,
        // not by the year-sized canonical batch.)
        $result = $this->db->connection()->transaction(function () use (
            $importRun,
            $enrichments,
            $user,
            $recorderResult,
            $totalDuplicates,
            $errorCount,
        ): ImportConfirmResult {
            $enrichedCount = ($this->applyEnrichments)($enrichments, $user);

            $importRun->update([
                'inserted_count' => $recorderResult->inserted,
                'duplicate_count' => $totalDuplicates,
                'enriched_count' => $enrichedCount,
                'error_count' => $errorCount,
                'confirmed_at' => $this->clock->now(),
                'status' => 'confirmed',
            ]);

            return new ImportConfirmResult(
                importRunId: $importRun->id,
                inserted: $recorderResult->inserted,
                duplicates: $totalDuplicates,
                enriched: $enrichedCount,
                errors: $errorCount,
            );
        });

        $this->cache->forget($importRunId);

        // Dispatch the chain resolver post-commit. NEVER inside the
        // transaction closure: the queue driver does NOT share the
        // SQLite transaction frame, so an in-transaction dispatch
        // would let the worker see stale state.
        //
        // Short-circuit when nothing changed — re-confirming an
        // idempotent file or a zero-row preview produces no work for
        // the resolver. The `inserted` and `enriched` counts come
        // from the recorder + enrichment writers above, so a true
        // no-op confirm (every row already in the ledger) sidesteps
        // the queue dispatch entirely.
        //
        // The pending row is INSERTED before the bus dispatch so the
        // wizard's wire:poll has a row to display on its first tick.
        // The job's handle() then transitions a separate row to
        // `running` and the failed-listener consumes the running row
        // on exhaust — the pending row stays as the user-visible
        // "Resolving chains…" marker until the wizard's next tick
        // observes the running row.
        // Callers that wrap several ConfirmImport invocations inside an
        // outer transaction pass `$dispatchChain = false` so the chain
        // resolver + recurring-detection dispatches do not race the outer
        // commit. They dispatch ONCE themselves after their own transaction
        // returns. The default `true` preserves the legacy single-run
        // behaviour for every existing caller.
        if ($dispatchChain) {
            // Stage A — always promote every ICS-kind
            // statement_summaries row written under this import_run
            // into a card_statements row. The upserter is idempotent
            // (its UNIQUE constraint is keyed by
            // (user_id, account_id, period_start, period_end), so a
            // re-import where every transaction is a fingerprint-
            // duplicate still produces a fresh card_statements row
            // when the matching one was manually deleted). The
            // upsert MUST precede the chain dispatcher so the
            // IcsSettlementResolver pass sees fresh card_statements
            // rows; decoupling it from the
            // inserted/enriched gate is what makes the
            // missing-card_statements recovery path work.
            $this->cardStatementUpserter->upsertForImportRun($importRunId, $user);

            // Stage B — chain + recurring dispatch only fires when
            // the recorder or enrichment writers actually changed
            // ledger state. A true no-op confirm (every row already
            // in the ledger, nothing to enrich) skips the dispatches
            // because the workers have no new work to do.
            if ($result->inserted > 0 || $result->enriched > 0) {
                // ChainResolutionRun is treated as a Public model
                // surface for cross-module reads/writes (the
                // wizard's wire:poll consumes the same table via
                // its own query). Eloquent here so any future
                // cast / boot / default added to the model lands
                // automatically instead of silently NULL via a
                // raw insert.
                ChainResolutionRun::query()->create([
                    'user_id' => $user->id,
                    'status' => 'pending',
                ]);
                $this->chainDispatcher->dispatchForUser($user->id);

                // Recurring-series detection sweep. Same post-commit
                // gate as the chain resolver above (inserts /
                // enrichments only) and the same outside-the-
                // transaction position to avoid the stale-read
                // pitfall. Both dispatchers now run their job via
                // dispatchSync (14.1-04, CRYPT-01) so the decrypt
                // work happens in-process with the KEK available;
                // the queue-only ShouldBeUniqueUntilProcessing lock
                // no longer collapses this with a same-user
                // re-detect click on `/recurring` — both dispatches
                // now run in full, redundantly but idempotently.
                $this->recurringDispatcher->dispatchForUser($user->id);
            }
        }

        return $result;
    }
}
