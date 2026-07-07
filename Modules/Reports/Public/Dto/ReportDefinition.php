<?php

declare(strict_types=1);

namespace Modules\Reports\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * The full, user-composed report definition — metric x dimension x period x
 * filters x currency mode x viz x compare. This is the exact shape persisted
 * as `saved_reports.definition` JSON (Req 9): saving a report is
 * `$definition->toArray()` into that column, reopening it is
 * `ReportDefinition::from($row->definition)`.
 *
 * `ReportAggregator::run()` (later waves) takes this DTO plus a User and
 * returns a `ReportResultDto`. `ReportCsvExporter::export()` and every viz
 * partial read the same DTO, so the builder table, the four chart forms, and
 * the CSV export can never disagree about what was asked for.
 *
 * T-999.6-06: this DTO is a transport contract only, never a trust boundary
 * — filter ids (`accounts`/`categories`/`counterparties`) carried in a
 * persisted/reopened definition are re-validated against the current user's
 * ownership at query time by the aggregation queries (Plans 04/06), exactly
 * like `SearchFilters` is re-validated by `SearchQuery::applyFilters()`.
 */
final class ReportDefinition extends Data
{
    /**
     * @param  string  $metric  'spend' | 'income' | 'net' | 'net_worth'
     * @param  string  $dimension  'category' | 'time_bucket' | 'counterparty' | 'account'
     * @param  string  $periodPreset  'this_month' | 'last_3_months' | 'last_6_months' | 'last_12_months' | 'ytd' | 'this_year' | 'custom'
     * @param  string  $granularity  'monthly' | 'weekly'
     * @param  string  $currencyMode  'base' | 'original'
     * @param  string  $viz  'table' | 'bar' | 'line' | 'donut'
     * @param  ?string  $customFrom  ISO date string (Y-m-d), only meaningful when periodPreset = 'custom'
     * @param  ?string  $customTo  ISO date string (Y-m-d), only meaningful when periodPreset = 'custom'
     * @param  list<int>  $accounts  Account IDs to restrict results to (empty = all)
     * @param  list<int>  $categories  Category IDs to restrict results to (empty = all)
     * @param  list<int>  $counterparties  Counterparty IDs to restrict results to (empty = all)
     * @param  ?string  $amountMin  Minimum absolute amount as decimal string (e.g. "10.00")
     * @param  ?string  $amountMax  Maximum absolute amount as decimal string (e.g. "500.00")
     * @param  string  $amountDirection  'in' | 'out' | 'both'
     */
    public function __construct(
        public readonly string $metric,
        public readonly string $dimension,
        public readonly string $periodPreset,
        public readonly string $granularity,
        public readonly string $currencyMode,
        public readonly string $viz,
        public readonly ?string $customFrom = null,
        public readonly ?string $customTo = null,
        public readonly bool $compare = false,
        public readonly array $accounts = [],
        public readonly array $categories = [],
        public readonly array $counterparties = [],
        public readonly ?string $amountMin = null,
        public readonly ?string $amountMax = null,
        public readonly string $amountDirection = 'both',
    ) {}

    /**
     * Deterministic kebab-case slug for CSV download filenames, e.g.
     * "spend-category-this-month". Never localized/translated — purely a
     * filesystem-safe identifier derived from the definition's own field
     * values (no random/time component, so the same definition always
     * yields the same slug).
     */
    public function slug(): string
    {
        $parts = [$this->metric, $this->dimension, $this->periodPreset];

        return implode('-', array_map(
            static fn (string $part): string => str_replace('_', '-', strtolower($part)),
            $parts,
        ));
    }
}
