<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Generator;
use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Public\Contracts\DispatchesAnomalyDetection;
use Modules\Chains\Models\ChainResolutionRun;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Import\Internal\Exceptions\PreviewExpiredException;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Internal\Services\StatementDerivedRecords;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Contracts\CapturesImportForSync;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Exceptions\ImportNotConfirmableException;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Recurring\Public\Contracts\DispatchesRecurringDetection;

/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#confirm-bounded-recorder-and-post-commit-dispatch
 */
/**
 * @link ../../../../.docs/architecture/measuring-write-cost.md
 */
final readonly class ConfirmImport implements ConfirmsImports
{
    public function __construct(
        private RecordsTransactions $recorder,
        private AppliesEnrichments $applyEnrichments,
        private PreviewCache $cache,
        private Clock $clock,
        private DispatchesAnomalyDetection $anomalyDispatcher,
        private DispatchesChainResolution $chainDispatcher,
        private DispatchesRecurringDetection $recurringDispatcher,
        private StatementDerivedRecords $derivedRecords,
        private DatabaseManager $db,
        private CapturesImportForSync $syncCapture,
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

        $canonical = $this->cache->canonicalChunks($importRunId);
        $enrichments = $this->cache->getEnrichments($importRunId) ?? [];
        $head = $this->cache->head($importRunId);

        if ($canonical === null) {
            // The run keeps its previous status, or RunImport's SHA256
            // short-circuit would lock the user out of re-uploading.
            throw new PreviewExpiredException($importRunId);
        }

        $refusal = $head?->confirmRefusal();

        if ($refusal !== null) {
            throw new ImportNotConfirmableException($importRunId, $refusal);
        }

        $errorCount = 0;
        $previewDuplicateCount = 0;
        if ($head !== null) {
            $errorCount = $head->errorCount;
            $previewDuplicateCount = $head->duplicateCount;
        }

        $rowIssues = $head === null ? [] : $head->rowIssues;

        // Chunk by chunk, never as one list: reading the whole run out of the
        // cache to hand it to a recorder that already buffers what it is given
        // killed the app mid-confirm with nothing written. captureForSync is
        // false because this action captures the run and its parents itself.
        $recorderResult = ($this->recorder)(self::rowsOf($canonical), $user, false);

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
            $rowIssues,
        ): ImportConfirmResult {
            $enrichedCount = ($this->applyEnrichments)($enrichments, $user);

            $importRun->update([
                'inserted_count' => $recorderResult->inserted,
                'duplicate_count' => $totalDuplicates,
                'enriched_count' => $enrichedCount,
                'error_count' => $errorCount,
                'row_issues' => $rowIssues,
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
            // every row is a duplicate still recovers a card statement or a
            // starting balance the reader deleted. RunImport reaches the same
            // call for a re-upload it short-circuits before ever getting here.
            $this->derivedRecords->promoteFor($importRunId, $user);

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

                // Once for the run, not once per row: the job used to be
                // dispatched from a per-transaction event, and its unique key
                // was per-transaction too, so nothing deduplicated.
                $this->anomalyDispatcher->dispatchForImportRun($user->id, $importRunId);
            }
        }

        return $result;
    }

    /**
     * @param  iterable<int, list<CanonicalTransaction>>  $chunks
     * @return Generator<int, CanonicalTransaction>
     */
    private static function rowsOf(iterable $chunks): Generator
    {
        foreach ($chunks as $chunk) {
            yield from $chunk;
        }
    }
}
