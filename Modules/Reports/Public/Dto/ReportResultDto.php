<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/reports/architecture.md
 */
final class ReportResultDto extends Data
{
    /**
     * @param  list<ReportResultRow>  $rows
     * @param  ?list<ReportResultRow>  $comparisonRows  Only populated when the driving ReportDefinition has compare = true
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly bool $hasExcludedAccounts = false,
        public readonly int $accountsWithoutRate = 0,
        public readonly ?array $comparisonRows = null,
    ) {}
}
