<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Outcome of the confirm phase. The wizard's results page renders the
 * "Imported N transactions · skipped M duplicates · P enriched · K errors"
 * summary directly from these counts.
 *
 * IN-04 per-attempt semantics: each ImportConfirmResult describes ONE confirm
 * attempt in isolation. On a re-confirm of an already-`confirmed` run,
 * `inserted` is 0 and the original inserts are reported as `duplicates` (the
 * attempt sees them as already-present) — so `inserted + duplicates` must NOT
 * be summed ACROSS confirm attempts for the same run, or the original inserts
 * double-count. Treat each result independently.
 */
final class ImportConfirmResult extends Data
{
    public function __construct(
        public readonly int $importRunId,
        public readonly int $inserted,
        public readonly int $duplicates,
        public readonly int $enriched,
        public readonly int $errors,
    ) {}
}
