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
            // Re-confirm idempotent result: the SHA256 short-circuit in
            // RunImport routes a same-file re-upload here, so this
            // attempt reports zero inserts and the file's full row
            // count (originally inserted + duplicate) as duplicates.
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
            // Cache miss (TTL expired/flushed). Leave the import_run row
            // on its previous status so the SHA256 idempotency short-
            // circuit doesn't lock the user out; surface a typed
            // exception so the wizard renders a re-upload prompt.
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

        // Promote WITHOUT an outer transaction: the recorder commits in
        // bounded, idempotent per-chunk transactions; wrapping it here
        // would demote those to savepoints and re-form the year-sized
        // transaction this design avoids. A crash-then-rerun is safe.
        $recorderResult = ($this->recorder)($canonical, $user);

        // The pipeline already filters fingerprint-duplicates out of
        // `$canonical`; the recorder's own `duplicates` only catches
        // race-condition collisions between preview and confirm, so
        // total duplicates = preview-detected + recorder-detected.
        $totalDuplicates = $previewDuplicateCount + $recorderResult->duplicates;

        // Wrap ONLY the enrichments + status flip + result assembly in one
        // transaction: a committed `confirmed` status ALWAYS implies the
        // enrichment writes landed. A mid-enrichment failure rolls both
        // back together, so a re-confirm replays from a clean slate.
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

        // Dispatch strictly post-commit, never inside the transaction
        // closure (the queue driver doesn't share the SQLite transaction
        // frame). Batched callers pass `$dispatchChain = false` and
        // dispatch once themselves.
        if ($dispatchChain) {
            // Stage A — promotes ICS-kind statement_summaries rows into
            // card_statements (idempotent UNIQUE constraint), decoupled
            // from the inserted/enriched gate so it also recovers a
            // manually-deleted row. See ingestion-pipeline.md#confirm.
            $this->cardStatementUpserter->upsertForImportRun($importRunId, $user);

            // Stage B — chain + recurring dispatch only fires when the
            // recorder or enrichment writers actually changed ledger
            // state; a true no-op confirm skips both dispatches since
            // there is no new work for the workers.
            if ($result->inserted > 0 || $result->enriched > 0) {
                // ChainResolutionRun is a Public model surface (the
                // wizard's wire:poll reads the same table); Eloquent
                // here so any future cast/boot/default lands
                // automatically instead of silently NULL via raw insert.
                ChainResolutionRun::query()->create([
                    'user_id' => $user->id,
                    'status' => 'pending',
                ]);
                $this->chainDispatcher->dispatchForUser($user->id);

                // Recurring-series sweep, same post-commit gate as the
                // chain resolver. Runs via dispatchSync so the decrypt
                // work happens in-process with the KEK available; no
                // longer collapses with a same-user re-detect click.
                $this->recurringDispatcher->dispatchForUser($user->id);
            }
        }

        return $result;
    }
}
