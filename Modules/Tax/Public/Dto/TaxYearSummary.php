<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Dto;

use Spatie\LaravelData\Data;

final class TaxYearSummary extends Data
{
    public function __construct(
        public readonly int $year,
        public readonly int $totalMinor,
        public readonly int $count,
    ) {}
}
