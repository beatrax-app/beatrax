<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Services\AccountStartingBalanceQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
use stdClass;

/**
 * @link ../../../../.docs/features/calendar/architecture.md#balance-aggregation
 */
final readonly class DailyBalanceAggregator
{
    use CoercesScalars;

    private const int FORECAST_HORIZON_DAYS = 365;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private ForecastQuery $forecastQuery,
        private CrossCurrencyTotal $fx,
        private AccountStartingBalanceQuery $startingBalances,
        private BaseCurrency $baseCurrencies,
    ) {}

    /**
     * @param  list<int>  $effectiveBalance
     * @return array{map: array<string, array{0: int, 1: bool}>, todayAnchorMinor: int|null}
     */
    public function buildBalanceMap(
        array $effectiveBalance,
        User $user,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): array {
        if ($effectiveBalance === []) {
            return ['map' => $this->emptyComputingMap($monthStart, $monthEnd), 'todayAnchorMinor' => null];
        }

        $baseCurrency = $this->baseCurrencies->forUser($user);
        ['byDateCurrency' => $byDateCurrency, 'isComputingAny' => $isComputingAny, 'anchorByCurrency' => $anchorByCurrency, 'hasAnchor' => $hasAnchor]
            = $this->collectForecastBuckets($effectiveBalance, $user);
        $overlay = $this->collectOverlayBuckets($effectiveBalance, $user, $monthStart, $monthEnd);

        // Every bucket the whole month will convert, priced in one pass: the
        // rate a day needs is the rate every other day needs, and the service
        // behind it reads the entire exchange_rates table per call.
        $rates = $this->fx->ratesTo(self::currenciesIn([
            ...array_values($byDateCurrency),
            $anchorByCurrency,
            ...($overlay === null ? [] : [$overlay['cumByCurrency'], ...array_values($overlay['deltaByDateCurrency'])]),
        ]), $baseCurrency);

        $map = $this->bucketsToBalanceMap($byDateCurrency, $baseCurrency, $rates, $isComputingAny);

        if ($isComputingAny) {
            $map = $this->applyComputingSentinel($map, $monthStart, $monthEnd);
        }

        if ($overlay !== null) {
            $map = $this->overlayActualBalances($map, $overlay, $baseCurrency, $rates);
        }

        $todayAnchorMinor = $hasAnchor
            ? $this->fx->withRates($anchorByCurrency, $baseCurrency, $rates)->minor
            : null;

        return ['map' => $map, 'todayAnchorMinor' => $isComputingAny ? null : $todayAnchorMinor];
    }

    /**
     * @param  list<array<string, int>>  $buckets
     * @return list<string>
     */
    private static function currenciesIn(array $buckets): array
    {
        $seen = [];
        foreach ($buckets as $byCurrency) {
            foreach (array_keys($byCurrency) as $currency) {
                $seen[$currency] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    private function emptyComputingMap(CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $map = [];
        $cursor = $monthStart;
        while ($cursor->lte($monthEnd)) {
            $map[$cursor->toDateString()] = [0, true];
            $cursor = $cursor->addDay();
        }

        return $map;
    }

    /**
     * @param  list<int>  $effectiveBalance
     * @return array{byDateCurrency: array<string, array<string, int>>, isComputingAny: bool, anchorByCurrency: array<string, int>, hasAnchor: bool}
     */
    private function collectForecastBuckets(array $effectiveBalance, User $user): array
    {
        /** @var array<string, array<string, int>> $byDateCurrency */
        $byDateCurrency = [];
        /** @var array<string, int> $anchorByCurrency */
        $anchorByCurrency = [];
        $isComputingAny = false;
        $hasAnchor = false;

        foreach ($effectiveBalance as $accountId) {
            $dto = $this->forecastQuery->forUser($accountId, self::FORECAST_HORIZON_DAYS, null, $user);

            if ($dto->isComputing) {
                $isComputingAny = true;

                continue;
            }

            $hasAnchor = true;
            $anchorByCurrency[$dto->defaultCurrency]
                = ($anchorByCurrency[$dto->defaultCurrency] ?? 0) + $dto->todayBalanceMinor;

            // Buckets keep currencies separate so a USD account's points are
            // never added raw to EUR points.
            foreach ($dto->points as $point) {
                $byDateCurrency[$point->date][$point->currency]
                    = ($byDateCurrency[$point->date][$point->currency] ?? 0) + $point->pointMinor;
            }
        }

        return [
            'byDateCurrency' => $byDateCurrency,
            'isComputingAny' => $isComputingAny,
            'anchorByCurrency' => $anchorByCurrency,
            'hasAnchor' => $hasAnchor,
        ];
    }

    /**
     * @param  array<string, array<string, int>>  $byDateCurrency
     * @param  array<string, string>  $rates
     * @return array<string, array{0: int, 1: bool}>
     */
    private function bucketsToBalanceMap(array $byDateCurrency, string $baseCurrency, array $rates, bool $isComputingAny): array
    {
        $map = [];
        foreach ($byDateCurrency as $dateStr => $byCurrency) {
            $map[$dateStr] = [$this->fx->withRates($byCurrency, $baseCurrency, $rates)->minor, $isComputingAny];
        }

        return $map;
    }

    /**
     * @param  array<string, array{0: int, 1: bool}>  $map
     * @return array<string, array{0: int, 1: bool}>
     */
    private function applyComputingSentinel(array $map, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $cur = $monthStart;
        while ($cur->lte($monthEnd)) {
            $dateStr = $cur->toDateString();
            $map[$dateStr] = [$map[$dateStr][0] ?? 0, true];
            $cur = $cur->addDay();
        }

        return $map;
    }

    /**
     * @param  list<int>  $effectiveBalance
     * @return array{cumByCurrency: array<string, int>, deltaByDateCurrency: array<string, array<string, int>>, gridStart: CarbonImmutable, pastEnd: CarbonImmutable}|null
     */
    private function collectOverlayBuckets(
        array $effectiveBalance,
        User $user,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): ?array {
        $today = $this->clock->now()->startOfDay();
        $gridStart = $monthStart->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $monthEnd->startOfDay()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
        $yesterday = $today->subDay();
        $pastEnd = $gridEnd->lt($yesterday) ? $gridEnd : $yesterday;

        if ($pastEnd->lt($gridStart)) {
            return null;
        }

        return [
            'cumByCurrency' => $this->cumulativeBalanceBefore($effectiveBalance, $user, $gridStart),
            'deltaByDateCurrency' => $this->dailyDeltasBetween($effectiveBalance, $user, $gridStart, $pastEnd),
            'gridStart' => $gridStart,
            'pastEnd' => $pastEnd,
        ];
    }

    /**
     * @param  array<string, array{0: int, 1: bool}>  $map
     * @param  array{cumByCurrency: array<string, int>, deltaByDateCurrency: array<string, array<string, int>>, gridStart: CarbonImmutable, pastEnd: CarbonImmutable}  $overlay
     * @param  array<string, string>  $rates
     * @return array<string, array{0: int, 1: bool}>
     */
    private function overlayActualBalances(array $map, array $overlay, string $baseCurrency, array $rates): array
    {
        // Runs last on purpose: actuals come from transactions, so they must
        // also overwrite the computing sentinel the previous step laid down.
        $cumByCurrency = $overlay['cumByCurrency'];
        $cursor = $overlay['gridStart'];
        while ($cursor->lte($overlay['pastEnd'])) {
            $dateStr = $cursor->toDateString();
            foreach ($overlay['deltaByDateCurrency'][$dateStr] ?? [] as $currency => $deltaMinor) {
                $cumByCurrency[$currency] = ($cumByCurrency[$currency] ?? 0) + $deltaMinor;
            }
            $map[$dateStr] = [$this->fx->withRates($cumByCurrency, $baseCurrency, $rates)->minor, false];
            $cursor = $cursor->addDay();
        }

        return $map;
    }

    /**
     * @param  list<int>  $effectiveBalance
     * @return array<string, int>
     */
    private function cumulativeBalanceBefore(array $effectiveBalance, User $user, CarbonImmutable $gridStart): array
    {
        $rows = $this->db->connection()->table('transactions')
            ->leftJoin('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->where('transactions.user_id', $user->id)
            ->whereIn('transactions.account_id', $effectiveBalance)
            ->where('transactions.posted_at', '<', $gridStart->toDateString())
            ->whereRaw(AccountStartingBalanceQuery::AT_OR_AFTER_BASELINE_SQL)
            ->groupBy('transactions.settled_currency')
            ->selectRaw('transactions.settled_currency as currency, SUM(transactions.settled_amount_minor) as sum_minor')
            ->get();

        // The baseline opens the bucket of the ACCOUNT's default currency and
        // each row the bucket of the currency it settled in, so an account
        // holding two currencies keeps them apart all the way to the rate.
        $cumByCurrency = $this->startingBalances->bucketedByDefaultCurrency($effectiveBalance, $user);
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $currency = self::toString($row->currency);
            $cumByCurrency[$currency] = ($cumByCurrency[$currency] ?? 0) + self::toInt($row->sum_minor);
        }

        return $cumByCurrency;
    }

    /**
     * @param  list<int>  $effectiveBalance
     * @return array<string, array<string, int>>
     */
    private function dailyDeltasBetween(
        array $effectiveBalance,
        User $user,
        CarbonImmutable $gridStart,
        CarbonImmutable $pastEnd,
    ): array {
        $rows = $this->db->connection()->table('transactions')
            ->leftJoin('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->where('transactions.user_id', $user->id)
            ->whereIn('transactions.account_id', $effectiveBalance)
            ->whereBetween('transactions.posted_at', [$gridStart->toDateString(), $pastEnd->toDateString()])
            ->whereRaw(AccountStartingBalanceQuery::AT_OR_AFTER_BASELINE_SQL)
            ->groupBy('transactions.posted_at', 'transactions.settled_currency')
            ->selectRaw('transactions.posted_at as posted_at, transactions.settled_currency as currency, SUM(transactions.settled_amount_minor) as sum_minor')
            ->get();

        $deltaByDateCurrency = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $deltaByDateCurrency[self::toString($row->posted_at)][self::toString($row->currency)]
                = self::toInt($row->sum_minor);
        }

        return $deltaByDateCurrency;
    }
}
