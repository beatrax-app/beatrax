<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Reports\Internal\Dto\ReportResultDto;
use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Internal\Enums\ReportCurrencyMode;

final class CurrencyModeApplier
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CrossCurrencyTotal $fx,
        private readonly BaseCurrency $baseCurrency,
    ) {}

    /**
     * @param  string  $metric  'spend' | 'income' | 'net'
     * @param  callable(string $currency): list<ReportResultRow>  $queryForCurrency  Re-runs the caller's chosen dimension query, scoped to one settled_currency at a time.
     * @param  SpendQueryFilters  $filters  the same accounts/categories/counterparties filters the dimension query itself applies, threaded into discoverCurrencies() too, so a filtered report only discovers currencies that can actually produce rows
     * @param  array<string, int>  $otherTotalsByCurrency  fees and adjustments per settled currency; carried through the same currency decision the rows get so the figure beside the total is denominated the same way
     */
    public function apply(
        User $user,
        Period $period,
        string $metric,
        string $currencyMode,
        callable $queryForCurrency,
        SpendQueryFilters $filters = new SpendQueryFilters,
        array $otherTotalsByCurrency = [],
    ): ReportResultDto {
        $currencies = $this->discoverCurrencies($user, $period, $metric, $filters->accountIds, $filters->categoryIds, $filters->counterpartyIds);

        return match ($currencyMode) {
            ReportCurrencyMode::Base->value => $this->applyBase($user, $currencies, $queryForCurrency, $otherTotalsByCurrency),
            ReportCurrencyMode::Original->value => $this->applyOriginal($user, $currencies, $queryForCurrency, $otherTotalsByCurrency),
            default => throw new InvalidArgumentException("Unknown currency mode: {$currencyMode}"),
        };
    }

    // Filtered exactly as the caller's dimension query is, so a filtered report
    // never discovers a currency that only exists on a filtered-out dimension.
    /**
     * @param  list<int>  $accountIds
     * @param  list<int>  $categoryIds
     * @param  list<int>  $counterpartyIds
     * @return list<string>
     */
    private function discoverCurrencies(User $user, Period $period, string $metric, array $accountIds = [], array $categoryIds = [], array $counterpartyIds = []): array
    {
        $values = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('type', ReportMetric::fromMetric($metric)->types())
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->whereNotNull('settled_currency')
            ->when($accountIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('account_id', $accountIds))
            ->when($categoryIds !== [], static fn (QueryBuilder $q): QueryBuilder => self::whereCategoryOnParentOrLeg($q, $categoryIds))
            ->when($counterpartyIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('counterparty_id', $counterpartyIds))
            ->distinct()
            ->orderBy('settled_currency')
            ->pluck('settled_currency');

        $currencies = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '' && ! in_array($value, $currencies, true)) {
                $currencies[] = $value;
            }
        }

        return $currencies;
    }

    // Splitting a transaction is how part of it is attributed to a category, so
    // the category a leg carries has to discover its parent's currency too. The
    // parent-only predicate found nothing for a category that lives only on
    // legs, and the dimension query was then never called at all.
    /**
     * @param  list<int>  $categoryIds
     */
    private static function whereCategoryOnParentOrLeg(QueryBuilder $query, array $categoryIds): QueryBuilder
    {
        return $query->where(static function (QueryBuilder $q) use ($categoryIds): void {
            $q->whereIn('category_id', $categoryIds)
                ->orWhereExists(static function (QueryBuilder $sub) use ($categoryIds): void {
                    $sub->selectRaw('1')
                        ->from('transaction_splits')
                        ->whereColumn('transaction_splits.transaction_id', 'transactions.id')
                        ->whereIn('transaction_splits.category_id', $categoryIds);
                });
        });
    }

    /**
     * @param  list<string>  $currencies
     * @param  callable(string $currency): list<ReportResultRow>  $queryForCurrency
     * @param  array<string, int>  $otherTotalsByCurrency
     */
    private function applyBase(User $user, array $currencies, callable $queryForCurrency, array $otherTotalsByCurrency = []): ReportResultDto
    {
        $baseCurrency = $this->baseCurrency->forUser($user);

        // One rate per currency for the whole report, fees included: every row
        // a dimension query returns is denominated in the one currency it was
        // asked for, so converting per row read the whole rate table once per
        // row for a rate that could not have changed between them.
        $rates = $this->fx->ratesTo([...$currencies, ...array_keys($otherTotalsByCurrency)], $baseCurrency);

        /** @var array<string, array{key: int|string|null, label: string, amount: int}> $merged */
        $merged = [];

        // A set, not a tally: the row loop counted per row and the fee loop per
        // currency, so 12 unconvertible USD rows plus USD fees reported 13.
        /** @var array<string, true> $excludedCurrencies */
        $excludedCurrencies = [];

        foreach ($currencies as $currency) {
            /** @var list<ReportResultRow> $rows */
            $rows = $queryForCurrency($currency);
            if ($rows === []) {
                continue;
            }

            $converted = $this->convertRowsOfOneCurrency($rows, $currency, $baseCurrency, $rates);

            if ($converted === null) {
                $excludedCurrencies[$currency] = true;

                continue;
            }

            foreach ($converted as $index => $amountMinor) {
                $row = $rows[$index];
                $mapKey = self::rowKey($row);
                if (! isset($merged[$mapKey])) {
                    $merged[$mapKey] = ['key' => $row->groupKey, 'label' => $row->groupLabel, 'amount' => 0];
                }
                $merged[$mapKey]['amount'] += $amountMinor;
            }
        }

        $resultRows = [];
        $total = 0;
        foreach ($merged as $entry) {
            $resultRows[] = new ReportResultRow(
                groupKey: $entry['key'],
                groupLabel: $entry['label'],
                amountMinor: $entry['amount'],
                currency: $baseCurrency,
            );
            $total += $entry['amount'];
        }

        // Fees come from their own query, not $currencies: a currency carrying
        // only fees produces no rows, so it never reaches the list above. The
        // banner reads ":count not converted", so flagging without counting
        // renders a literal zero beside the warning.
        $fees = $this->fx->withRates($otherTotalsByCurrency, $baseCurrency, $rates);
        foreach ($fees->unconverted as $code) {
            $excludedCurrencies[$code] = true;
        }

        return new ReportResultDto(
            rows: $resultRows,
            totalMinor: $total,
            currency: $baseCurrency,
            hasExcludedAccounts: $excludedCurrencies !== [],
            accountsWithoutRate: count($excludedCurrencies),
            otherMovementsByCurrency: $fees->minor === 0 ? [] : [$baseCurrency => $fees->minor],
        );
    }

    // Rounding each group's conversion on its own drifts by up to half a minor
    // unit per group, which put one report at 8942.01 by category and 8942.04 by
    // counterparty. The currency's own subtotal converts once and the remainder
    // is handed back to the rows, so the total cannot depend on the grouping.
    /**
     * @param  list<ReportResultRow>  $rows  all denominated in $currency
     * @param  array<string, string>  $rates
     * @return ?list<int> converted minor units, index-aligned to $rows; null when the pair has no rate
     */
    private function convertRowsOfOneCurrency(array $rows, string $currency, string $baseCurrency, array $rates): ?array
    {
        $rawTotal = 0;
        foreach ($rows as $row) {
            $rawTotal += $row->amountMinor;
        }

        $subtotal = Money::tryOfMinor($rawTotal, $currency);
        $convertedSubtotal = $subtotal === null ? null : $this->fx->convert($subtotal, $baseCurrency, $rates);

        if ($convertedSubtotal === null) {
            return null;
        }

        $converted = [];
        $sumOfRows = 0;
        foreach ($rows as $index => $row) {
            $money = Money::tryOfMinor($row->amountMinor, $currency);
            $rowConverted = $money === null ? null : $this->fx->convert($money, $baseCurrency, $rates);

            if ($rowConverted === null) {
                return null;
            }

            $converted[$index] = $rowConverted->toMinor();
            $sumOfRows += $converted[$index];
        }

        return self::spreadRemainder($converted, $rows, $convertedSubtotal->toMinor() - $sumOfRows);
    }

    // Largest magnitude first, ties broken by position, so the same report
    // always lands the same cents on the same rows.
    /**
     * @param  list<int>  $converted
     * @param  list<ReportResultRow>  $rows
     * @return list<int>
     */
    private static function spreadRemainder(array $converted, array $rows, int $remainder): array
    {
        if ($remainder === 0 || $converted === []) {
            return $converted;
        }

        $order = array_keys($converted);
        usort($order, static fn (int $a, int $b): int => abs($rows[$b]->amountMinor) <=> abs($rows[$a]->amountMinor) ?: $a <=> $b);

        $step = $remainder > 0 ? 1 : -1;
        for ($i = 0; $i < abs($remainder); $i++) {
            $converted[$order[$i % count($order)]] += $step;
        }

        return array_values($converted);
    }

    // No conversion. currency/totalMinor go to whichever currency has the largest
    // absolute total in the actual rows, never a first-discovered guess.
    /**
     * @param  list<string>  $currencies
     * @param  callable(string $currency): list<ReportResultRow>  $queryForCurrency
     * @param  array<string, int>  $otherTotalsByCurrency
     */
    private function applyOriginal(User $user, array $currencies, callable $queryForCurrency, array $otherTotalsByCurrency = []): ReportResultDto
    {
        $resultRows = [];
        /** @var array<string, int> $totalsByCurrency */
        $totalsByCurrency = [];

        foreach ($currencies as $currency) {
            /** @var list<ReportResultRow> $rows */
            $rows = $queryForCurrency($currency);
            $currencyTotal = 0;
            foreach ($rows as $row) {
                $resultRows[] = $row;
                $currencyTotal += $row->amountMinor;
            }
            $totalsByCurrency[$currency] = ($totalsByCurrency[$currency] ?? 0) + $currencyTotal;
        }

        $primaryCurrency = $currencies[0] ?? $this->baseCurrency->forUser($user);
        $total = 0;
        $bestAbsTotal = -1;
        foreach ($totalsByCurrency as $currency => $currencyTotal) {
            if (abs($currencyTotal) > $bestAbsTotal) {
                $bestAbsTotal = abs($currencyTotal);
                $primaryCurrency = $currency;
                $total = $currencyTotal;
            }
        }

        return new ReportResultDto(
            rows: $resultRows,
            totalMinor: $total,
            currency: $primaryCurrency,
            hasExcludedAccounts: false,
            accountsWithoutRate: 0,
            // Every currency, not just the headline one: nothing is converted
            // here, so a fee bucket outside it has no other line to appear on
            // and used to vanish -- a total that omits money reading as all of
            // it, which is the one thing this disclosure exists to prevent.
            otherMovementsByCurrency: array_filter($otherTotalsByCurrency, static fn (int $minor): bool => $minor !== 0),
        );
    }

    private static function rowKey(ReportResultRow $row): string
    {
        return $row->groupKey === null ? 'null:'.$row->groupLabel : (string) $row->groupKey;
    }
}
