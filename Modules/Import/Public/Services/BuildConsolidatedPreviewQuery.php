<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Internal\Enums\ConfirmRefusal;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ConsolidatedPreviewBatch;
use Modules\Import\Public\Dto\ConsolidatedPreviewSection;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\PreviewSectionStatus;
use Modules\Ledger\Public\Enums\ImportRunStatus;

final readonly class BuildConsolidatedPreviewQuery
{
    // The 30-minute cache TTL empties the rows long before this matters;
    // the day-level window keeps the contract explicit at the query layer.
    public const int STALE_WINDOW_DAYS = 14;

    public const int SAMPLE_ROW_LIMIT = 5;

    // The ceiling an override is clamped to. loadMoreRows() steps 25 at a time
    // from 5, so twenty clicks reach it and nothing a reader does goes past it,
    // while an unbounded override drew every committable row of every section
    // into one fragment on the phone that has to render it.
    public const int MAX_SAMPLE_ROW_LIMIT = 500;

    public function __construct(
        private DatabaseManager $db,
        private PreviewCache $cache,
        private Clock $clock,
    ) {}

    /**
     * @param  list<int>  $importRunIds
     * @param  array<string, int>  $sectionLimitOverrides  Per-`source_format`
     *                                                     override of the default `SAMPLE_ROW_LIMIT` (5); absent sections keep
     *                                                     the 5-row default; non-positive values are ignored, and any
     *                                                     value above `MAX_SAMPLE_ROW_LIMIT` is clamped to it.
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

    /**
     * @param  list<int>  $importRunIds
     * @param  ?int  $override  When non-null and positive, replaces the
     *                          default `SAMPLE_ROW_LIMIT` slice size for this section only.
     *                          Non-positive values fall through to the default 5-row cap,
     *                          and anything above `MAX_SAMPLE_ROW_LIMIT` is clamped to it.
     * @return array{0: ConsolidatedPreviewSection, 1: int}
     */
    private function buildSection(string $sourceFormat, array $importRunIds, ?int $override = null): array
    {
        $limit = ($override !== null && $override > 0)
            ? min($override, self::MAX_SAMPLE_ROW_LIMIT)
            : self::SAMPLE_ROW_LIMIT;

        /** @var list<PreviewRowDto> $sampleRows */
        $sampleRows = [];
        /** @var list<int> $committableRunIds */
        $committableRunIds = [];
        $rowCount = 0;
        $errorRowCount = 0;
        $committableRowCount = 0;
        $duplicateRowCount = 0;
        $leftOutCount = 0;
        $leftOutText = null;
        // The reason, translated, rather than the exception's own words: those
        // name internal classes and the reader's user id, and this is rendered.
        $rowFailureText = null;

        foreach ($importRunIds as $runId) {
            $summary = $this->cache->sectionSummary($runId, $limit);

            if ($summary === null) {
                $leftOutCount++;
                $leftOutText ??= Lang::get('import::preview.refused.preview_expired');

                continue;
            }

            $refusal = $summary->confirmRefusal;

            if ($refusal === ConfirmRefusal::NothingImportable && $summary->rowCount === 0) {
                // A file with no rows in it was not left out -- there was
                // nothing in it to leave. Counted apart from the refusals so a
                // section of nothing but empty statements still reads 'empty'.
                continue;
            }

            if ($refusal !== null) {
                // The whole run drops out, not just the rows past a stop:
                // ConfirmImport refuses it, so counting its rows here would
                // promise the reader an import the commit then cannot make --
                // and one refusal takes every run staged beside it down with it.
                $leftOutCount++;
                // Narrowest true answer first. A run refused because every row
                // failed has no file-level reason, and the refusal's own label
                // only says nothing can be imported -- which the reader can see.
                // The first row's reason is the one that says what to do about
                // it, so it is preferred over the vocabulary of the refusal.
                $leftOutText ??= $summary->fileFailureDetail
                    ?? $summary->fileFailureReason?->label()
                    ?? $summary->firstRowErrorReason?->label()
                    ?? $refusal->label();

                continue;
            }

            $committableRunIds[] = $runId;
            $rowCount += $summary->rowCount;
            $errorRowCount += $summary->errorCount;
            $committableRowCount += $summary->committableCount;
            $duplicateRowCount += $summary->duplicateCount;
            $rowFailureText ??= $summary->firstRowErrorReason?->label();

            foreach ($summary->sampleRows as $row) {
                if (count($sampleRows) >= $limit) {
                    break;
                }
                $sampleRows[] = $row;
            }
        }

        return [
            new ConsolidatedPreviewSection(
                sourceFormat: $sourceFormat,
                importRunIds: $committableRunIds,
                totalRows: $committableRowCount,
                sampleRows: $sampleRows,
                status: self::resolveSectionStatus(count($committableRunIds), $rowCount, $errorRowCount, $leftOutCount),
                error: $leftOutText ?? $rowFailureText,
                leftOutRunCount: $leftOutCount,
            ),
            $duplicateRowCount,
        ];
    }

    private static function resolveSectionStatus(int $committableRunCount, int $rowCount, int $errorRowCount, int $leftOutCount): PreviewSectionStatus
    {
        if ($committableRunCount === 0) {
            // Nothing survived. A run that was left out is a failure the reader
            // has to act on; a section of empty statements is not.
            return $leftOutCount === 0 ? PreviewSectionStatus::Empty : PreviewSectionStatus::Error;
        }
        if ($rowCount === 0) {
            return $leftOutCount > 0 ? PreviewSectionStatus::Error : PreviewSectionStatus::Empty;
        }

        // A row that failed counts as neither committable nor duplicate.
        // Without this the section reads 'ready' with a total of zero and
        // offers a commit button.
        return $errorRowCount === $rowCount ? PreviewSectionStatus::Error : PreviewSectionStatus::Ready;
    }
}
