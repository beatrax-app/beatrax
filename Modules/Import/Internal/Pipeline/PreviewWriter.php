<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Modules\Import\Internal\Dto\ImportRowIssue;
use Modules\Import\Internal\Dto\PreviewHead;
use Modules\Import\Internal\Enums\ImportIssueKind;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// Folds a preview into a bounded head as the rows go past, flushing chunks as
// they fill, so the peak does not grow with the file. Every count a screen
// reads is computed here once: the consolidated screen, the wizard's confirm
// gate and the confirm action each walked all the rows for four integers.
/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#preview-vs-confirm
 */
final class PreviewWriter
{
    // What confirming keeps per kind so the results screen can name examples.
    // Read from ConfirmImport, which used to walk the whole row list for them.
    public const int MAX_STORED_ISSUES_PER_KIND = 50;

    /** @var list<PreviewRowDto> */
    private array $rowBuffer = [];

    /** @var list<array<mixed>> */
    private array $canonicalBuffer = [];

    /** @var list<array<mixed>> */
    private array $enrichmentBuffer = [];

    private int $rowChunks = 0;

    private int $canonicalChunks = 0;

    private int $enrichmentChunks = 0;

    private int $rowCount = 0;

    private int $committableCount = 0;

    private int $duplicateCount = 0;

    private int $errorCount = 0;

    private int $enrichedCount = 0;

    private ?ImportFailureReason $firstRowErrorReason = null;

    /** @var list<PreviewRowDto> */
    private array $sampleRows = [];

    /** @var list<ImportRowIssue> */
    private array $rowIssues = [];

    private int $errorIssuesKept = 0;

    private int $duplicateIssuesKept = 0;

    public function __construct(
        private readonly Repository $cache,
        private readonly int $importRunId,
        private readonly CarbonInterface $expiresAt,
        private readonly int $sampleLimit,
    ) {}

    public function addRow(PreviewRowDto $row): void
    {
        $this->rowCount++;

        if ($row->status === PreviewRowStatus::NewRow || $row->status === PreviewRowStatus::Enriched) {
            $this->committableCount++;
        } elseif ($row->status === PreviewRowStatus::Duplicate) {
            $this->duplicateCount++;
        } elseif ($row->status === PreviewRowStatus::Error) {
            $this->errorCount++;
            $this->firstRowErrorReason ??= $row->errorReason;
        }

        if ($row->status === PreviewRowStatus::Enriched) {
            $this->enrichedCount++;
        }

        // The sample stands for what committing writes, and a failed row
        // writes nothing. Shown among the others in a table with no status
        // column, it reads as one more transaction.
        if ($row->status !== PreviewRowStatus::Error && count($this->sampleRows) < $this->sampleLimit) {
            $this->sampleRows[] = $row;
        }

        $this->rememberIssue($row);

        $this->rowBuffer[] = $row;

        if (count($this->rowBuffer) >= PreviewKeys::CHUNK_ROWS) {
            $this->flushRows();
        }
    }

    public function addCanonical(CanonicalTransaction $canonical): void
    {
        $this->canonicalBuffer[] = $canonical->toArray();

        if (count($this->canonicalBuffer) >= PreviewKeys::CHUNK_ROWS) {
            $this->canonicalChunks = $this->flushJson(
                $this->canonicalBuffer,
                PreviewKeys::canonicalChunk($this->importRunId, $this->canonicalChunks),
                $this->canonicalChunks,
            );
        }
    }

    public function addEnrichment(PendingEnrichment $enrichment): void
    {
        $this->enrichmentBuffer[] = $enrichment->toArray();

        if (count($this->enrichmentBuffer) >= PreviewKeys::CHUNK_ROWS) {
            $this->enrichmentChunks = $this->flushJson(
                $this->enrichmentBuffer,
                PreviewKeys::enrichmentChunk($this->importRunId, $this->enrichmentChunks),
                $this->enrichmentChunks,
            );
        }
    }

