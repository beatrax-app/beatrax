<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One aggregated group row in a `ReportResultDto` — e.g. one category, one
 * counterparty, one account, or one time bucket, depending on the driving
 * `ReportDefinition->dimension`.
 *
 * `groupKey` is the raw identifier the row was grouped by (a category/
 * counterparty/account id, or a time-bucket start date string) — the value
 * drill-down (Req 12) maps back into a `SearchFilters`/`transactions.index`
 * query. `groupLabel` is the human-readable label the table/chart renders.
 *
 * `previousAmountMinor`/`deltaMinor` are only populated when the driving
 * definition has `compare = true` (Req 13); both stay null otherwise.
 */
final class ReportResultRow extends Data
{
    public function __construct(
        public readonly int|string|null $groupKey,
        public readonly string $groupLabel,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?int $previousAmountMinor = null,
        public readonly ?int $deltaMinor = null,
    ) {}
}
