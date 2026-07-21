<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Dto;

use Spatie\LaravelData\Data;

// Produced by TaxYearQuery; each $categories element is an associative
// array with id/name/shortName/subtotalMinor/rows keys, and each rows[]
// entry carries the full per-transaction tax-year row shape (see
// TaxYearQuery::forYear() for the canonical key list).
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