    /**
     * @param  list<UnknownIban>  $accountsToName
     */
    public function finish(
        array $accountsToName,
        ?ImportFailureReason $fileFailureReason = null,
        ?string $fileFailureDetail = null,
        ?int $fileFailureRowIndex = null,
    ): PreviewHead {
        $this->flushRows();

        // Written unconditionally, even empty: a canonical chunk count of zero
        // has to be distinguishable from a preview that expired, or confirming
        // an all-duplicates import reads as "re-upload the file".
        $this->canonicalChunks = $this->flushJson(
            $this->canonicalBuffer,
            PreviewKeys::canonicalChunk($this->importRunId, $this->canonicalChunks),
            $this->canonicalChunks,
            force: true,
        );
        $this->enrichmentChunks = $this->flushJson(
            $this->enrichmentBuffer,
            PreviewKeys::enrichmentChunk($this->importRunId, $this->enrichmentChunks),
            $this->enrichmentChunks,
            force: true,
        );

        $issues = [];

        if ($fileFailureReason !== null) {
            $issues[] = (new ImportRowIssue(
                kind: ImportIssueKind::FileError,
                rowIndex: $fileFailureRowIndex,
                reason: $fileFailureReason,
                detail: $fileFailureDetail,
            ))->toStored();
        }

        foreach ($this->rowIssues as $issue) {
            $issues[] = $issue->toStored();
        }

        $head = new PreviewHead(
            importRunId: $this->importRunId,
            accountsToName: $accountsToName,
            rowCount: $this->rowCount,
            committableCount: $this->committableCount,
            duplicateCount: $this->duplicateCount,
            errorCount: $this->errorCount,
            enrichedCount: $this->enrichedCount,
            sampleRows: $this->sampleRows,
            sampleComplete: count($this->sampleRows) === $this->rowCount - $this->errorCount,
            rowIssues: $issues,
            rowChunkCount: $this->rowChunks,
            canonicalChunkCount: $this->canonicalChunks,
            enrichmentChunkCount: $this->enrichmentChunks,
            firstRowErrorReason: $this->firstRowErrorReason,
            fileFailureReason: $fileFailureReason,
            fileFailureDetail: $fileFailureDetail,
            fileFailureRowIndex: $fileFailureRowIndex,
        );

        $this->cache->put(PreviewKeys::head($this->importRunId), $head->toArray(), $this->expiresAt);

        return $head;
    }

    // The preview is dropped moments after confirming, so anything the results
    // screen needs to name what it skipped has to be counted here and copied
    // onto the run. Counterparty names and descriptions are deliberately not
    // among it.
    private function rememberIssue(PreviewRowDto $row): void
    {
        if ($row->status === PreviewRowStatus::Error && $this->errorIssuesKept < self::MAX_STORED_ISSUES_PER_KIND) {
            $this->rowIssues[] = new ImportRowIssue(
                kind: ImportIssueKind::RowError,
                rowIndex: $row->rowIndex,
                reason: $row->errorReason,
                detail: $row->errorDetail,
            );
            $this->errorIssuesKept++;
        }

        if ($row->status === PreviewRowStatus::Duplicate && $this->duplicateIssuesKept < self::MAX_STORED_ISSUES_PER_KIND) {
            $this->rowIssues[] = new ImportRowIssue(
                kind: ImportIssueKind::Duplicate,
                rowIndex: $row->rowIndex,
                reason: null,
                detail: null,
            );
            $this->duplicateIssuesKept++;
        }
    }

    private function flushRows(): void
    {
        if ($this->rowBuffer === []) {
            return;
        }

        $this->cache->put(
            PreviewKeys::rowChunk($this->importRunId, $this->rowChunks),
            array_map(static fn (PreviewRowDto $row): array => $row->toArray(), $this->rowBuffer),
            $this->expiresAt,
        );

        $this->rowChunks++;
        $this->rowBuffer = [];
    }

    /**
     * @param  list<array<mixed>>  $buffer
     */
    private function flushJson(array &$buffer, string $key, int $written, bool $force = false): int
    {
        if ($buffer === [] && ! ($force && $written === 0)) {
            return $written;
        }

        $this->cache->put($key, json_encode($buffer, JSON_THROW_ON_ERROR), $this->expiresAt);
        $buffer = [];

        return $written + 1;
    }
}
