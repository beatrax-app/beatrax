<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * A scheduled/recurring transaction definition, reachable only from Actual's
 * `schedules` + `rules` tables (YNAB4/nYNAB's CSV export carries none).
 *
 * `Modules\Recurring` has no public write path to create a series from
 * external data (RESEARCH.md Summary finding 2 / Open Question 4) — this
 * phase deliberately descopes schedules to a note-only import: every
 * `MigrationScheduleDto` becomes exactly one `UnmappedItemDto` (surfaced to
 * the user, never silently dropped) AND its `note` is preserved verbatim so
 * the information isn't lost, pending a future `Modules\Recurring` public
 * write-path addition. `note` is a plain human-readable one-line summary
 * (name + rule condition summary) the parser assembles from the source row
 * — NOT a machine-parseable re-import format.
 */
final class MigrationScheduleDto extends Data
{
    public function __construct(
        public readonly string $sourceExternalId,
        public readonly string $name,
        public readonly string $note,
    ) {}
}
