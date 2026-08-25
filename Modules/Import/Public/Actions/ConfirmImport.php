<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Public\Contracts\DispatchesAnomalyDetection;
use Modules\Chains\Models\ChainResolutionRun;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Import\Internal\Dto\ImportRowIssue;
use Modules\Import\Internal\Enums\ImportIssueKind;
use Modules\Import\Internal\Exceptions\PreviewExpiredException;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Contracts\CapturesImportForSync;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Recurring\Public\Contracts\DispatchesRecurringDetection;

/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#confirm-bounded-recorder-and-post-commit-dispatch
 */
final class ConfirmImport implements ConfirmsImports
{
    // The results screen lists what was skipped, and an all-duplicate re-import
    // of a year of statements would otherwise write thousands of entries into
    // one column. Past the cap the screen falls back to the run's own counts.
    private const MAX_STORED_ISSUES_PER_KIND = 50;

    public function __construct(
        private readonly RecordsTransactions $recorder,
        private readonly AppliesEnrichments $applyEnrichments,
        private readonly PreviewCache $cache,
        private readonly Clock $clock,
        private readonly DispatchesAnomalyDetection $anomalyDispatcher,
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
            // A file that failed to parse is one error even though it is no
            // row, so the summary count keeps meaning "things that went wrong"
            // now that the failure is no longer smuggled in as a row.
            $errorCount = $preview->fileFailureReason === null ? 0 : 1;
            foreach ($preview->rows as $row) {
                if ($row->status === PreviewRowStatus::Error) {
                    $errorCount++;
                } elseif ($row->status === PreviewRowStatus::Duplicate) {
                    $previewDuplicateCount++;
                }
            }
        }

        $rowIssues = self::rowIssues($preview);

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

                // Once for the run, not once per row: the job used to be
                // dispatched from a per-transaction event, and its unique key
                // was per-transaction too, so nothing deduplicated.
                $this->anomalyDispatcher->dispatchForImportRun($user->id, $importRunId);
            }
        }

        return $result;
    }

    // The preview cache is dropped moments from here, so anything the results
    // screen needs to name what it skipped has to be copied onto the run
    // first. Counterparty names and descriptions are deliberately not among it.
    /**
     * @return list<array{kind: string, row: int|null, reason: string|null, detail: string|null}>
     */
    private static function rowIssues(?ImportPreviewResult $preview): array
    {
        if ($preview === null) {
            return [];
        }

        $issues = [];
        if ($preview->fileFailureReason !== null) {
            $issues[] = new ImportRowIssue(
                kind: ImportIssueKind::FileError,
                rowIndex: $preview->fileFailureRowIndex,
                reason: $preview->fileFailureReason,
                detail: $preview->fileFailureDetail,
            );
        }

        $errorsKept = 0;
        $duplicatesKept = 0;
        foreach ($preview->rows as $row) {
            if ($row->status === PreviewRowStatus::Error && $errorsKept < self::MAX_STORED_ISSUES_PER_KIND) {
                $issues[] = new ImportRowIssue(
                    kind: ImportIssueKind::RowError,
                    rowIndex: $row->rowIndex,
                    reason: $row->errorReason,
                    detail: $row->errorDetail,
                );
                $errorsKept++;
            }
            if ($row->status === PreviewRowStatus::Duplicate && $duplicatesKept < self::MAX_STORED_ISSUES_PER_KIND) {
                $issues[] = new ImportRowIssue(
                    kind: ImportIssueKind::Duplicate,
                    rowIndex: $row->rowIndex,
                    reason: null,
                    detail: null,
                );
                $duplicatesKept++;
            }
        }

        return array_map(static fn (ImportRowIssue $issue): array => $issue->toStored(), $issues);
    }
}
