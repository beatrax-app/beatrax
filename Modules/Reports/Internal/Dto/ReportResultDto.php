<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Dto;

use Spatie\LaravelData\Data;

final class ReportResultDto extends Data
{
    /**
     * @param  list<ReportResultRow>  $rows
     * @param  list<string>  $excludedCurrencies  settled currencies left out of a base-mode total because no rate reaches the reader's own — a SET, so the same currency excluded in both compared windows is still one currency
     * @param  list<int>  $excludedAccountIds  accounts left out of a net-worth point for the same reason; a set for the same reason, and a different thing from the currencies above — one field meaning both told a reader with four unconvertible accounts that one account was missing
     * @param  ?list<ReportResultRow>  $comparisonRows  Only populated when the driving ReportDefinition has compare = true
     * @param  array<string, int>  $otherMovementsByCurrency  settled currency => fees/adjustments/uncounted refunds over the same period and filters — money no metric counts, reported beside the total rather than folded into it. Keyed by currency because 'original' mode converts nothing, so a fee bucket outside the headline currency has nowhere else to be said.
     * @param  ?int  $previousTotalMinor  The previous period's own headline total, computed the same way this one is — never re-derived by summing rows, which mixes currencies and adds balances across buckets. Null when the previous window produced nothing and the join reads a missing counterpart as unknown rather than as zero.
     * @param  ?string  $previousCurrency  The currency $previousTotalMinor is denominated in; a delta is only meaningful when it equals $currency
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly array $excludedCurrencies = [],
        public readonly array $excludedAccountIds = [],
        public readonly ?array $comparisonRows = null,
        public readonly array $otherMovementsByCurrency = [],
        public readonly ?int $previousTotalMinor = null,
        public readonly ?string $previousCurrency = null,
    ) {}

    public function hasExclusions(): bool
    {
        return $this->excludedCurrencies !== [] || $this->excludedAccountIds !== [];
    }
}
