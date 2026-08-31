<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Calendar\Internal\Dto\DayBalanceDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\FX\Public\Dto\ConvertedTotal;
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

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private ForecastQuery $forecastQuery,
        private CrossCurrencyTotal $fx,
        private AccountStartingBalanceQuery $startingBalances,
        private BaseCurrency $baseCurrencies,
    ) {}

    // A currency the rate table cannot reach is left OFF the line rather than
    // counted at par, and each day names its own codes: a caller that renders
    // the line without them shows a partial balance as a whole one.
    /**
     * @param  list<int>  $effectiveBalance
     * @return array{map: array<string, DayBalanceDto>, todayAnchorMinor: int|null, gridStartOpening: DayBalanceDto|null}
     */
    public function buildBalanceMap(
        array $effectiveBalance,
        User $user,
        CarbonImmutable $gridStart,
        CarbonImmutable $gridEnd,
    ): array {
        if ($effectiveBalance === []) {
            return [
                'map' => $this->emptyComputingMap($gridStart, $gridEnd),
                'todayAnchorMinor' => null,
                'gridStartOpening' => null,
            ];
        }

        $baseCurrency = $this->baseCurrencies->forUser($user);
        ['byDateCurrency' => $byDateCurrency, 'isComputingAny' => $isComputingAny, 'anchorByCurrency' => $anchorByCurrency, 'hasAnchor' => $hasAnchor]
            = $this->collectForecastBuckets($effectiveBalance, $user);
        $overlay = $this->collectOverlayBuckets($effectiveBalance, $user, $gridStart, $gridEnd);

        $sourceCurrencies = self::currenciesIn([
            ...array_values($byDateCurrency),
            $anchorByCurrency,
            ...($overlay === null ? [] : [$overlay['cumByCurrency'], ...array_values($overlay['deltaByDateCurrency'])]),
        ]);

        // Every bucket the whole grid will convert, priced in one pass: the
        // rate a day needs is the rate every other day needs, and the service
        // behind it reads the entire exchange_rates table per call.
        $rates = $this->fx->ratesTo($sourceCurrencies, $baseCurrency);

        $map = $this->bucketsToBalanceMap($byDateCurrency, $baseCurrency, $rates, $isComputingAny);

        if ($isComputingAny) {
            $map = $this->applyComputingSentinel($map, $gridStart, $gridEnd);
        }

        if ($overlay !== null) {
            $map = $this->overlayActualBalances($map, $overlay, $baseCurrency, $rates);
        }

        // Today's start-of-day chains off the anchor, so the anchor answers on
        // the same terms every other day does: the priced part where there is
        // one, and no figure at all where there is not.
        $anchor = self::dayBalance($this->fx->withRates($anchorByCurrency, $baseCurrency, $rates), $anchorByCurrency, false);
        $todayAnchorMinor = $hasAnchor && $anchor->isKnown() ? $anchor->minor : null;

        return [
            'map' => $map,
            'todayAnchorMinor' => $isComputingAny ? null : $todayAnchorMinor,
            // What the first grid day opened on. cumulativeBalanceBefore() is
            // already exactly that figure — it seeds the overlay's running
            // total — and the grid's first cell had no predecessor to chain
            // from, so it reported unknown for a balance held right here.
            'gridStartOpening' => $overlay === null ? null : self::dayBalance(
                $this->fx->withRates($overlay['cumByCurrency'], $baseCurrency, $rates),
                $overlay['cumByCurrency'],
                false,
            ),
        ];
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
     * @return array<string, DayBalanceDto>
     */
    private function emptyComputingMap(CarbonImmutable $gridStart, CarbonImmutable $gridEnd): array
    {
        $map = [];
        $cursor = $gridStart;
        while ($cursor->lte($gridEnd)) {
            $map[$cursor->toDateString()] = new DayBalanceDto(minor: 0, isComputing: true, hasFigure: false);
            $cursor = $cursor->addDay();
        }

        return $map;
    }

    // The converted figure plus what conversion left out, so a reader of one
    // day can tell "the line is zero" from "the line is unknown". An unpriced
    // bucket that is itself negative is an overdraft whatever it is worth, and
    // it is invisible in $total->minor, which that bucket never reached.
    /**
     * @param  array<string, int>  $byCurrency
     */
    private static function dayBalance(ConvertedTotal $total, array $byCurrency, bool $isComputing): DayBalanceDto
    {
        $priced = array_diff_key($byCurrency, array_flip($total->unconverted));

        return new DayBalanceDto(
            minor: $total->minor,
            isComputing: $isComputing,
            unconvertedCurrencies: $total->unconverted,
            isNegative: $total->minor < 0 || self::anyUnpricedOverdraft($byCurrency, $total->unconverted),
            hasFigure: $priced !== [],
        );
    }

    /**
     * @param  array<string, int>  $byCurrency
     * @param  list<string>  $unconverted
     */
    private static function anyUnpricedOverdraft(array $byCurrency, array $unconverted): bool
    {
        return array_any($unconverted, fn (string $currency): bool => ($byCurrency[$currency] ?? 0) < 0);
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
            $dto = $this->forecastQuery->forUser($accountId, CalendarMonthWindow::PROJECTION->value, null, $user);

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
     * @return array<string, DayBalanceDto>
     */
    private function bucketsToBalanceMap(array $byDateCurrency, string $baseCurrency, array $rates, bool $isComputingAny): array
    {
        $map = [];
        foreach ($byDateCurrency as $dateStr => $byCurrency) {
            $map[$dateStr] = self::dayBalance(
                $this->fx->withRates($byCurrency, $baseCurrency, $rates),
                $byCurrency,
                $isComputingAny,
            );
        }

        return $map;
    }

    /**
     * @param  array<string, DayBalanceDto>  $map
     * @return array<string, DayBalanceDto>
     */
    private function applyComputingSentinel(array $map, CarbonImmutable $gridStart, CarbonImmutable $gridEnd): array
    {
        $cur = $gridStart;
        while ($cur->lte($gridEnd)) {
            $dateStr = $cur->toDateString();
            $known = $map[$dateStr] ?? null;
            $map[$dateStr] = new DayBalanceDto(
                minor: $known->minor ?? 0,
                isComputing: true,
                unconvertedCurrencies: $known->unconvertedCurrencies ?? [],
                isNegative: $known->isNegative ?? false,
                hasFigure: $known->hasFigure ?? false,
            );
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
        CarbonImmutable $gridStart,
        CarbonImmutable $gridEnd,
    ): ?array {
        // The caller owns the range. Widening it to a Mon–Sun week here was a
        // second answer to where the grid starts, and two answers is how the
        // edge cells came to disagree with the entries drawn on them.
        $yesterday = $this->clock->now()->startOfDay()->subDay();
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
     * @param  array<string, DayBalanceDto>  $map
     * @param  array{cumByCurrency: array<string, int>, deltaByDateCurrency: array<string, array<string, int>>, gridStart: CarbonImmutable, pastEnd: CarbonImmutable}  $overlay
     * @param  array<string, string>  $rates
     * @return array<string, DayBalanceDto>
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
            $map[$dateStr] = self::dayBalance(
                $this->fx->withRates($cumByCurrency, $baseCurrency, $rates),
                $cumByCurrency,
                false,
            );
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
