<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline;

use Generator;
use Illuminate\Contracts\Cache\Repository;
use JsonException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Dto\PreviewHead;
use Modules\Import\Internal\Dto\PreviewSectionSummary;
use Modules\Import\Internal\Exceptions\PreviewCacheCorruptedException;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// A preview is a bounded head plus chunks of rows. Nothing here reads more of
// a run than it was asked for, which is what lets a statement be larger than
// the memory the device will give the interpreter.
/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#preview-vs-confirm
 */
final readonly class PreviewCache
{
    private const int TTL_MINUTES = 30;

    // What a consolidated section shows without being asked for more, so the
    // screen that lists several runs answers from the head and leaves the row
    // chunks of every one of them unread.
    private const int SUMMARY_SAMPLE_ROWS = BuildConsolidatedPreviewQuery::SAMPLE_ROW_LIMIT;

    // How many rows ride back on an ImportPreviewResult. The callers that hold
    // one -- the upload wizard, and every test that imports a fixture -- want
    // the rows they just produced, not a page of them, and no fixture in this
    // repository is a tenth of this. Past it, rowsAreComplete() says so.
    public const int RESULT_ROW_WINDOW = 500;

    public function __construct(
        private Repository $cache,
        private Clock $clock,
    ) {}

    public function writer(int $importRunId): PreviewWriter
    {
        return new PreviewWriter(
            $this->cache,
            $importRunId,
            $this->clock->now()->addMinutes(self::TTL_MINUTES),
            self::SUMMARY_SAMPLE_ROWS,
        );
    }

    /**
     * @param  list<CanonicalTransaction>  $canonical
     * @param  list<PendingEnrichment>  $enrichments
     */
    public function put(int $importRunId, ImportPreviewResult $result, array $canonical, array $enrichments = []): void
    {
        $writer = $this->writer($importRunId);

        foreach ($result->rows as $row) {
            $writer->addRow($row);
        }
        foreach ($canonical as $transaction) {
            $writer->addCanonical($transaction);
        }
        foreach ($enrichments as $enrichment) {
            $writer->addEnrichment($enrichment);
        }

        $writer->finish(
            array_values($result->accountsToName),
            $result->fileFailureReason,
            $result->fileFailureDetail,
            $result->fileFailureRowIndex,
            $result->receiptCaptures,
            $result->receiptCaptureCount,
        );
    }

    // A malformed-but-present payload throws rather than reading as a miss,
    // so an expired preview stays distinguishable from a cache regression.
    public function head(int $importRunId): ?PreviewHead
    {
        $key = PreviewKeys::head($importRunId);
        $raw = $this->cache->get($key);

        if ($raw === null) {
            return null;
        }

        if (! is_array($raw)) {
            throw new PreviewCacheCorruptedException($importRunId, $key);
        }

        return PreviewHead::from($raw);
    }

    /**
     * @return list<PreviewRowDto>
     */
    public function rows(int $importRunId, int $offset, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $head = $this->head($importRunId);

        if ($head === null) {
            return [];
        }

        $rows = [];
        $position = max(0, $offset);
        $chunk = intdiv($position, PreviewKeys::CHUNK_ROWS);

        while (count($rows) < $limit && $chunk < $head->rowChunkCount) {
            $entries = $this->rowChunk($importRunId, $chunk);
            $within = $chunk === intdiv($position, PreviewKeys::CHUNK_ROWS)
                ? $position % PreviewKeys::CHUNK_ROWS
                : 0;

            for ($i = $within; $i < count($entries) && count($rows) < $limit; $i++) {
                $rows[] = $entries[$i];
            }

            $chunk++;
        }

        return $rows;
    }

    // The window rather than the whole run: what a caller holding one of these
    // wants is the rows it just produced, and rowsAreComplete() is how anything
    // that needs all of them finds out that it does not have them.
    public function getPreview(int $importRunId): ?ImportPreviewResult
    {
        $head = $this->head($importRunId);

        if ($head === null) {
            return null;
        }

        return self::resultFrom($head, $this->rows($importRunId, 0, self::RESULT_ROW_WINDOW));
    }

    /**
     * @param  list<PreviewRowDto>  $rows
     */
    public static function resultFrom(PreviewHead $head, array $rows): ImportPreviewResult
    {
        return new ImportPreviewResult(
            importRunId: $head->importRunId,
            rows: $rows,
            accountsToName: $head->accountsToName,
            enrichedCount: $head->enrichedCount,
            fileFailureReason: $head->fileFailureReason,
            fileFailureDetail: $head->fileFailureDetail,
            fileFailureRowIndex: $head->fileFailureRowIndex,
            totalRowCount: $head->rowCount,
            errorRowCount: $head->errorCount,
            duplicateRowCount: $head->duplicateCount,
            receiptCaptures: $head->receiptCaptures,
            receiptCaptureCount: $head->receiptCaptureCount,
        );
    }

    // What a section of the consolidated screen needs, without the row chunks
    // it was summarised from. A sample bigger than the stored one reads only as
    // many chunks as it takes to fill, never the run.
    public function sectionSummary(int $importRunId, int $sampleLimit): ?PreviewSectionSummary
    {
        $head = $this->head($importRunId);

        if ($head === null) {
            return null;
        }

        $summary = $head->toSectionSummary();

        if ($summary->sampleComplete || count($summary->sampleRows) >= $sampleLimit) {
            return $summary;
        }

        $sample = [];
        $offset = 0;

        while (count($sample) < $sampleLimit) {
            $page = $this->rows($importRunId, $offset, PreviewKeys::CHUNK_ROWS);

            if ($page === []) {
                break;
            }

            foreach ($page as $row) {
                if ($row->status !== PreviewRowStatus::Error && count($sample) < $sampleLimit) {
                    $sample[] = $row;
                }
            }

            $offset += count($page);
        }

        return new PreviewSectionSummary(
            rowCount: $summary->rowCount,
            committableCount: $summary->committableCount,
            duplicateCount: $summary->duplicateCount,
            errorCount: $summary->errorCount,
            sampleRows: $sample,
            sampleComplete: count($sample) === $summary->rowCount - $summary->errorCount,
            firstRowErrorReason: $summary->firstRowErrorReason,
            fileFailureReason: $summary->fileFailureReason,
            fileFailureDetail: $summary->fileFailureDetail,
        );
    }

    // null means confirm has nothing to replay and the user needs a
    // re-upload prompt; an empty list is a legitimate all-duplicates import.
    /**
     * @return Generator<int, list<CanonicalTransaction>>|null
     */
    public function canonicalChunks(int $importRunId): ?Generator
    {
        $head = $this->head($importRunId);

        if ($head === null || $head->canonicalChunkCount === 0) {
            return null;
        }

        return (function () use ($importRunId, $head): Generator {
            for ($chunk = 0; $chunk < $head->canonicalChunkCount; $chunk++) {
                yield array_values(array_map(
                    static fn (array $row): CanonicalTransaction => CanonicalTransaction::from($row),
                    $this->jsonChunk($importRunId, PreviewKeys::canonicalChunk($importRunId, $chunk)),
                ));
            }
        })();
    }

    /**
     * @return list<CanonicalTransaction>|null
     */
    public function getCanonical(int $importRunId): ?array
    {
        $chunks = $this->canonicalChunks($importRunId);

        if ($chunks === null) {
            return null;
        }

        $all = [];

        foreach ($chunks as $chunk) {
            foreach ($chunk as $transaction) {
                $all[] = $transaction;
            }
        }

        return $all;
    }

    /**
     * @return list<PendingEnrichment>|null
     */
    public function getEnrichments(int $importRunId): ?array
    {
        $head = $this->head($importRunId);

        if ($head === null || $head->enrichmentChunkCount === 0) {
            return null;
        }

        $all = [];

        for ($chunk = 0; $chunk < $head->enrichmentChunkCount; $chunk++) {
            foreach ($this->jsonChunk($importRunId, PreviewKeys::enrichmentChunk($importRunId, $chunk)) as $row) {
                $all[] = PendingEnrichment::from($row);
            }
        }

        return $all;
    }

    public function forget(int $importRunId): void
    {
        $head = $this->head($importRunId);

        if ($head !== null) {
            for ($chunk = 0; $chunk < $head->rowChunkCount; $chunk++) {
                $this->cache->forget(PreviewKeys::rowChunk($importRunId, $chunk));
            }
            for ($chunk = 0; $chunk < $head->canonicalChunkCount; $chunk++) {
                $this->cache->forget(PreviewKeys::canonicalChunk($importRunId, $chunk));
            }
            for ($chunk = 0; $chunk < $head->enrichmentChunkCount; $chunk++) {
                $this->cache->forget(PreviewKeys::enrichmentChunk($importRunId, $chunk));
            }
        }

        $this->cache->forget(PreviewKeys::head($importRunId));
    }

    // False rather than a throw on a missing run or an index no row carries,
    // so a stale dispatch is silent. Only the chunk holding the row is
    // rewritten, and the head only when the renamed row is one of the sampled ones.
    public function applyAliasInPlace(int $importRunId, int $rowIndex, string $friendlyName): bool
    {
        $head = $this->head($importRunId);

        if ($head === null || $rowIndex < 0) {
            return false;
        }

        $located = $this->locateRow($importRunId, $head->rowChunkCount, $rowIndex);

        if ($located === null) {
            return false;
        }

        [$chunkIndex, $within, $entries] = $located;

        $updated = self::renamed($entries[$within], $friendlyName);
        $entries[$within] = $updated;
        $expiresAt = $this->clock->now()->addMinutes(self::TTL_MINUTES);

        $this->cache->put(
            PreviewKeys::rowChunk($importRunId, $chunkIndex),
            array_map(static fn (PreviewRowDto $row): array => $row->toArray(), $entries),
            $expiresAt,
        );

        $sample = $head->sampleRows;
        $sampleChanged = false;

        foreach ($sample as $position => $sampled) {
            if ($sampled->rowIndex === $updated->rowIndex) {
                $sample[$position] = $updated;
                $sampleChanged = true;
            }
        }

        if ($sampleChanged) {
            $this->cache->put(
                PreviewKeys::head($importRunId),
                self::withSample($head, $sample)->toArray(),
                $expiresAt,
            );
        }

        return true;
    }

    // PreviewRowDto::$rowIndex is the adapter's index into the SOURCE, not the
    // row's position in the preview: ParseStage's mbox arm counts every message
    // and yields only the ones that parsed, so the two diverge and arithmetic
    // off CHUNK_ROWS addresses a row the reader never clicked.
    /**
     * @return array{int, int, list<PreviewRowDto>}|null
     */
    private function locateRow(int $importRunId, int $chunkCount, int $rowIndex): ?array
    {
        for ($chunk = 0; $chunk < $chunkCount; $chunk++) {
            $entries = $this->rowChunk($importRunId, $chunk);

            foreach ($entries as $position => $entry) {
                if ($entry->rowIndex === $rowIndex) {
                    return [$chunk, $position, $entries];
                }
            }
        }

        return null;
    }

    private static function renamed(PreviewRowDto $existing, string $friendlyName): PreviewRowDto
    {
        return new PreviewRowDto(
            rowIndex: $existing->rowIndex,
            status: $existing->status,
            accountId: $existing->accountId,
            postedAt: $existing->postedAt,
            counterpartyName: $existing->counterpartyName,
            counterpartyIban: $existing->counterpartyIban,
            description: $existing->description,
            amountMinor: $existing->amountMinor,
            currency: $existing->currency,
            error: $existing->error,
            diff: $existing->diff,
            paymentType: $existing->paymentType,
            aliasFriendlyName: $friendlyName,
            errorReason: $existing->errorReason,
            errorDetail: $existing->errorDetail,
        );
    }

    /**
     * @param  list<PreviewRowDto>  $sample
     */
    private static function withSample(PreviewHead $head, array $sample): PreviewHead
    {
        return new PreviewHead(
            importRunId: $head->importRunId,
            accountsToName: $head->accountsToName,
            rowCount: $head->rowCount,
            committableCount: $head->committableCount,
            duplicateCount: $head->duplicateCount,
            errorCount: $head->errorCount,
            enrichedCount: $head->enrichedCount,
            sampleRows: $sample,
            sampleComplete: $head->sampleComplete,
            rowIssues: $head->rowIssues,
            rowChunkCount: $head->rowChunkCount,
            canonicalChunkCount: $head->canonicalChunkCount,
            enrichmentChunkCount: $head->enrichmentChunkCount,
            firstRowErrorReason: $head->firstRowErrorReason,
            fileFailureReason: $head->fileFailureReason,
            fileFailureDetail: $head->fileFailureDetail,
            fileFailureRowIndex: $head->fileFailureRowIndex,
            receiptCaptures: $head->receiptCaptures,
            receiptCaptureCount: $head->receiptCaptureCount,
        );
    }

    /**
     * @return list<PreviewRowDto>
     */
    private function rowChunk(int $importRunId, int $chunk): array
    {
        $key = PreviewKeys::rowChunk($importRunId, $chunk);
        $raw = $this->cache->get($key);

        if ($raw === null) {
            return [];
        }

        if (! is_array($raw)) {
            throw new PreviewCacheCorruptedException($importRunId, $key);
        }

        return array_values(array_map(
            static fn (mixed $row): PreviewRowDto => PreviewRowDto::from($row),
            $raw,
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function jsonChunk(int $importRunId, string $key): array
    {
        $raw = $this->cache->get($key);

        if (! is_string($raw)) {
            throw new PreviewCacheCorruptedException($importRunId, $key);
        }

        try {
            /** @var array<int, array<string, mixed>> $list */
            $list = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PreviewCacheCorruptedException($importRunId, $key, $e);
        }

        return $list;
    }
}
