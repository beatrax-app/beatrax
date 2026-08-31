<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Reports\Internal\Aggregation\Dto\NetWorthSeriesPoint;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Dto\ReportResultDto;
use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Internal\Enums\ComparisonJoin;
use Modules\Reports\Internal\Enums\ReportDimension;
use Modules\Reports\Internal\Enums\ReportMetricSelection;

final readonly class ReportAggregator
{
    public function __construct(
        private PeriodPresetResolver $periodPresetResolver,
        private CategorySpendQuery $categorySpendQuery,
        private CounterpartySpendQuery $counterpartySpendQuery,
        private AccountSpendQuery $accountSpendQuery,
        private TimeBucketSpendQuery $timeBucketSpendQuery,
        private NetWorthSeriesQuery $netWorthSeriesQuery,
        private CurrencyModeApplier $currencyModeApplier,
        private OtherMovementQuery $otherMovementQuery,
        private PeriodComparison $periodComparison,
        private BaseCurrency $baseCurrency,
        private CrossCurrencyTotal $fx,
    ) {}

    public function run(User $user, ReportDefinition $definition): ReportResultDto
    {
        $period = $this->periodPresetResolver->resolve($definition->periodPreset, $definition->customFrom, $definition->customTo);

        $result = $definition->metric === ReportMetricSelection::NetWorth->value
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
            fn (Period $previousPeriod): ReportResultDto => $definition->metric === ReportMetricSelection::NetWorth->value
                ? $this->buildNetWorthResult($user, $previousPeriod, $definition)
                : $this->buildTransactionResult($user, $previousPeriod, $definition),
            self::joinFor($definition),
        );

        return new ReportResultDto(
            rows: $result->rows,
            totalMinor: $result->totalMinor,
            currency: $result->currency,
            // Unioned, never added: a currency with no rate in both windows is
            // still one currency the reader is missing.
            excludedCurrencies: self::union($result->excludedCurrencies, $comparison['previousExcludedCurrencies']),
            excludedAccountIds: self::union($result->excludedAccountIds, $comparison['previousExcludedAccountIds']),
            comparisonRows: $comparison['rows'],
            otherMovementsByCurrency: $result->otherMovementsByCurrency,
            previousTotalMinor: $comparison['previousTotalMinor'],
            previousCurrency: $comparison['previousCurrency'],
        );
    }

    // Both of these are ordered series whose group key is a date, so no key can
    // survive the shift into the previous window.
    private static function joinFor(ReportDefinition $definition): ComparisonJoin
    {
        return $definition->metric === ReportMetricSelection::NetWorth->value
            || $definition->dimension === ReportDimension::TimeBucket->value
            ? ComparisonJoin::Sequence
            : ComparisonJoin::Group;
    }

    private function buildTransactionResult(User $user, Period $period, ReportDefinition $definition): ReportResultDto
    {
        $filters = $this->filtersFor($user, $definition);
        $boundsForCurrency = fn (string $currency): ?SpendQueryFilters => $this->filtersInCurrency($user, $filters, $currency);

        // Null, not [], for a currency the reader's own amount bound cannot be
        // stated in: no rows is "nothing matched", which is a different answer.
        $queryForCurrency = function (string $currency) use ($user, $period, $definition, $boundsForCurrency): ?array {
            $scoped = $boundsForCurrency($currency);

            return $scoped === null ? null : $this->dimensionRows($user, $period, $definition, $currency, $scoped);
        };

        // discoverCurrencies() needs the dimension query's own filters, or a
        // filtered report discovers currencies that cannot produce rows.
        return $this->currencyModeApplier->apply(
            $user,
            $period,
            $definition->metric,
            $definition->currencyMode,
            $queryForCurrency,
            $filters,
            $this->otherMovementQuery->totalsByCurrency($user, $period, $definition->metric, $filters, $boundsForCurrency),
        );
    }

    // Threaded into every dimension query, so totals, chart, table and CSV
    // cannot silently disagree about an active amount filter. The bounds are
    // parsed at the scale of the currency the reader typed them in -- "20" is
    // twenty yen, not two thousand of them.
    private function filtersFor(User $user, ReportDefinition $definition): SpendQueryFilters
    {
        $readerCurrency = $this->baseCurrency->forUser($user);

        return new SpendQueryFilters(
            accountIds: $definition->accounts,
            categoryIds: $definition->categories,
            counterpartyIds: $definition->counterparties,
            amountMinMinor: self::amountToMinor($definition->amountMin, $readerCurrency),
            amountMaxMinor: self::amountToMinor($definition->amountMax, $readerCurrency),
            amountDirection: $definition->amountDirection,
        );
    }

    // One typed figure means one amount of money, so it is converted into the
    // currency each dimension query is scoped to rather than applied raw to all
    // of them at once -- which made "at least 20" mean EUR 20, USD 20 and 20 yen
    // simultaneously. Null where no rate reaches that currency: never a 1:1.
    private function filtersInCurrency(User $user, SpendQueryFilters $filters, string $currency): ?SpendQueryFilters
    {
        $readerCurrency = $this->baseCurrency->forUser($user);

        if ($currency === $readerCurrency || ! $filters->hasAmountBounds()) {
            return $filters;
        }

        $rates = $this->fx->ratesTo([$readerCurrency], $currency);

        $minMinor = $filters->amountMinMinor === null
            ? null
            : $this->boundInCurrency($filters->amountMinMinor, $readerCurrency, $currency, $rates);

        $maxMinor = $filters->amountMaxMinor === null
            ? null
            : $this->boundInCurrency($filters->amountMaxMinor, $readerCurrency, $currency, $rates);

        // A bound that had a value and lost it in conversion leaves the filter
        // unanswerable. Reporting on the surviving side alone would silently
        // widen the window the reader asked for.
        $lost = ($filters->amountMinMinor !== null && $minMinor === null)
            || ($filters->amountMaxMinor !== null && $maxMinor === null);

        return $lost ? null : $filters->withAmountBounds($minMinor, $maxMinor);
    }

    /**
     * @param  array<string, string>  $rates
     */
    private function boundInCurrency(int $minor, string $from, string $to, array $rates): ?int
    {
        $money = Money::tryOfMinor($minor, $from);

        return $money === null ? null : $this->fx->convert($money, $to, $rates)?->toMinor();
    }

    /**
     * @template TValue of int|string
     *
     * @param  list<TValue>  $current
     * @param  list<TValue>  $previous
     * @return list<TValue>
     */
    private static function union(array $current, array $previous): array
    {
        $merged = array_unique([...$current, ...$previous]);
        sort($merged);

        return $merged;
    }

    /**
     * @return list<ReportResultRow>
     */
    private function dimensionRows(User $user, Period $period, ReportDefinition $definition, string $currency, SpendQueryFilters $filters): array
    {
        return match ($definition->dimension) {
            'category' => $this->categorySpendQuery->forUserAndPeriod($user, $period, $definition->metric, $currency, $filters),
            ReportDimension::Counterparty->value => $this->counterpartySpendQuery->forUserAndPeriod($user, $period, $definition->metric, $currency, $filters),
            ReportDimension::Account->value => $this->accountSpendQuery->forUserAndPeriod($user, $period, $definition->metric, $currency, $filters),
            ReportDimension::TimeBucket->value => $this->timeBucketSpendQuery->forUserAndPeriod($user, $period, $definition->metric, $currency, $definition->granularity, $filters),
            default => throw new InvalidArgumentException("Unknown report dimension: {$definition->dimension}"),
        };
    }

    private static function amountToMinor(?string $amount, string $currency): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return MoneyInput::tryToMinor($amount, $currency);
    }

    private function buildNetWorthResult(User $user, Period $period, ReportDefinition $definition): ReportResultDto
    {
        $points = $this->netWorthSeriesQuery->forUser($user, $period, $definition->granularity, $this->filtersFor($user, $definition));
        $rows = self::pointsToRows($points);

        $totalMinor = 0;
        $currency = $this->baseCurrency->forUser($user);
        // A set of accounts, not a tally of samples: one unconvertible account
        // sampled over 60 buckets is still one account, and adding the counts up
        // told a reader with five accounts that 4108 of them were left out.
        /** @var array<int, true> $excludedAccounts */
        $excludedAccounts = [];
        foreach ($points as $point) {
            // Net worth is a balance, not a flow, so the total is the most recent
            // point; summing would count every account's balance once per bucket.
            // Overwritten each iteration so the last point wins.
            $totalMinor = $point->totalMinor;
            $currency = $point->currency;
            foreach ($point->excludedAccountIds as $accountId) {
                $excludedAccounts[$accountId] = true;
            }
        }

        return new ReportResultDto(
            rows: $rows,
            totalMinor: $totalMinor,
            currency: $currency,
            excludedAccountIds: array_keys($excludedAccounts),
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
