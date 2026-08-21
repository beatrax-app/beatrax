<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Reports\Internal\Dto\ReportResultDto;
use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Internal\Enums\ReportCurrencyMode;

final class CurrencyModeApplier
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ExchangeRateService $fx,
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
            ->when($categoryIds !== [], static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('category_id', $categoryIds))
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

    /**
     * @param  list<string>  $currencies
     * @param  callable(string $currency): list<ReportResultRow>  $queryForCurrency
     * @param  array<string, int>  $otherTotalsByCurrency
     */
    private function applyBase(User $user, array $currencies, callable $queryForCurrency, array $otherTotalsByCurrency = []): ReportResultDto
    {
        $baseCurrency = $user->base_currency;

        /** @var array<string, array{key: int|string|null, label: string, amount: int}> $merged */
        $merged = [];

        // A set, not a tally: the row loop counted per row and the fee loop per
        // currency, so 12 unconvertible USD rows plus USD fees reported 13.
        /** @var array<string, true> $excludedCurrencies */
        $excludedCurrencies = [];

        foreach ($currencies as $currency) {
            /** @var list<ReportResultRow> $rows */
            $rows = $queryForCurrency($currency);

            foreach ($rows as $row) {
                $money = Money::ofMinor($row->amountMinor, $currency);
                $conversion = $this->fx->convertToBase($money, $baseCurrency);

                // Never a silent 1:1 fallback: a converted currency that still
                // differs from the target means no rate was available at all.
                if ($conversion->converted->currency() !== $baseCurrency) {
                    $excludedCurrencies[$currency] = true;

                    continue;
                }

                $mapKey = self::rowKey($row);
                if (! isset($merged[$mapKey])) {
                    $merged[$mapKey] = ['key' => $row->groupKey, 'label' => $row->groupLabel, 'amount' => 0];
                }
                $merged[$mapKey]['amount'] += $conversion->converted->toMinor();
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
        // only fees produces no rows, so it never reaches the list above.
        $other = 0;
        foreach ($otherTotalsByCurrency as $currency => $amount) {
            $conversion = $this->fx->convertToBase(Money::ofMinor($amount, $currency), $baseCurrency);
            if ($conversion->converted->currency() !== $baseCurrency) {
                // The banner reads ":count not converted", so flagging without
                // counting renders a literal zero beside the warning.
                $excludedCurrencies[$currency] = true;

                continue;
            }
            $other += $conversion->converted->toMinor();
        }

        return new ReportResultDto(
            rows: $resultRows,
            totalMinor: $total,
            currency: $baseCurrency,
            hasExcludedAccounts: $excludedCurrencies !== [],
            accountsWithoutRate: count($excludedCurrencies),
            otherMovementMinor: $other,
        );
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

        $primaryCurrency = $currencies[0] ?? $user->base_currency;
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
            // Nothing is converted in this mode, so only the currency the
            // headline total is denominated in can be reported beside it.
            otherMovementMinor: $otherTotalsByCurrency[$primaryCurrency] ?? 0,
        );
    }

    private static function rowKey(ReportResultRow $row): string
    {
        return $row->groupKey === null ? 'null:'.$row->groupLabel : (string) $row->groupKey;
    }
}
