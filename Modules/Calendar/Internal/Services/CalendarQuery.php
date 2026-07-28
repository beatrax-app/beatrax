<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Calendar\Public\Dto\CalendarDayDto;
use Modules\Calendar\Public\Dto\CalendarEntryDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use stdClass;

/**
 * @link ../../../../.docs/features/calendar/architecture.md
 */
final readonly class CalendarQuery
{
    use CoercesScalars;

    // ON kinds (checking/savings/cash/PayPal) are included in the spendable
    // balance default; OFF kinds (ICS credit-card family) are excluded since
    // their liability already shows up via the bulk-iDEAL settlement leg.
    private const array SPENDABLE_KINDS = ['asn', 'bank', 'cash', 'paypal', 'paypal_funding'];

    // Tolerance window cap for past-day paid/missed matching; the effective
    // window is clamped per cadence in matchWindowDays() so one observed
    // payment can never mark multiple adjacent expected entries paid.
    private const int MATCH_WINDOW_DAYS = 7;

    // 365 days covers a full calendar year so any month the user navigates
    // to has balance projection data available.
    private const int FORECAST_HORIZON_DAYS = 365;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private RecurringSeriesQuery $seriesQuery,
        private ForecastQuery $forecastQuery,
        private ExchangeRateService $fxService,
        private CounterpartyProfileQuery $counterpartyQuery,
    ) {}

    /**
     * @param  list<int>|null  $visibleAccountIds  IDs of accounts whose series entries appear (null = all)
     * @param  list<int>|null  $balanceAccountIds  IDs of accounts summed for the balance line (null = spendable default)
     * @return list<CalendarDayDto>
     */
    public function forMonth(
        User $user,
        int $year,
        int $month,
        ?array $visibleAccountIds = null,
        ?array $balanceAccountIds = null,
    ): array {
        // $effectiveVisible === null means "all accounts visible" (no filter
        // specified); [] means "no visible accounts" (filter specified but
        // everything dropped).
        $ownedIds = $this->ownedAccountIds($user);
        $effectiveVisible = $this->resolveVisibleAccountIds($visibleAccountIds, $ownedIds);
        $effectiveBalance = $this->resolveBalanceAccountIds($balanceAccountIds, $ownedIds, $user);

        $gridDays = $this->buildGridDays($year, $month);

        $allSeries = $this->seriesQuery->allApprovedForUser($user);
        $seriesIds = array_map(static fn ($s): int => $s->seriesId, $allSeries);
        $accountIdForSeries = $seriesIds !== []
            ? $this->seriesQuery->accountIdsForSeriesIds($seriesIds, $user)
            : [];

        $now = $this->clock->now();
        // CarbonImmutable::create() returns CarbonImmutable|null — parse() on
        // a formatted date string guarantees a non-null CarbonImmutable.
        $monthStart = CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month));
        $monthEnd = $monthStart->endOfMonth();

        $entryMap = $this->buildEntryMap(
            $allSeries,
            $accountIdForSeries,
            $effectiveVisible,
            $monthStart,
            $monthEnd,
            $user,
        );

        ['map' => $balanceMap, 'todayAnchorMinor' => $todayAnchorMinor]
            = $this->buildBalanceMap($effectiveBalance, $user, $year, $month, $monthStart, $monthEnd);

        $occurrenceMap = $this->buildOccurrenceMap($user, $monthStart, $monthEnd);

        $cadenceBySeries = [];
        foreach ($allSeries as $series) {
            $cadenceBySeries[$series->seriesId] = $series->cadence;
        }

        $days = [];
        // SoD chain: null until a known (non-computing) EoD is seen — a day
        // after a data-less day must report "SoD unknown", not a fake 0.
        $prevEod = null;

        // First pass: compute the eod map for the entire grid, needed below
        // for the sod chain.
        $eodMinorMap = [];
        foreach ($gridDays as $date) {
            $dateStr = $date->toDateString();
            [$sumMinor, $isComputing] = $balanceMap[$dateStr] ?? [0, true];
            $eodMinorMap[$dateStr] = [$sumMinor, $isComputing];
        }

        $baseCurrency = $user->base_currency;
        foreach ($gridDays as $date) {
            $dateStr = $date->toDateString();
            $isToday = $date->isSameDay($now);
            $isPast = $date->lt($now->startOfDay());

            [$eodMinor, $isComputing] = $eodMinorMap[$dateStr] ?? [0, true];

            // SoD = prior day's EoD when known; today falls back to the
            // forecast's as-of anchor sum (yesterday is a past day with no
            // forecast point, so the chain alone would leave today unknown).
            $sodMinor = $prevEod;
            if ($sodMinor === null && $isToday) {
                $sodMinor = $todayAnchorMinor;
            }

            $isRisk = ! $isComputing && $eodMinor < 0;

            $rawEntries = $entryMap[$dateStr] ?? [];
            $entries = [];
            foreach ($rawEntries as $entry) {
                if ($isPast) {
                    $observedDates = $occurrenceMap[$entry->seriesId] ?? [];
                    $windowDays = $this->matchWindowDays($cadenceBySeries[$entry->seriesId] ?? null);
                    $isPaid = $this->hasMatchingOccurrence($date, $observedDates, $windowDays);
                    $isMissed = ! $isPaid;
                    $entries[] = new CalendarEntryDto(
                        seriesId: $entry->seriesId,
                        name: $entry->name,
                        amountMinor: $entry->amountMinor,
                        currency: $entry->currency,
                        direction: $entry->direction,
                        accountId: $entry->accountId,
                        accountName: $entry->accountName,
                        counterpartyId: $entry->counterpartyId,
                        counterpartySlug: $entry->counterpartySlug,
                        isPaid: $isPaid,
                        isMissed: $isMissed,
                        isApproximate: $entry->isApproximate,
                    );
                } else {
                    $entries[] = $entry;
                }
            }

            $days[] = new CalendarDayDto(
                date: $date,
                isToday: $isToday,
                isPast: $isPast,
                isRisk: $isRisk,
                sodBalanceMinor: $sodMinor,
                eodBalanceMinor: $eodMinor,
                currency: $baseCurrency,
                isComputing: $isComputing,
                entries: $entries,
            );

            $prevEod = $isComputing ? null : $eodMinor;
        }

        return $days;
    }

    // -------------------------------------------------------------------------
    // Entry placement
    // -------------------------------------------------------------------------

    /**
     * @param  list<RecurringSeriesDto>  $allSeries
     * @param  array<int, int>  $accountIdForSeries
     * @param  list<int>|null  $effectiveVisible  null = all visible; [] = none visible
     * @return array<string, list<CalendarEntryDto>>
     */
    private function buildEntryMap(
        array $allSeries,
        array $accountIdForSeries,
        ?array $effectiveVisible,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        User $user,
    ): array {
        /** @var list<array{series: RecurringSeriesDto, accountId: int|null}> $candidates */
        $candidates = [];
        foreach ($allSeries as $series) {
            if ($series->cadence === 'irregular' && $series->nextExpectedAt === null) {
                continue;
            }

            $accountId = $accountIdForSeries[$series->seriesId] ?? null;

            // effectiveVisible === null means "all visible" (no filter
            // specified, include even unlinked series); [] means "nothing
            // passed the filter" (explicit filter, all dropped).
            if ($effectiveVisible !== null) {
                if ($accountId === null || ! in_array($accountId, $effectiveVisible, true)) {
                    continue;
                }
            }

            $candidates[] = ['series' => $series, 'accountId' => $accountId];
        }

        if ($candidates === []) {
            return [];
        }

        // The backward projection must not fabricate expected occurrences
        // from before the series existed.
        $candidateIds = array_map(static fn (array $c): int => $c['series']->seriesId, $candidates);
        $startFloors = $this->seriesStartFloors($candidateIds, $user);

        /** @var list<array{series: RecurringSeriesDto, accountId: int|null, dates: list<CarbonImmutable>}> $placed */
        $placed = [];
        foreach ($candidates as $candidate) {
            $series = $candidate['series'];
            $occurrenceDates = $this->placeSeriesInMonth(
                $series,
                $monthStart,
                $monthEnd,
                $startFloors[$series->seriesId] ?? null,
            );
            if ($occurrenceDates === []) {
                continue;
            }

            $placed[] = ['series' => $series, 'accountId' => $candidate['accountId'], 'dates' => $occurrenceDates];
        }

        if ($placed === []) {
            return [];
        }

        $placedSeriesIds = array_map(static fn (array $p): int => $p['series']->seriesId, $placed);

        // Primary path: occurrences -> transactions -> counterparty_id.
        // Series unresolved here fall back to the cluster-key path below.
        $counterpartyIdBySeries = $this->seriesQuery->counterpartyIdsForSeriesIds($placedSeriesIds, $user);
        $identityByCounterpartyId = $this->counterpartyQuery->identitiesForIds(
            $user,
            array_values(array_unique(array_values($counterpartyIdBySeries))),
        );

        // Fallback path: series with no occurrence-linked counterparty may
        // still resolve via cluster_counterparty_key == counterparties.slug.
        $unresolvedSeriesIds = array_values(array_filter(
            $placedSeriesIds,
            static fn (int $id): bool => ! isset($counterpartyIdBySeries[$id]),
        ));
        $clusterKeyBySeries = $unresolvedSeriesIds !== []
            ? $this->clusterKeysForSeriesIds($unresolvedSeriesIds, $user)
            : [];
        $counterpartyIdBySlug = $clusterKeyBySeries !== []
            ? $this->counterpartyQuery->idsBySlugs($user, array_values(array_unique(array_values($clusterKeyBySeries))))
            : [];

        $accountNames = $this->accountNamesForUser($user);

        $entryMap = [];
        foreach ($placed as $placement) {
            $series = $placement['series'];
            $accountId = $placement['accountId'];

            $counterpartyId = $counterpartyIdBySeries[$series->seriesId] ?? null;
            $counterpartySlug = null;
            if ($counterpartyId !== null) {
                $counterpartySlug = $identityByCounterpartyId[$counterpartyId]['slug'] ?? null;
            } else {
                $clusterKey = $clusterKeyBySeries[$series->seriesId] ?? null;
                if ($clusterKey !== null && isset($counterpartyIdBySlug[$clusterKey])) {
                    $counterpartyId = $counterpartyIdBySlug[$clusterKey];
                    $counterpartySlug = $clusterKey;
                }
            }

            $accountName = $accountId !== null ? ($accountNames[$accountId] ?? '') : '';

            foreach ($placement['dates'] as $date) {
                $dateStr = $date->toDateString();
                $entry = new CalendarEntryDto(
                    seriesId: $series->seriesId,
                    name: $series->displayName(),
                    amountMinor: $series->latestAmount->toMinor(),
                    currency: $series->latestAmount->currency(),
                    direction: $series->direction,
                    accountId: $accountId,
                    accountName: $accountName,
                    counterpartyId: $counterpartyId,
                    counterpartySlug: $counterpartySlug,
                    isPaid: false,
                    isMissed: false,
                    isApproximate: $series->nextExpectedConfidenceLow,
                );
                $entryMap[$dateStr][] = $entry;
            }
        }

        return $entryMap;
    }

    /**
     * @link ../../../../.docs/features/calendar/architecture.md
     *
     * @return list<CarbonImmutable>
     */
    private function placeSeriesInMonth(
        RecurringSeriesDto $series,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        ?CarbonImmutable $seriesStart = null,
    ): array {
        $next = $series->nextExpectedAt;
        if ($next === null) {
            return [];
        }

        if ($seriesStart !== null && $monthEnd->lt($seriesStart)) {
            return [];
        }

        if ($series->cadence === 'irregular') {
            if ($next->gte($monthStart) && $next->lte($monthEnd)) {
                return [$next->startOfDay()];
            }

            return [];
        }

        // Occurrences are found by stepping by index from the anchor
        // (anchor->addMonthsNoOverflow(k) for k = …,-1,0,1,…) rather than
        // chaining no-overflow steps, which permanently loses an end-of-month
        // anchor after the first short month and is non-invertible.
        $cadence = $series->cadence;
        if (! in_array($cadence, ['weekly', 'monthly', 'quarterly', 'yearly'], true)) {
            return [];
        }

        $anchor = $next->startOfDay();

        // Estimate the first occurrence index that could land in the month,
        // then start one step earlier as a safety margin.
        $monthsDelta = ($monthStart->year - $anchor->year) * 12 + ($monthStart->month - $anchor->month);
        $deltaDays = (int) floor(($monthStart->getTimestamp() - $anchor->getTimestamp()) / 86400);
        $k = match ($cadence) {
            'weekly' => (int) floor($deltaDays / 7) - 1,
            'monthly' => $monthsDelta - 1,
            'quarterly' => (int) floor($monthsDelta / 3) - 1,
            'yearly' => ($monthStart->year - $anchor->year) - 1,
        };

        // Occurrence dates are strictly increasing in k, so collect from the
        // estimated start until the first date past monthEnd.
        $results = [];
        $iterations = 0;
        $maxIterations = 60;

        while ($iterations < $maxIterations) {
            $iterations++;
            $occurrence = $this->occurrenceAt($anchor, $cadence, $k);
            $k++;
            if ($occurrence === null || $occurrence->gt($monthEnd)) {
                break;
            }
            if ($occurrence->lt($monthStart)) {
                continue;
            }
            if ($seriesStart !== null && $occurrence->lt($seriesStart)) {
                continue;
            }
            $results[] = $occurrence;
        }

        return $results;
    }

    /**
     * @param  list<int>  $seriesIds
     * @return array<int, CarbonImmutable>
     */
    private function seriesStartFloors(array $seriesIds, User $user): array
    {
        $rows = $this->db->connection()->table('recurring_series as s')
            ->leftJoin('recurring_series_occurrences as o', function (JoinClause $join) use ($user): void {
                $join->on('o.recurring_series_id', '=', 's.id')
                    ->where('o.user_id', '=', $user->id);
            })
            ->whereIn('s.id', $seriesIds)
            ->where('s.user_id', $user->id)
            ->groupBy('s.id', 's.created_at')
            ->selectRaw('s.id as id, s.created_at as created_at, MIN(o.observed_at) as first_observed')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $seriesId = self::toInt($row->id);
            if ($seriesId === 0) {
                continue;
            }
            $raw = self::toString($row->first_observed ?? null);
            if ($raw === '') {
                $raw = self::toString($row->created_at ?? null);
            }
            if ($raw === '') {
                continue;
            }
            $map[$seriesId] = CarbonImmutable::parse($raw)
                ->startOfDay()
                ->subDays(self::MATCH_WINDOW_DAYS);
        }

        return $map;
    }

    // Negative k steps backward; every index is computed from the anchor so
    // short months never permanently shift an end-of-month billing day.
    private function occurrenceAt(CarbonImmutable $anchor, string $cadence, int $k): ?CarbonImmutable
    {
        return match ($cadence) {
            'weekly' => $anchor->addDays(7 * $k),
            'monthly' => $anchor->addMonthsNoOverflow($k),
            'quarterly' => $anchor->addMonthsNoOverflow(3 * $k),
            'yearly' => $anchor->addYearsNoOverflow($k),
            default => null,
        };
    }

    // -------------------------------------------------------------------------
    // Balance aggregation
    // -------------------------------------------------------------------------

    /**
     * @link ../../../../.docs/features/calendar/architecture.md
     *
     * @param  list<int>  $effectiveBalance
     * @return array{map: array<string, array{0: int, 1: bool}>, todayAnchorMinor: int|null}
     */
    private function buildBalanceMap(
        array $effectiveBalance,
        User $user,
        int $year,
        int $month,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): array {
        if ($effectiveBalance === []) {
            $map = [];
            $cursor = $monthStart;
            while ($cursor->lte($monthEnd)) {
                $map[$cursor->toDateString()] = [0, true];
                $cursor = $cursor->addDay();
            }

            return ['map' => $map, 'todayAnchorMinor' => null];
        }

        /** @var array<string, array<string, int>> $byDateCurrency */
        $byDateCurrency = [];
        $isComputingAny = false;
        $baseCurrency = $user->base_currency;
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

        // Build the map for the dates we have data for, FX-converting each
        // currency bucket to the user's base reporting currency before summing.
        $map = [];

        foreach ($byDateCurrency as $dateStr => $byCurrency) {
            $totalMinor = 0;
            foreach ($byCurrency as $currency => $sumMinor) {
                $converted = $this->fxService->convertToBase(Money::ofMinor($sumMinor, $currency), $baseCurrency);
                $totalMinor += $converted->converted->toMinor();
            }
            $map[$dateStr] = [$totalMinor, $isComputingAny];
        }

        // If any account is computing, propagate the sentinel to every day,
        // including dates with no forecast data yet.
        if ($isComputingAny) {
            $cur = $monthStart;
            while ($cur->lte($monthEnd)) {
                $dateStr = $cur->toDateString();
                if (! isset($map[$dateStr])) {
                    $map[$dateStr] = [0, true];
                } else {
                    $map[$dateStr] = [$map[$dateStr][0], true];
                }
                $cur = $cur->addDay();
            }
        }

        // Overwrite every grid day before today with the real cumulative
        // balance — actuals are derived from transactions, not forecast
        // runs, so they also override the computing fill above.
        $today = $this->clock->now()->startOfDay();
        $gridStart = $monthStart->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $monthEnd->startOfDay()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
        $yesterday = $today->subDay();
        $pastEnd = $gridEnd->lt($yesterday) ? $gridEnd : $yesterday;

        if ($pastEnd->gte($gridStart)) {
            /** @var array<string, int> $cumByCurrency */
            $cumByCurrency = [];
            $baseRows = $this->db->connection()->table('transactions')
                ->where('user_id', $user->id)
                ->whereIn('account_id', $effectiveBalance)
                ->where('posted_at', '<', $gridStart->toDateString())
                ->groupBy('currency')
                ->selectRaw('currency, SUM(amount_minor) as sum_minor')
                ->get();
            foreach ($baseRows as $row) {
                /** @var stdClass $row */
                $cumByCurrency[self::toString($row->currency)] = self::toInt($row->sum_minor);
            }

            /** @var array<string, array<string, int>> $deltaByDateCurrency */
            $deltaByDateCurrency = [];
            $deltaRows = $this->db->connection()->table('transactions')
                ->where('user_id', $user->id)
                ->whereIn('account_id', $effectiveBalance)
                ->whereBetween('posted_at', [$gridStart->toDateString(), $pastEnd->toDateString()])
                ->groupBy('posted_at', 'currency')
                ->selectRaw('posted_at, currency, SUM(amount_minor) as sum_minor')
                ->get();
            foreach ($deltaRows as $row) {
                /** @var stdClass $row */
                $deltaByDateCurrency[self::toString($row->posted_at)][self::toString($row->currency)]
                    = self::toInt($row->sum_minor);
            }

            // Walk the past grid days, carrying the cumulative balance
            // forward. convertToBase is a zero-query passthrough for
            // base-currency buckets; non-base buckets hit the cached
            // exchange_rates lookup per (day, currency).
            $cursor = $gridStart;
            while ($cursor->lte($pastEnd)) {
                $dateStr = $cursor->toDateString();
                foreach ($deltaByDateCurrency[$dateStr] ?? [] as $currency => $deltaMinor) {
                    $cumByCurrency[$currency] = ($cumByCurrency[$currency] ?? 0) + $deltaMinor;
                }
                $totalMinor = 0;
                foreach ($cumByCurrency as $currency => $sumMinor) {
                    $converted = $this->fxService->convertToBase(Money::ofMinor($sumMinor, $currency), $baseCurrency);
                    $totalMinor += $converted->converted->toMinor();
                }
                $map[$dateStr] = [$totalMinor, false];
                $cursor = $cursor->addDay();
            }
        }

        // A partially-computing aggregate has no honest SoD anchor to
        // report, so todayAnchorMinor is forced null in that case.
        return ['map' => $map, 'todayAnchorMinor' => $isComputingAny ? null : $todayAnchorMinor];
    }

    // -------------------------------------------------------------------------
    // Past-day occurrence matching
    // -------------------------------------------------------------------------

    /**
     * @return array<int, list<string>>
     */
    private function buildOccurrenceMap(
        User $user,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): array {
        // Extend the window to catch occurrences just outside the month that
        // might still match entries near the month boundaries.
        $windowStart = $monthStart->subDays(self::MATCH_WINDOW_DAYS)->toDateString();
        $windowEnd = $monthEnd->addDays(self::MATCH_WINDOW_DAYS)->toDateString();

        $rows = $this->db->connection()->table('recurring_series_occurrences')
            ->where('user_id', $user->id)
            ->whereBetween('observed_at', [$windowStart, $windowEnd])
            ->get(['recurring_series_id', 'observed_at']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $seriesId = self::toInt($row->recurring_series_id);
            if ($seriesId === 0) {
                continue;
            }
            $observedDate = CarbonImmutable::parse(self::toString($row->observed_at))->toDateString();
            $map[$seriesId][] = $observedDate;
        }

        return $map;
    }

    // The window is clamped to half the cadence interval so adjacent
    // expected occurrences can never both match the same observed payment.
    private function matchWindowDays(?string $cadence): int
    {
        $cadenceDays = match ($cadence) {
            'daily' => 1,
            'weekly' => 7,
            default => null,
        };

        if ($cadenceDays === null) {
            return self::MATCH_WINDOW_DAYS;
        }

        return min(self::MATCH_WINDOW_DAYS, intdiv($cadenceDays, 2));
    }

    /**
     * @param  list<string>  $observedDates
     */
    private function hasMatchingOccurrence(CarbonImmutable $date, array $observedDates, int $windowDays): bool
    {
        $expected = $date->startOfDay();
        $windowStart = $expected->subDays($windowDays);
        $windowEnd = $expected->addDays($windowDays);

        foreach ($observedDates as $observedDateStr) {
            $observed = CarbonImmutable::parse($observedDateStr)->startOfDay();
            if ($observed->gte($windowStart) && $observed->lte($windowEnd)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Account-preference resolution
    // -------------------------------------------------------------------------

    /**
     * @return list<int>
     */
    public function ownedAccountIds(User $user): array
    {
        $rows = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->pluck('id');

        $ids = [];
        foreach ($rows as $id) {
            $ids[] = self::toInt($id);
        }

        return $ids;
    }

    /**
     * @link ../../../../.docs/features/calendar/architecture.md
     *
     * @param  list<int>|null  $callerIds
     * @param  list<int>  $ownedIds
     * @return list<int>|null null = all visible
     */
    private function resolveVisibleAccountIds(?array $callerIds, array $ownedIds): ?array
    {
        if ($callerIds === null) {
            return null;
        }

        return array_values(array_intersect($callerIds, $ownedIds));
    }

    /**
     * @link ../../../../.docs/features/calendar/architecture.md
     *
     * @param  list<int>|null  $callerIds
     * @param  list<int>  $ownedIds
     * @return list<int>
     */
    private function resolveBalanceAccountIds(?array $callerIds, array $ownedIds, User $user): array
    {
        if ($callerIds === null) {
            return $this->spendableAccountIds($user);
        }

        return array_values(array_intersect($callerIds, $ownedIds));
    }

    /**
     * @link ../../../../.docs/features/calendar/architecture.md
     *
     * @return list<int>
     */
    public function spendableAccountIds(User $user): array
    {
        $rows = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->whereIn('kind', self::SPENDABLE_KINDS)
            ->pluck('id');

        $ids = [];
        foreach ($rows as $id) {
            $ids[] = self::toInt($id);
        }

        return $ids;
    }

    // -------------------------------------------------------------------------
    // Grid construction
    // -------------------------------------------------------------------------

    /**
     * @return list<CarbonImmutable>
     */
    private function buildGridDays(int $year, int $month): array
    {
        // CarbonImmutable::create() returns CarbonImmutable|null — parse a
        // formatted string to guarantee a non-null CarbonImmutable.
        $firstOfMonth = CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month))->startOfDay();
        $lastOfMonth = $firstOfMonth->endOfMonth()->startOfDay();

        $gridStart = $firstOfMonth->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $lastOfMonth->endOfWeek(CarbonImmutable::SUNDAY);

        $days = [];
        $cursor = $gridStart;
        while ($cursor->lte($gridEnd)) {
            $days[] = $cursor;
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function accountNamesForUser(User $user): array
    {
        $rows = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->get(['id', 'name']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $map[self::toInt($row->id)] = self::toString($row->name ?? null);
        }

        return $map;
    }

    /**
     * @param  list<int>  $seriesIds
     * @return array<int, string>
     */
    private function clusterKeysForSeriesIds(array $seriesIds, User $user): array
    {
        $rows = $this->db->connection()->table('recurring_series')
            ->whereIn('id', $seriesIds)
            ->where('user_id', $user->id)
            ->get(['id', 'cluster_counterparty_key']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $key = self::toString($row->cluster_counterparty_key ?? null);
            if ($key !== '') {
                $map[self::toInt($row->id)] = $key;
            }
        }

        return $map;
    }
}
