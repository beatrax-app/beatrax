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

/**
 * Read-side projection driving the consolidated preview surface the
 * FirstImportStep renders after every connector step has stashed its
 * own ImportRun ids. The wizard hands `build()` the union of all
 * stashed ids; the query enforces three boundary rules and groups
 * the survivors into per-source sections backed by the in-memory
 * PreviewCache.
 *
 * Boundary rules (every input id is filtered before any cache read):
 *
 *   1. User-scope — only ImportRuns owned by `$user` survive. A
 *      tampered wizard_progress payload that points at a sibling
 *      user's ImportRun never reaches the cache lookup.
 *      (T-16.1.1-21 mitigation.)
 *   2. Stale window — runs whose `created_at` is older than the
 *      `STALE_WINDOW_DAYS` cutoff are dropped. A forgotten browser
 *      tab whose connector step preview expired weeks ago never
 *      replays last month's data.
 *   3. Already-confirmed — runs whose `status` is `confirmed` are
 *      dropped. Refreshes and back-button navigations cannot
 *      surface an already-committed run on the commit-everything
 *      review screen.
 *
 * Surviving ids are grouped by `source_format` and the cached preview
 * rows for every contributing run are concatenated into the section's
 * `sampleRows` (capped at `SAMPLE_ROW_LIMIT`). `totalRows` counts the
 * NEW-disposition rows the section will insert when the user commits.
 * The batch-level `dedupedTotalCount` and `alreadyImportedCount` are
 * the summed NEW / DUPLICATE counts across every section, used by the
 * commit button label and the reassurance footer.
 *
 * A section emits status `ready` whenever the cache returned at least
 * one row, `empty` when every contributing run returned an empty
 * preview (legitimate — all rows were already in the ledger), and
 * `error` when one or more contributing runs cached `null` (the
 * preview window elapsed before the user reached the FirstImportStep).
 */
final readonly class BuildConsolidatedPreviewQuery
{
    /**
     * Number of days an unconfirmed ImportRun stays eligible for
     * inclusion in the consolidated preview. After this window the
     * row is dropped at the query boundary — the cache TTL on the
     * underlying preview is shorter (30 minutes) so the run already
     * has no rows by the time the day cutoff matters, but applying
     * the filter at the query layer keeps the contract explicit.
     */
    public const int STALE_WINDOW_DAYS = 14;

    /**
     * Maximum number of `sampleRows` per section. Mirrors the
     * existing AliasMatchPreviewQuery five-row UI cap.
     */
    public const int SAMPLE_ROW_LIMIT = 5;

    public function __construct(
        private DatabaseManager $db,
        private PreviewCache $cache,
        private Clock $clock,
    ) {}

    /**
     * Resolve the supplied ImportRun ids into a per-source-format
     * batch, applying the user-scope + stale + already-confirmed
     * filters described in the class doc.
     *
     * @param  list<int>  $importRunIds
     */
    public function build(array $importRunIds, User $user): ConsolidatedPreviewBatch
    {
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
            $section = $this->buildSection($format, $groupedIds[$format]);
            $sections[] = $section;
            $totalNew += $section->totalRows;
            $totalDuplicate += $this->countDuplicateRowsAcrossRuns($groupedIds[$format]);
        }

        return new ConsolidatedPreviewBatch(
            sections: $sections,
            dedupedTotalCount: $totalNew,
            alreadyImportedCount: $totalDuplicate,
        );
    }

    /**
     * Run the three boundary filters in one query: drop ids that do
     * not belong to `$user`, that are older than the stale window,
     * or that are already confirmed. Returns the surviving rows in
     * the original input order keyed on `id` + `source_format`.
     *
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
            ->where('status', '!=', 'confirmed')
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

    /**
     * Build one section for the given source format. Concatenates the
     * cached preview rows across every contributing run, counts NEW
     * dispositions for `totalRows`, takes the first
     * `SAMPLE_ROW_LIMIT` rows for `sampleRows`, and derives the
     * section status from cache hits.
     *
     * @param  list<int>  $importRunIds
     */
    private function buildSection(string $sourceFormat, array $importRunIds): ConsolidatedPreviewSection
    {
        $allRows = [];
        $newRowCount = 0;
        $hasCacheMiss = false;

        foreach ($importRunIds as $runId) {
            $preview = $this->cache->getPreview($runId);
            if ($preview === null) {
                $hasCacheMiss = true;

                continue;
            }
            foreach ($preview->rows as $row) {
                $allRows[] = $row;
                if ($row->status === 'new') {
                    $newRowCount++;
                }
            }
        }

        $status = $this->resolveSectionStatus($hasCacheMiss, $allRows);

        /** @var list<PreviewRowDto> $sampleRows */
        $sampleRows = array_slice($allRows, 0, self::SAMPLE_ROW_LIMIT);

        return new ConsolidatedPreviewSection(
            sourceFormat: $sourceFormat,
            importRunIds: $importRunIds,
            totalRows: $newRowCount,
            sampleRows: $sampleRows,
            status: $status,
        );
    }

    /**
     * `error` when any contributing run's preview cache returned
     * null; `empty` when every preview cached zero rows; `ready`
     * otherwise.
     *
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

        return 'ready';
    }

    /**
     * Count DUPLICATE-disposition rows across every contributing run
     * of a section. Pulled from the same cached preview the
     * `buildSection` pass reads, so cache misses contribute zero
     * (the error status on the section already signals the user
     * needs to re-upload).
     *
     * @param  list<int>  $importRunIds
     */
    private function countDuplicateRowsAcrossRuns(array $importRunIds): int
    {
        $count = 0;
        foreach ($importRunIds as $runId) {
            $preview = $this->cache->getPreview($runId);
            if ($preview === null) {
                continue;
            }
            foreach ($preview->rows as $row) {
                if ($row->status === 'duplicate') {
                    $count++;
                }
            }
        }

        return $count;
    }
}
