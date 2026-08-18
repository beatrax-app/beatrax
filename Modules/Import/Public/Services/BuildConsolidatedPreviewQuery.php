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

/**
 * @link ../../../../.docs/features/import/architecture.md#consolidated-preview-multi-run-commit
 */
final readonly class BuildConsolidatedPreviewQuery
{
    // The underlying preview cache TTL (30 min) already empties the row
    // set well before this day-level window matters; filtering here too
    // keeps the contract explicit at the query layer.
    public const int STALE_WINDOW_DAYS = 14;

    // Mirrors the existing AliasMatchPreviewQuery five-row UI cap for
    // the number of sampleRows rendered per section.
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

        // Group surviving ids by their source_format while preserving
        // first-appearance order so section ordering is deterministic
        // for a given input list.
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

        // Preserve caller-supplied ordering so section order is
        // deterministic — first-appearance of each source_format in
        // the input list wins.
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

    // All counts are produced from a single cache read per run,
    // eliminating the TTL-expiry race a second read would introduce.
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

        // A parse that failed contributes exactly one row, and that row is an
        // error carrying the reason. Counted as neither committable nor
        // duplicate, it used to leave the section 'ready' with a total of
        // zero — so a statement the app had correctly refused was presented as
        // read and fine, with a commit button under it.
        foreach ($allRows as $row) {
            if ($row->status !== 'error') {
                return 'ready';
            }
        }

        return 'error';
    }

    /**
     * The reason a section failed, as the parser stated it.
     *
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
