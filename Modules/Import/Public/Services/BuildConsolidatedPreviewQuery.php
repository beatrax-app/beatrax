<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ConsolidatedPreviewBatch;
use Modules\Import\Public\Dto\ConsolidatedPreviewSection;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Ledger\Public\Enums\ImportRunStatus;

final readonly class BuildConsolidatedPreviewQuery
{
    // The 30-minute cache TTL empties the rows long before this matters;
    // the day-level window keeps the contract explicit at the query layer.
    public const int STALE_WINDOW_DAYS = 14;

    // Mirrors AliasMatchPreviewQuery's five-row cap.
    public const int SAMPLE_ROW_LIMIT = 5;

    public function __construct(
        private DatabaseManager $db,
        private PreviewCache $cache,
        private Clock $clock,
    ) {}

    /**
     * @param  list<int>  $importRunIds
     * @param  array<string, int>  $sectionLimitOverrides  Per-`source_format`
     *                                                     override of the default `SAMPLE_ROW_LIMIT` (5); absent sections keep
     *                                                     the 5-row default; non-positive values are ignored.
     */
    public function build(
        array $importRunIds,
        User $user,
        array $sectionLimitOverrides = [],
    ): ConsolidatedPreviewBatch {
        if ($importRunIds === []) {
            return new ConsolidatedPreviewBatch(
                sections: [],
                dedupedTotalCount: 0,
                alreadyImportedCount: 0,
            );
        }

        $surviving = $this->surviveBoundaryFilters($importRunIds, $user);
        if ($surviving === []) {
            return new ConsolidatedPreviewBatch(
                sections: [],
                dedupedTotalCount: 0,
                alreadyImportedCount: 0,
            );
        }

        // First-appearance order is preserved, so a given input list always
        // produces the same section order.
        $groupedIds = [];
        $orderedFormats = [];
        foreach ($surviving as $row) {
            $format = $row['source_format'];
            if (! array_key_exists($format, $groupedIds)) {
                $groupedIds[$format] = [];
                $orderedFormats[] = $format;
            }
            $groupedIds[$format][] = $row['id'];
        }

        $sections = [];
        $totalNew = 0;
        $totalDuplicate = 0;
        foreach ($orderedFormats as $format) {
            $override = $sectionLimitOverrides[$format] ?? null;
            [$section, $sectionDuplicateCount] = $this->buildSection($format, $groupedIds[$format], $override);
            $sections[] = $section;
            $totalNew += $section->totalRows;
            $totalDuplicate += $sectionDuplicateCount;
        }

        return new ConsolidatedPreviewBatch(
            sections: $sections,
            dedupedTotalCount: $totalNew,
            alreadyImportedCount: $totalDuplicate,
        );
    }

    /**
     * @param  list<int>  $importRunIds
     * @return list<array{id: int, source_format: string}>
     */
    private function surviveBoundaryFilters(array $importRunIds, User $user): array
    {
        $cutoff = $this->clock->now()->subDays(self::STALE_WINDOW_DAYS)->toDateTimeString();

        $rows = $this->db->connection()
            ->table('import_runs')
            ->whereIn('id', $importRunIds)
            ->where('user_id', $user->id)
            ->where('status', '!=', ImportRunStatus::Confirmed->value)
            ->where('created_at', '>=', $cutoff)
            ->select(['id', 'source_format'])
            ->get();

        $bySurvivingId = [];
        foreach ($rows as $row) {
            $id = is_numeric($row->id) ? (int) $row->id : 0;
            $format = is_string($row->source_format) ? $row->source_format : '';
            if ($id > 0 && $format !== '') {
                $bySurvivingId[$id] = ['id' => $id, 'source_format' => $format];
            }
        }

        $ordered = [];
        foreach ($importRunIds as $id) {
            if (array_key_exists($id, $bySurvivingId)) {
                $ordered[] = $bySurvivingId[$id];
            }
        }

        return $ordered;
    }

    // One cache read per run: a second read could cross a TTL expiry.
    /**
     * @param  list<int>  $importRunIds
     * @param  ?int  $override  When non-null and positive, replaces the
     *                          default `SAMPLE_ROW_LIMIT` slice size for this section only.
     *                          Non-positive values fall through to the default 5-row cap.
     * @return array{0: ConsolidatedPreviewSection, 1: int}
     */
    private function buildSection(string $sourceFormat, array $importRunIds, ?int $override = null): array
    {
        $allRows = [];
        $committableRowCount = 0;
        $duplicateRowCount = 0;
        $hasCacheMiss = false;

        foreach ($importRunIds as $runId) {
            $preview = $this->cache->getPreview($runId);
            if ($preview === null) {
                $hasCacheMiss = true;

                continue;
            }
            foreach ($preview->rows as $row) {
                $allRows[] = $row;
                if ($row->status === 'new' || $row->status === 'enriched') {
                    $committableRowCount++;
                } elseif ($row->status === 'duplicate') {
                    $duplicateRowCount++;
                }
            }
        }

        $status = $this->resolveSectionStatus($hasCacheMiss, $allRows);

        $limit = ($override !== null && $override > 0) ? $override : self::SAMPLE_ROW_LIMIT;

        /** @var list<PreviewRowDto> $sampleRows */
        $sampleRows = array_slice($allRows, 0, $limit);

        return [
            new ConsolidatedPreviewSection(
                sourceFormat: $sourceFormat,
                importRunIds: $importRunIds,
                totalRows: $committableRowCount,
                sampleRows: $sampleRows,
                status: $status,
                error: $this->resolveSectionError($allRows),
            ),
            $duplicateRowCount,
        ];
    }

    /**
     * @param  list<PreviewRowDto>  $allRows
     */
    private function resolveSectionStatus(bool $hasCacheMiss, array $allRows): string
    {
        if ($hasCacheMiss) {
            return 'error';
        }
        if ($allRows === []) {
            return 'empty';
        }

        // A failed parse contributes a single error row, which counts as
        // neither committable nor duplicate. Without this the section reads
        // 'ready' with a total of zero and offers a commit button.
        return $this->everyRowFailed($allRows) ? 'error' : 'ready';
    }

    /**
     * @param  list<PreviewRowDto>  $allRows
     */
    private function everyRowFailed(array $allRows): bool
    {
        foreach ($allRows as $row) {
            if ($row->status !== 'error') {
                return false;
            }
        }

        return true;
    }

    // The parser's own reason — which format it expected, what to
    // re-download — carried to the screen rather than only to the log.
    /**
     * @param  list<PreviewRowDto>  $allRows
     */
    private function resolveSectionError(array $allRows): ?string
    {
        foreach ($allRows as $row) {
            if ($row->status === 'error' && is_string($row->error) && $row->error !== '') {
                return $row->error;
            }
        }

        return null;
    }
}
