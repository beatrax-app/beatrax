<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One source entity the parser could not (or deliberately does not) map onto
 * a beatrax domain concept — surfaced to the user in the wizard's grouped
 * "unmapped" summary (Req 12) rather than silently dropped.
 *
 * `itemType`:
 *  - `'category'` / `'payee'` — a genuinely unresolvable category/payee row.
 *  - `'extra'` — a source concept with no beatrax equivalent at all (a
 *    non-flat Actual `goal_def`, a saved-report/`custom_reports` config row,
 *    a `schedules` row per `MigrationScheduleDto`'s note-only descope).
 *  - `'conflict'` — a 3-way-merge conflict awaiting resolution (Req 10);
 *    the richer `ConflictDto` carries the full local/source/baseline triple,
 *    this entry is the summary-list row the preview/results UI renders.
 */
final class UnmappedItemDto extends Data
{
    public function __construct(
        /** 'category' | 'payee' | 'extra' | 'conflict' */
        public readonly string $itemType,
        public readonly ?string $sourceExternalId,
        public readonly string $displayLabel,
        public readonly string $reason,
    ) {}
}
