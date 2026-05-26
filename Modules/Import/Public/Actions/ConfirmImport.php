<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;
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
 * cached pending-enrichments list, then inside a single DB transaction:
 *
 *  1. Replays the canonical batch through `RecordsTransactions` (which
 *     silently drops fingerprint duplicates and counts them).
 *  2. Applies the pending-enrichments via `AppliesEnrichments` (which
 *     UPDATEs each existing transactions row with a stronger source_ref
 *     and appends a provenance entry to `enriched_from`).
 *  3. Updates the ImportRun row with the inserted / duplicate / enriched
 *     / error counts and flips `status` to `confirmed`.
 *
 * The transaction wrapping both writers means a recorder failure cannot
 * land enrichments and an enrichment failure cannot land inserts —
 * confirm is atomic across both halves.
 *
 * AFTER the transaction commits the action inserts a `pending`
 * chain_resolution_runs row and dispatches `ResolveChainLinksJob` so
 * the chain resolver pass runs against the freshly-committed state.
 * The dispatch is gated on `$result->inserted > 0 || $result->enriched
 * > 0` — re-confirms and zero-row previews short-circuit. Dispatching
 * INSIDE the closure would let the queue worker pick up the job before
 * SQLite commits, letting it read stale state.
 *
 * Re-confirming an already-confirmed run returns a zero-action result
 * built from the persisted counts, so a refresh/back-button in the
 * wizard cannot double-import or double-enrich. The early-return
 * path skips the chain-resolver dispatch entirely.
 */
final class ConfirmImport implements ConfirmsImports
{
    public function __construct(
        private readonly RecordsTransactions $recorder,
        private readonly AppliesEnrichments $applyEnrichments,
        private readonly PreviewCache $cache,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly DispatchesChainResolution $chainDispatcher,
        private readonly DispatchesRecurringDetection $recurringDispatcher,
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

        /** @var ImportConfirmResult $result */
        $result = $this->db->connection()->transaction(function () use (
            $canonical,
            $enrichments,
            $importRun,
            $user,
            $errorCount,
            $previewDuplicateCount,
        ): ImportConfirmResult {
            $recorderResult = ($this->recorder)($canonical, $user);
            $enrichedCount = ($this->applyEnrichments)($enrichments, $user);

            // The pipeline filters fingerprint-duplicates out of `$canonical`
            // before the recorder runs (so the preview screen can render the
            // badge); the recorder's own `duplicates` only catches race-
            // condition collisions between preview and confirm. Total
            // duplicates = preview-detected + recorder-detected.
            $totalDuplicates = $previewDuplicateCount + $recorderResult->duplicates;

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
        if ($dispatchChain && ($result->inserted > 0 || $result->enriched > 0)) {
            $now = $this->clock->now()->toDateTimeString();
            $this->db->connection()->table('chain_resolution_runs')->insert([
                'user_id' => $user->id,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->chainDispatcher->dispatchForUser($user->id);

            // Recurring-series detection sweep. Same post-commit gate as the
            // chain resolver above (inserts/enrichments only) and the same
            // outside-the-transaction position to avoid the stale-read
            // pitfall. The job's per-user `ShouldBeUniqueUntilProcessing`
            // lock collapses this dispatch with a same-user re-detect click
            // on `/recurring` and with the daily scheduled sweep into a
            // single queued pass.
            $this->recurringDispatcher->dispatchForUser($user->id);
        }

        return $result;
    }
}
