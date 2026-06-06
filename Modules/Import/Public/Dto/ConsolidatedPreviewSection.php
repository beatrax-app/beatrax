<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One source-format section in the consolidated preview surface the
 * FirstImportStep renders before the user clicks "Commit everything".
 *
 * The wizard groups the upstream ImportRun ids stashed by the
 * connector steps by their `source_format` and renders one section
 * per group — a section heading ("ASN bank — CAMT.053", "ICS card —
 * PDF statements"), the sampled rows (up to 5 per
 * `BuildConsolidatedPreviewQuery::SAMPLE_ROW_LIMIT`), and a per-
 * section row count.
 *
 *  - `sourceFormat` is the canonical adapter key
 *    (`camt053`, `mt940`, `asn-csv`, `ing-csv`, `ics-pdf`,
 *    `paypal-csv`). The wizard maps it to the user-facing chip label.
 *  - `importRunIds` are the runs whose cached previews contributed to
 *    this section. The wizard does not need them for rendering but
 *    the commit-everything action loops over them when calling
 *    ConfirmImport per run.
 *  - `totalRows` counts rows that will write when the user commits:
 *    NEW rows (inserts) plus ENRICHED rows (updates to existing
 *    transactions). DUPLICATE rows are summarised separately at the
 *    batch level (see ConsolidatedPreviewBatch).
 *  - `sampleRows` is the first 5 rows in run order, used purely for
 *    the visual table preview — never for the commit logic.
 *  - `status` is one of `ready`, `empty`, `error`, `filtered`:
 *      - `ready` — at least one cached preview row is available.
 *      - `empty` — every contributing run cached an empty preview
 *        (legitimate: a statement whose every row was already in the
 *        ledger before this upload).
 *      - `error` — at least one contributing run's preview cache is
 *        missing / expired. The wizard surfaces a re-upload prompt.
 *      - `filtered` — reserved for future per-section opt-outs; the
 *        current query never emits it but the field exists so the
 *        UI contract stays forwards-compatible.
 */
final class ConsolidatedPreviewSection extends Data
{
    /**
     * @param  list<int>  $importRunIds
     * @param  list<PreviewRowDto>  $sampleRows
     */
    public function __construct(
        public readonly string $sourceFormat,
        public readonly array $importRunIds,
        public readonly int $totalRows,
        public readonly array $sampleRows,
        public readonly string $status,
    ) {}
}
