<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Reports\Internal\Aggregation\Dto\NetWorthSeriesPoint;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Dto\ReportResultDto;
use Modules\Reports\Internal\Dto\ReportResultRow;

final class ReportAggregator
{
    public function __construct(
        private readonly PeriodPresetResolver $periodPresetResolver,
        private readonly CategorySpendQuery $categorySpendQuery,
        private readonly CounterpartySpendQuery $counterpartySpendQuery,
        private readonly AccountSpendQuery $accountSpendQuery,
        private readonly TimeBucketSpendQuery $timeBucketSpendQuery,
        private readonly NetWorthSeriesQuery $netWorthSeriesQuery,
        private readonly CurrencyModeApplier $currencyModeApplier,
        private readonly OtherMovementQuery $otherMovementQuery,
        private readonly PeriodComparison $periodComparison,
    ) {}

    public function run(User $user, ReportDefinition $definition): ReportResultDto
    {
        $period = $this->periodPresetResolver->resolve($definition->periodPreset, $definition->customFrom, $definition->customTo);

        $result = $definition->metric === 'net_worth'
            ? $this->buildNetWorthResult($user, $period, $definition)
            : $this->buildTransactionResult($user, $period, $definition);

        if (! $definition->compare) {
            return $result;
        }

        // The full previous-period DTO, not just ->rows: otherwise an
        // unconvertible previous currency vanishes from the exclusion counters.
        $comparison = $this->periodComparison->compare(
            $period,
            $result->rows,
            fn (Period $previousPeriod): ReportResultDto => $definition->metric === 'net_worth'
                ? $this->buildNetWorthResult($user, $previousPeriod, $definition)
                : $this->buildTransactionResult($user, $previousPeriod, $definition),
        );

        return new ReportResultDto(
            rows: $result->rows,
            totalMinor: $result->totalMinor,
            currency: $result->currency,
            hasExcludedAccounts: $result->hasExcludedAccounts || $comparison['previousHasExcludedAccounts'],
            accountsWithoutRate: $result->accountsWithoutRate + $comparison['previousAccountsWithoutRate'],
            comparisonRows: $comparison['rows'],
            otherMovementMinor: $result->otherMovementMinor,
        );
    }

    private function buildTransactionResult(User $user, Period $period, ReportDefinition $definition): ReportResultDto
    {
        $filters = self::filtersFor($definition);
        $queryForCurrency = fn (string $currency): array => $this->dimensionRows($user, $period, $definition, $currency, $filters);

        // discoverCurrencies() needs the dimension query's own filters, or a
        // filtered report discovers currencies that cannot produce rows.
        return $this->currencyModeApplier->apply(
            $user,
            $period,
            $definition->metric,
            $definition->currencyMode,
            $queryForCurrency,
            $filters,
            $this->otherMovementQuery->totalsByCurrency($user, $period, $definition->metric, $filters),
        );
    }

    // Threaded into every dimension query, so totals, chart, table and CSV
    // cannot silently disagree about an active amount filter.
    private static function filtersFor(ReportDefinition $definition): SpendQueryFilters
    {
        return new SpendQueryFilters(
            accountIds: $definition->accounts,
            categoryIds: $definition->categories,
            counterpartyIds: $definition->counterparties,
            amountMinMinor: self::amountToMinor($definition->amountMin),
            amountMaxMinor: self::amountToMinor($definition->amountMax),
            amountDirection: $definition->amountDirection,
        );
    }

    /**
     * @return list<ReportResultRow>
     */
    private function dimensionRows(User $user, Period $period, ReportDefinition $definition, string $currency, SpendQueryFilters $filters): array
    {
        return match ($definition->dimension) {
            'category' => $this->categorySpendQuery->forUserAndPeriod($user, $period, $definition->metric, $currency, $filters),
            'counterparty' => $this->counterpartySpendQuery->forUserAndPeriod($user, $period, $definition->metric, $currency, $filters),
            'account' => $this->accountSpendQuery->forUserAndPeriod($user, $period, $definition->metric, $currency, $filters),
            'time_bucket' => $this->timeBucketSpendQuery->forUserAndPeriod($user, $period, $definition->metric, $currency, $definition->granularity, $filters),
            default => throw new InvalidArgumentException("Unknown report dimension: {$definition->dimension}"),
        };
    }

    // Mirrors Search's SearchQuery::applyFilters() amountMin/amountMax-to-
    // minor conversion so the two filter UIs behave identically.
    private static function amountToMinor(?string $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return MoneyInput::tryToMinor($amount);
    }

    private function buildNetWorthResult(User $user, Period $period, ReportDefinition $definition): ReportResultDto
    {
        $points = $this->netWorthSeriesQuery->forUser($user, $period, $definition->granularity);
        $rows = self::pointsToRows($points);

        $totalMinor = 0;
        $currency = $user->base_currency;
        $hasExcluded = false;
        $excludedTotal = 0;
        foreach ($points as $point) {
            // Net worth is a balance, not a flow, so the total is the most recent
            // point; summing would count every account's balance once per bucket.
            // Overwritten each iteration so the last point wins.
            $totalMinor = $point->totalMinor;
            $currency = $point->currency;
            if ($point->excludedCount > 0) {
                $hasExcluded = true;
                $excludedTotal += $point->excludedCount;
            }
        }

        return new ReportResultDto(
            rows: $rows,
            totalMinor: $totalMinor,
            currency: $currency,
            hasExcludedAccounts: $hasExcluded,
            accountsWithoutRate: $excludedTotal,
        );
    }

    /**
     * @param  list<NetWorthSeriesPoint>  $points
     * @return list<ReportResultRow>
     */
    private static function pointsToRows(array $points): array
    {
        $rows = [];
        foreach ($points as $point) {
            $rows[] = new ReportResultRow(
                groupKey: $point->date->toDateString(),
                groupLabel: $point->label,
                amountMinor: $point->totalMinor,
                currency: $point->currency,
            );
        }

        return $rows;
    }
}
