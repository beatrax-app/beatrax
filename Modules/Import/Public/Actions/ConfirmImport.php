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
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#confirm-bounded-recorder-and-post-commit-dispatch
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
