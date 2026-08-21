<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Dto;

use Spatie\LaravelData\Data;

final class ReportResultDto extends Data
{
    /**
     * @param  list<ReportResultRow>  $rows
     * @param  ?list<ReportResultRow>  $comparisonRows  Only populated when the driving ReportDefinition has compare = true
     * @param  int  $otherMovementMinor  fees and adjustments over the same period and filters — money no metric counts, reported beside the total rather than folded into it
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly bool $hasExcludedAccounts = false,
        public readonly int $accountsWithoutRate = 0,
        public readonly ?array $comparisonRows = null,
        public readonly int $otherMovementMinor = 0,
    ) {}
}
