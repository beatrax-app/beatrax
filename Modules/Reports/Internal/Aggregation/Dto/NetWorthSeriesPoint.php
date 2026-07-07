<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation\Dto;

use Carbon\CarbonImmutable;

/**
 * One sampled point in a `NetWorthSeriesQuery` result — a base-currency
 * net-worth total for a single `TimeBucketGenerator` bucket's sample date,
 * plus the count of accounts excluded from that total for lack of an FX
 * rate (T-999.6-12 never-1:1 guard, mirrors `NetWorth`'s
 * `hasExcludedAccounts`/`accountsWithoutRate` semantics one point at a
 * time). `ReportAggregator` (Plan 06) maps a list of these into
 * `ReportResultRow`s plus the result-level aggregate fields on
 * `ReportResultDto`.
 *
 * Lives under `Internal/` because the shape is this query's own
 * implementation detail — mirrors the plain-readonly-class precedent of
 * `Modules\Ingestion\Internal\Adapters\Banking\Dto\Mt940BalanceTuple`.
 */
final readonly class NetWorthSeriesPoint
{
    public function __construct(
        public CarbonImmutable $date,
        public string $label,
        public int $totalMinor,
        public string $currency,
        public int $excludedCount,
    ) {}
}
