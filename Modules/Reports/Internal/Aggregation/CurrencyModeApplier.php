<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Reports\Public\Dto\ReportResultDto;
use Modules\Reports\Public\Dto\ReportResultRow;

/**
 * Applies a report's `currencyMode` ('base' | 'original') to a Plan 04
 * dimension query and assembles the resulting `ReportResultDto` (Req 4/5).
 *
 * Neither this class nor its caller ever hardcodes a currency list: every
 * call FIRST discovers the distinct `settled_currency` values actually
 * present for the user+period+metric via a fresh, user-scoped `SELECT
 * DISTINCT settled_currency` read, then re-runs the caller-supplied
 * dimension query once per discovered currency.
 *
 * 'base' mode: converts each currency's rows via
 * `ExchangeRateService::convertToBase()` and merges same-group rows across
 * currencies into one base-currency total per group. A row whose currency
 * has no available rate is EXCLUDED from the total and COUNTED
 * (`hasExcludedAccounts`/`accountsWithoutRate`) — NEVER a silent 1:1
 * fallback (T-999.6-07). This is the transaction-metric analogue of
 * `NetWorthSeriesQuery`'s own never-1:1 guard — a DISTINCT code path
 * (`CurrencyModeExclusionTest` vs Plan 05's `FxExclusionTest`), because this
 * class converts `ReportResultRow` group totals (already summed in SQL),
 * never per-account balances.
 *
 * 'original' mode: never converts. Every discovered currency's rows are
 * returned unconverted and concatenated — a group present in more than one
 * currency yields one row PER currency (never merged; summing raw minor
 * units across different currencies would silently corrupt the total). No
 * FX exclusion is possible in this mode, so `hasExcludedAccounts`/
 * `accountsWithoutRate` always stay false/0.
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
     */
    public function apply(User $user, Period $period, string $metric, string $currencyMode, callable $queryForCurrency): ReportResultDto
    {
        $currencies = $this->discoverCurrencies($user, $period, $metric);

        return match ($currencyMode) {
            'base' => $this->applyBase($user, $currencies, $queryForCurrency),
            'original' => $this->applyOriginal($user, $currencies, $queryForCurrency),
            default => throw new InvalidArgumentException("Unknown currency mode: {$currencyMode}"),
        };
    }

    /**
     * @return list<string>
     */
    private function discoverCurrencies(User $user, Period $period, string $metric): array
    {
        $values = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('type', self::metricTypes($metric))
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->whereNotNull('settled_currency')
            ->distinct()
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

                // Never a silent 1:1 fallback (T-999.6-07): a passthrough
                // whose converted currency still differs from the target
                // means no rate was available at all — exclude + count,
                // exactly like NetWorthSeriesQuery's own never-1:1 guard.
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

    /**
     * No conversion — every discovered currency's rows are returned
     * unconverted, concatenated across currencies. The DTO-level
     * `currency`/`totalMinor` reflect the FIRST discovered currency's own
     * total only (never summing raw minor units across different
     * currencies, which would silently corrupt the figure) — every
     * currency's true, unconverted total remains available per-row via
     * `rows` regardless of how many currencies are present.
     *
     * @param  list<string>  $currencies
     * @param  callable(string $currency): list<ReportResultRow>  $queryForCurrency
     */
    private function applyOriginal(User $user, array $currencies, callable $queryForCurrency): ReportResultDto
    {
        $resultRows = [];
        $primaryCurrency = $currencies[0] ?? $user->base_currency;
        $total = 0;

        foreach ($currencies as $currency) {
            /** @var list<ReportResultRow> $rows */
            $rows = $queryForCurrency($currency);
            foreach ($rows as $row) {
                $resultRows[] = $row;
                if ($currency === $primaryCurrency) {
                    $total += $row->amountMinor;
                }
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
