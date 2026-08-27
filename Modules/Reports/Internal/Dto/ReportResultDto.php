<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Dto;

use Spatie\LaravelData\Data;

final class ReportResultDto extends Data
{
    /**
     * @param  list<ReportResultRow>  $rows
     * @param  ?list<ReportResultRow>  $comparisonRows  Only populated when the driving ReportDefinition has compare = true
     * @param  array<string, int>  $otherMovementsByCurrency  settled currency => fees/adjustments/uncounted refunds over the same period and filters — money no metric counts, reported beside the total rather than folded into it. Keyed by currency because 'original' mode converts nothing, so a fee bucket outside the headline currency has nowhere else to be said.
     * @param  ?int  $previousTotalMinor  The previous period's own headline total, computed the same way this one is — never re-derived by summing rows, which mixes currencies and adds balances across buckets
     * @param  ?string  $previousCurrency  The currency $previousTotalMinor is denominated in; a delta is only meaningful when it equals $currency
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly bool $hasExcludedAccounts = false,
        public readonly int $accountsWithoutRate = 0,
        public readonly ?array $comparisonRows = null,
        public readonly array $otherMovementsByCurrency = [],
        public readonly ?int $previousTotalMinor = null,
        public readonly ?string $previousCurrency = null,
    ) {}
}
