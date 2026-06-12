<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Compact year summary for sidebar / dashboard tiles.
 *
 * Produced by TaxYearQuery (Plan 02).
 */
final class TaxYearSummary extends Data
{
    public function __construct(
        public readonly int $year,
        public readonly int $totalMinor,
        public readonly int $count,
    ) {}
}
