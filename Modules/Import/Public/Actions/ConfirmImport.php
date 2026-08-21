<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\ChainResolutionRun;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Import\Internal\Exceptions\PreviewExpiredException;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Contracts\CapturesImportForSync;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Enums\ImportRunStatus;
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
        private readonly CapturesImportForSync $syncCapture,
    ) {}

    public function __invoke(int $importRunId, User $user, bool $dispatchChain = true): ImportConfirmResult
    {
        /** @var ImportRun $importRun */
        $importRun = ImportRun::query()
            ->where('id', $importRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($importRun->status === ImportRunStatus::Confirmed->value) {
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
            // The run keeps its previous status, or RunImport's SHA256
            // short-circuit would lock the user out of re-uploading.
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

        // captureForSync: false — this action captures run, accounts and
        // transactions itself below, parents first. Capturing in the recorder
        // as well wrote every imported row to the op log twice.
        $recorderResult = ($this->recorder)($canonical, $user, false);

        // The pipeline already filtered fingerprint-duplicates out of
        // $canonical, so the recorder's own count holds only the collisions
        // that raced in between preview and confirm. Both are duplicates.
        $totalDuplicates = $previewDuplicateCount + $recorderResult->duplicates;

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
                'status' => ImportRunStatus::Confirmed->value,
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

        // Post-commit, parents first. Without this the ledger rows reached a
        // peer only through the one-time pairing backfill, so a statement
        // imported afterwards stayed on the device that read it.
        $this->syncCapture->capture($importRun, $user);

        if ($dispatchChain) {
            // Outside the inserted/enriched gate below, so a re-import whose
            // every row is a duplicate still recovers a deleted
            // card_statements row.
            $this->cardStatementUpserter->upsertForImportRun($importRunId, $user);

            if ($result->inserted > 0 || $result->enriched > 0) {
                // Eloquent rather than a raw insert, so a cast or boot default
                // added later lands instead of silently writing NULL.
                ChainResolutionRun::query()->create([
                    'user_id' => $user->id,
                    'status' => JobRunStatus::Pending->value,
                ]);
                $this->chainDispatcher->dispatchForUser($user->id);

                // dispatchSync, so the decrypt work runs in-process while the
                // KEK is still available.
                $this->recurringDispatcher->dispatchForUser($user->id);
            }
        }

        return $result;
    }
}
