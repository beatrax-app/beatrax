<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Top-level outcome of `BuildConsolidatedPreviewQuery::build()`.
 *
 * The FirstImportStep renders the batch as one section per source
 * format plus a single rolled-up footer counting how many new rows
 * the user is about to commit and how many were already in the
 * ledger.
 *
 *  - `sections` is the per-source-format list. Section order matches
 *    the order in which each source format first appeared in the
 *    surviving ImportRun list — deterministic for a given input.
 *  - `dedupedTotalCount` is the sum of NEW-disposition rows across
 *    every section. It is the headline "X new transactions" number
 *    rendered in the commit button copy.
 *  - `alreadyImportedCount` is the sum of DUPLICATE-disposition rows
 *    across every section. The wizard renders this as a reassurance
 *    line ("Y rows were already imported earlier") so the user sees
 *    that re-uploading a statement does not double-count.
 */
final class ConsolidatedPreviewBatch extends Data
{
    /**
     * @param  list<ConsolidatedPreviewSection>  $sections
     */
    public function __construct(
        public readonly array $sections,
        public readonly int $dedupedTotalCount,
        public readonly int $alreadyImportedCount,
    ) {}
}
