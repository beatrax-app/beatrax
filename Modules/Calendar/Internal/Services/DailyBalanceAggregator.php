<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

// Builds the per-day end-of-day balance map for the month grid: forecast
// points for future days, real transaction actuals overlaid on past days,
// and a computing sentinel while any account's forecast is still warming up.
final readonly class DailyBalanceAggregator
{
    use CoercesScalars;

    // 365 days covers a full calendar year so any month the user navigates
    // to has balance projection data available.
    private const int FORECAST_HORIZON_DAYS = 365;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private ForecastQuery $forecastQuery,
        private ExchangeRateService $fxService,
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

        $baseCurrency = $user->base_currency;
        ['byDateCurrency' => $byDateCurrency, 'isComputingAny' => $isComputingAny, 'todayAnchorMinor' => $todayAnchorMinor]
            = $this->collectForecastBuckets($effectiveBalance, $user, $baseCurrency);

        $map = $this->bucketsToBalanceMap($byDateCurrency, $baseCurrency, $isComputingAny);

        if ($isComputingAny) {
            $map = $this->applyComputingSentinel($map, $monthStart, $monthEnd);
        }

        $map = $this->overlayActualBalances($map, $effectiveBalance, $user, $monthStart, $monthEnd, $baseCurrency);

        // A partially-computing aggregate has no honest SoD anchor to
        // report, so todayAnchorMinor is forced null in that case.
        return ['map' => $map, 'todayAnchorMinor' => $isComputingAny ? null : $todayAnchorMinor];
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
     * @return array{byDateCurrency: array<string, array<string, int>>, isComputingAny: bool, todayAnchorMinor: int|null}
     */
    private function collectForecastBuckets(array $effectiveBalance, User $user, string $baseCurrency): array
    {
        /** @var array<string, array<string, int>> $byDateCurrency */
        $byDateCurrency = [];
        $isComputingAny = false;
        $todayAnchorMinor = null;

        // Each account's forecast is fetched once here, not re-fetched per
        // grid day below.
        foreach ($effectiveBalance as $accountId) {
            $dto = $this->forecastQuery->forUser($accountId, self::FORECAST_HORIZON_DAYS, null, $user);

            if ($dto->isComputing) {
                $isComputingAny = true;

                continue;
            }

            $anchorConverted = $this->fxService->convertToBase(
                Money::ofMinor($dto->todayBalanceMinor, $dto->defaultCurrency),
                $baseCurrency,
            );
            $todayAnchorMinor = ($todayAnchorMinor ?? 0) + $anchorConverted->converted->toMinor();

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
            'todayAnchorMinor' => $todayAnchorMinor,
        ];
    }

    /**
     * @param  array<string, array<string, int>>  $byDateCurrency
     * @return array<string, array{0: int, 1: bool}>
     */
    private function bucketsToBalanceMap(array $byDateCurrency, string $baseCurrency, bool $isComputingAny): array
    {
        // Build the map for the dates we have data for, FX-converting each
        // currency bucket to the user's base reporting currency before summing.
        $map = [];
        foreach ($byDateCurrency as $dateStr => $byCurrency) {
            $map[$dateStr] = [$this->sumBucketToBase($byCurrency, $baseCurrency), $isComputingAny];
        }

        return $map;
    }

    /**
     * @param  array<string, array{0: int, 1: bool}>  $map
     * @return array<string, array{0: int, 1: bool}>
     */
    private function applyComputingSentinel(array $map, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        // If any account is computing, propagate the sentinel to every day,
        // including dates with no forecast data yet.
        $cur = $monthStart;
        while ($cur->lte($monthEnd)) {
            $dateStr = $cur->toDateString();
            $map[$dateStr] = [$map[$dateStr][0] ?? 0, true];
            $cur = $cur->addDay();
        }

        return $map;
    }

    /**
     * @param  array<string, array{0: int, 1: bool}>  $map
     * @param  list<int>  $effectiveBalance
     * @return array<string, array{0: int, 1: bool}>
     */
    private function overlayActualBalances(
        array $map,
        array $effectiveBalance,
        User $user,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        string $baseCurrency,
    ): array {
        // Overwrite every grid day before today with the real cumulative
        // balance — actuals are derived from transactions, not forecast
        // runs, so they also override the computing fill above.
        $today = $this->clock->now()->startOfDay();
        $gridStart = $monthStart->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $monthEnd->startOfDay()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
        $yesterday = $today->subDay();
        $pastEnd = $gridEnd->lt($yesterday) ? $gridEnd : $yesterday;

        if ($pastEnd->lt($gridStart)) {
            return $map;
        }

        $cumByCurrency = $this->cumulativeBalanceBefore($effectiveBalance, $user, $gridStart);
        $deltaByDateCurrency = $this->dailyDeltasBetween($effectiveBalance, $user, $gridStart, $pastEnd);

        // Walk the past grid days, carrying the cumulative balance forward.
        // convertToBase is a zero-query passthrough for base-currency buckets;
        // non-base buckets hit the cached exchange_rates lookup per (day,
        // currency).
        $cursor = $gridStart;
        while ($cursor->lte($pastEnd)) {
            $dateStr = $cursor->toDateString();
            foreach ($deltaByDateCurrency[$dateStr] ?? [] as $currency => $deltaMinor) {
                $cumByCurrency[$currency] = ($cumByCurrency[$currency] ?? 0) + $deltaMinor;
            }
            $map[$dateStr] = [$this->sumBucketToBase($cumByCurrency, $baseCurrency), false];
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
            ->where('user_id', $user->id)
            ->whereIn('account_id', $effectiveBalance)
            ->where('posted_at', '<', $gridStart->toDateString())
            ->groupBy('currency')
            ->selectRaw('currency, SUM(amount_minor) as sum_minor')
            ->get();

        $cumByCurrency = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $cumByCurrency[self::toString($row->currency)] = self::toInt($row->sum_minor);
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
            ->where('user_id', $user->id)
            ->whereIn('account_id', $effectiveBalance)
            ->whereBetween('posted_at', [$gridStart->toDateString(), $pastEnd->toDateString()])
            ->groupBy('posted_at', 'currency')
            ->selectRaw('posted_at, currency, SUM(amount_minor) as sum_minor')
            ->get();

        $deltaByDateCurrency = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $deltaByDateCurrency[self::toString($row->posted_at)][self::toString($row->currency)]
                = self::toInt($row->sum_minor);
        }

        return $deltaByDateCurrency;
    }

    /**
     * @param  array<string, int>  $byCurrency
     */
    private function sumBucketToBase(array $byCurrency, string $baseCurrency): int
    {
        $totalMinor = 0;
        foreach ($byCurrency as $currency => $sumMinor) {
            $converted = $this->fxService->convertToBase(Money::ofMinor($sumMinor, $currency), $baseCurrency);
            $totalMinor += $converted->converted->toMinor();
        }

        return $totalMinor;
    }
}
