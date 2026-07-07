<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * The aggregator's output contract — shared verbatim by the report builder
 * table, all four viz partials (table/bar/line/donut), and
 * `ReportCsvExporter`, so none of them can ever disagree about totals.
 *
 * `hasExcludedAccounts`/`accountsWithoutRate` reuse `NetWorth`'s exact field
 * names and semantics (Modules\Forecasting\Public\Dto\NetWorth) so the
 * amber "some accounts excluded" note partial can be shared verbatim: true
 * only when at least one row/account had no available FX rate under
 * `currencyMode = 'base'` and was excluded — counted, never silently
 * defaulted to a 1:1 rate (T-999.6-07 canonical-total guard).
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
