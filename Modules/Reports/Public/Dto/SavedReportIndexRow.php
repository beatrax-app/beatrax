<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Dto;

/**
 * Output shape of a single row on the `/reports/library` index (Req 9).
 *
 * `summary` is the pre-rendered "{Metric} · by {dimension} · {period}" line
 * (999.6-UI-SPEC.md Component Inventory item 3, e.g. "Spend · by category ·
 * Last 3 months") — computed once by `SavedReportsQuery` from the row's
 * decoded `definition` JSON so the Blade view never re-derives label maps.
 */
final readonly class SavedReportIndexRow
{
    public function __construct(
        public int $id,
        public string $name,
        public string $summary,
        public bool $pinned,
        public ?int $pinOrder,
    ) {}
}
