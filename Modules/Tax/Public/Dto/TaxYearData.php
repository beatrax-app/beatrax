<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Dto;

use Spatie\LaravelData\Data;

// Each $categories element has id/name/shortName/subtotalMinor/rows keys; see
// TaxYearQuery::forYear() for the per-row key list.
final class TaxYearData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    public function __construct(
        public readonly int $year,
        public readonly int $deductionsTotalMinor,
        public readonly int $incomeTotalMinor,
        public readonly int $itemCount,
        public readonly array $categories,
    ) {}
}
