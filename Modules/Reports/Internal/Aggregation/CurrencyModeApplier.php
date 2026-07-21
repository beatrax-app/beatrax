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
use Modules\Reports\Public\Dto\ReportResultDto;
use Modules\Reports\Public\Dto\ReportResultRow;

/**
 * @link ../../../../.docs/features/reports/architecture.md
 */
final class CurrencyModeApplier
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ExchangeRateService $fx,
    ) {}

    /**
     * @param  string  $metric  'spend' | 'income' | 'net'
     * @param  string  $currencyMode  'base' | 'original'
     * @param  callable(string $currency): list<ReportResultRow>  $queryForCurrency  Re-runs the caller's chosen dimension query, scoped to one settled_currency at a time.
     * @param  list<int>  $accountIds  the same accounts/categories/counterparties filters the dimension query itself applies, threaded into discoverCurrencies() too, so a filtered report only discovers currencies that can actually produce rows
     * @param  list<int>  $categoryIds
     * @param  list<int>  $counterpartyIds
     */
    public function apply(
        User $user,
        Period $period,
        string $metric,
        string $currencyMode,
        callable $queryForCurrency,
        array $accountIds = [],
        array $categoryIds = [],
        array $counterpartyIds = [],
    ): ReportResultDto {
        $currencies = $this->discoverCurrencies($user, $period, $metric, $accountIds, $categoryIds, $counterpartyIds);

        return match ($currencyMode) {
            'base' => $this->applyBase($user, $currencies, $queryForCurrency),
            'original' => $this->applyOriginal($user, $currencies, $queryForCurrency),
            default => throw new InvalidArgumentException("Unknown currency mode: {$currencyMode}"),
        };
    }

    // Applies the same accounts/categories/counterparties filters the
    // caller's dimension query applies, so a filtered report never
    // discovers a currency that only exists on a filtered-out dimension.
    // Ordered deterministically rather than left to unordered DISTINCT.
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
            ->whereIn('type', self::metricTypes($metric))
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
     */
    private function applyBase(User $user, array $currencies, callable $queryForCurrency): ReportResultDto
    {
        $baseCurrency = $user->base_currency;

        /** @var array<string, array{key: int|string|null, label: string, amount: int}> $merged */
        $merged = [];
        $hasExcluded = false;
        $excludedCount = 0;

        foreach ($currencies as $currency) {
            /** @var list<ReportResultRow> $rows */
            $rows = $queryForCurrency($currency);

            foreach ($rows as $row) {
                $money = Money::ofMinor($row->amountMinor, $currency);
                $conversion = $this->fx->convertToBase($money, $baseCurrency);

                // Never a silent 1:1 fallback: a passthrough whose converted
                // currency still differs from the target means no rate was
                // available at all — exclude + count, exactly like
                // NetWorthSeriesQuery's own never-1:1 guard.
                if ($conversion->converted->currency() !== $baseCurrency) {
                    $hasExcluded = true;
                    $excludedCount++;

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

        return new ReportResultDto(
            rows: $resultRows,
            totalMinor: $total,
            currency: $baseCurrency,
            hasExcludedAccounts: $hasExcluded,
            accountsWithoutRate: $excludedCount,
        );
    }

    // No conversion. The DTO-level currency/totalMinor are picked after
    // running every currency's query — the currency with the largest
    // absolute total among the actual result rows, never a "first
    // discovered currency" guess that could land on a filtered-to-zero row.
    /**
     * @param  list<string>  $currencies
     * @param  callable(string $currency): list<ReportResultRow>  $queryForCurrency
     */
    private function applyOriginal(User $user, array $currencies, callable $queryForCurrency): ReportResultDto
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
        );
    }

    private static function rowKey(ReportResultRow $row): string
    {
        return $row->groupKey === null ? 'null:'.$row->groupLabel : (string) $row->groupKey;
    }

    /**
     * @return list<string>
     */
    private static function metricTypes(string $metric): array
    {
        return match ($metric) {
            'spend' => ['expense'],
            'income' => ['income'],
            'net' => ['expense', 'income'],
            default => throw new InvalidArgumentException("Unknown report metric: {$metric}"),
        };
    }
}
