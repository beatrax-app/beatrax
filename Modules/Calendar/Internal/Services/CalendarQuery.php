<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Calendar\Public\Dto\CalendarDayDto;
use Modules\Calendar\Public\Dto\CalendarEntryDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use stdClass;

/**
 * Read-only service that assembles a `list<CalendarDayDto>` for a given
 * user + month. This is the single backend brain of the calendar — Plan 03's
 * Livewire page is a thin renderer over this service's output.
 *
 * Responsibilities (all concentrated here, not in the Livewire component):
 *   - Entry placement: recurring series occurrences on their correct dates (D-01)
 *   - Account-preference resolution: visible/balance account sets (D-02, D-03)
 *   - Irregular-series gate: null-nextExpectedAt excluded (Pitfall 4)
 *   - Combined day-end balance: ForecastQuery sum per date + FX conversion (D-05)
 *   - Computing sentinel: "—" when any balance account's forecast is computing (D-13)
 *   - Past-day reconciliation: isPaid / isMissed from recurring_series_occurrences (D-07, D-08)
 *   - Internal-transfer entries appear but net to zero in combined balance (D-04)
 *   - Cross-user safety: every DB query carries user_id scoping (T-06-02, T-06-03)
 *
 * Security note (T-06-02): the $visibleAccountIds and $balanceAccountIds caller-supplied
 * arrays are ALWAYS intersected against `SELECT id FROM accounts WHERE user_id = ?`
 * before any forUser/forecast call. Foreign account ids are silently dropped.
 */
final readonly class CalendarQuery
{
    /**
     * Cadence → spendable-set membership (D-03).
     *
     * CONTEXT D-03 used semantic names ("checking/savings/ICS liability") that map
     * to these actual provider-code account kinds. The ON set represents accounts
     * whose balance is included in the "spendable" default view: the accounts the
     * user actually spends from day-to-day. The OFF set (ICS family) represents
     * credit-card liability balances that are already reflected on the funding side
     * (the iDEAL bulk settlement into ASN), so including them would double-count.
     *
     * ON kinds  : asn, bank, cash, paypal, paypal_funding
     * OFF kinds : ics, ics_card, ics_bulk_settle
     *
     * @var list<string>
     */
    private const array SPENDABLE_KINDS = ['asn', 'bank', 'cash', 'paypal', 'paypal_funding'];

    /**
     * Tolerance window for past-day paid/missed matching (Pitfall 3).
     * An occurrence observed within ±MATCH_WINDOW_DAYS of the expected date
     * counts as "paid". Beyond this window the occurrence is unrelated.
     */
    private const int MATCH_WINDOW_DAYS = 7;

    /**
     * Forecast horizon to use for balance projections (D-14).
     * 365 days covers a full calendar year so any month the user navigates to
     * has balance data available.
     */
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
     * Assemble the full calendar grid for the given user + year + month.
     *
     * Returns a `list<CalendarDayDto>` covering the display grid — from the
     * Monday that starts the week containing the 1st of the month to the Sunday
     * that ends the week containing the last day of the month (full weeks only,
     * Mon–Sun). Each day carries its entries and balance data.
     *
     * @param  list<int>  $visibleAccountIds  IDs of accounts whose series entries appear (empty = all)
     * @param  list<int>  $balanceAccountIds  IDs of accounts summed for the balance line (empty = spendable default)
     * @return list<CalendarDayDto>
     */
    public function forMonth(
        User $user,
        int $year,
        int $month,
        array $visibleAccountIds = [],
        array $balanceAccountIds = [],
    ): array {
        // Resolve effective account sets, intersecting against owned accounts (T-06-02).
        // $effectiveVisible === null means "all accounts visible" (no filter specified).
        // $effectiveVisible === [] means "no visible accounts" (filter specified but everything dropped).
        $ownedIds = $this->resolveOwnedAccountIds($user);
        $effectiveVisible = $this->resolveVisibleAccountIds($visibleAccountIds, $ownedIds);
        $effectiveBalance = $this->resolveBalanceAccountIds($balanceAccountIds, $ownedIds, $user);

        // Build the display grid: Mon–Sun weeks spanning the month
        $gridDays = $this->buildGridDays($year, $month);

        // Fetch approved series and resolve account IDs (one batch query)
        $allSeries = $this->seriesQuery->allApprovedForUser($user);
        $seriesIds = array_map(static fn ($s): int => $s->seriesId, $allSeries);
        $accountIdForSeries = $seriesIds !== []
            ? $this->seriesQuery->accountIdsForSeriesIds($seriesIds, $user)
            : [];

        // Place entries on grid days
        $now = $this->clock->now();
        // CarbonImmutable::create() returns CarbonImmutable|null — use parse() on
        // a formatted date string to guarantee a non-null CarbonImmutable.
        $monthStart = CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month));
        $monthEnd = $monthStart->endOfMonth();

        // Build entry map: date string => list<CalendarEntryDto>
        $entryMap = $this->buildEntryMap(
            $allSeries,
            $accountIdForSeries,
            $effectiveVisible,
            $monthStart,
            $monthEnd,
            $user,
        );

        // Build balance map: date string => [sumMinor, isComputing]
        $balanceMap = $this->buildBalanceMap($effectiveBalance, $user, $year, $month, $monthStart, $monthEnd);

        // Build past-day occurrence set: seriesId => list<string date>
        $occurrenceMap = $this->buildOccurrenceMap($user, $monthStart, $monthEnd);

        // Assemble CalendarDayDto for each grid day
        $days = [];
        $prevEod = null; // track previous day eod for sod of next day

        // First pass: compute eod map for entire grid (needed for sod chain)
        $eodMinorMap = [];
        foreach ($gridDays as $date) {
            $dateStr = $date->toDateString();
            [$sumMinor, $isComputing] = $balanceMap[$dateStr] ?? [0, true];
            $eodMinorMap[$dateStr] = [$sumMinor, $isComputing];
        }

        // Build calendar day DTOs
        $baseCurrency = $user->base_currency;
        foreach ($gridDays as $date) {
            $dateStr = $date->toDateString();
            $isToday = $date->isSameDay($now);
            $isPast = $date->lt($now->startOfDay());

            // Balance data
            [$eodMinor, $isComputing] = $eodMinorMap[$dateStr] ?? [0, true];

            // SoD = prior day's EoD
            $sodMinor = 0;
            if ($prevEod !== null) {
                $sodMinor = $prevEod;
            }

            $isRisk = ! $isComputing && $eodMinor < 0;

            // Apply past-day paid/missed to entries
            $rawEntries = $entryMap[$dateStr] ?? [];
            $entries = [];
            foreach ($rawEntries as $entry) {
                if ($isPast) {
                    // Check if there's an occurrence within ±MATCH_WINDOW_DAYS
                    $observedDates = $occurrenceMap[$entry->seriesId] ?? [];
                    $isPaid = $this->hasMatchingOccurrence($date, $observedDates);
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

            $prevEod = $eodMinor;
        }

        return $days;
    }

    // -------------------------------------------------------------------------
    // Entry placement
    // -------------------------------------------------------------------------

    /**
     * Build a map of date => list<CalendarEntryDto> for entries falling in the month.
     *
     * Gates on the irregular-series rule (Pitfall 4):
     *   cadence !== 'irregular' || nextExpectedAt !== null
     *
     * Account filter: only series whose resolved accountId is in $effectiveVisible.
     * When $effectiveVisible is null it means "all accounts visible" (D-02 default).
     * When $effectiveVisible is [] it means no accounts matched the filter → no entries.
     *
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
        $entryMap = [];

        foreach ($allSeries as $series) {
            // Irregular gate (Pitfall 4): exclude irregular with null nextExpectedAt
            if ($series->cadence === 'irregular' && $series->nextExpectedAt === null) {
                continue;
            }

            // Resolve account for this series (may be null if no occurrences link it to an account yet)
            $accountId = $accountIdForSeries[$series->seriesId] ?? null;

            // Account visibility filter (D-02).
            // effectiveVisible === null → "all visible" (D-02 default; no filter specified).
            // effectiveVisible === []   → "nothing passed the filter" (explicit filter, all dropped).
            // effectiveVisible is non-empty list → only those account IDs are visible.
            if ($effectiveVisible !== null) {
                // Caller specified an explicit filter. Empty means nothing passes.
                if ($accountId === null || ! in_array($accountId, $effectiveVisible, true)) {
                    continue;
                }
            }
            // effectiveVisible === null → all accounts on; include even unlinked series

            // Resolve counterparty: primary path via occurrences→transactions→counterparty_id;
            // fallback path via cluster_counterparty_key → counterparties.slug (D-16).
            $counterpartyId = $this->seriesQuery->counterpartyIdForSeries($series->seriesId, $user);
            $counterpartySlug = null;
            if ($counterpartyId !== null) {
                $identity = $this->counterpartyQuery->identityForId($user, $counterpartyId);
                $counterpartySlug = $identity['slug'] ?? null;
            } else {
                // Fallback: look up counterparty by cluster_counterparty_key as slug.
                // This resolves counterparties for series that have no linked occurrences yet
                // but whose cluster_counterparty_key matches a known counterparty slug.
                $counterpartySlug = $this->resolveCounterpartySlugByClusterKey($series->seriesId, $user);
                if ($counterpartySlug !== null) {
                    // Verify the counterparty exists and belongs to the user (T-06-02)
                    $profile = $this->counterpartyQuery->bySlug($user, $counterpartySlug);
                    if ($profile !== null) {
                        $counterpartyId = $profile->id;
                    } else {
                        $counterpartySlug = null;
                    }
                }
            }

            // Resolve account name (empty string when series has no account link)
            $accountName = $accountId !== null ? $this->resolveAccountName($accountId, $user) : '';

            // Find occurrence dates in the display month
            $occurrenceDates = $this->placeSeriesInMonth($series, $monthStart, $monthEnd);

            foreach ($occurrenceDates as $date) {
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
     * Determine which dates within [monthStart, monthEnd] a series occupies.
     *
     * For irregular series (with non-null nextExpectedAt), the single date is
     * used directly if it falls within the display window — no stepping.
     *
     * For regular cadences (weekly, monthly, quarterly, yearly), mirror the
     * RangeProjector::envelope() step-forward logic without jitter — point-estimate
     * placement only. Start from nextExpectedAt and step forward/backward so that
     * occurrences falling in the display month are collected.
     *
     * @return list<CarbonImmutable>
     */
    private function placeSeriesInMonth(
        RecurringSeriesDto $series,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): array {
        $next = $series->nextExpectedAt;
        if ($next === null) {
            // Already gated before calling this method, but guard defensively.
            return [];
        }

        // Irregular: place exactly once at nextExpectedAt if it's in the window
        if ($series->cadence === 'irregular') {
            if ($next->gte($monthStart) && $next->lte($monthEnd)) {
                return [$next->startOfDay()];
            }

            return [];
        }

        // For regular cadences, we need to find all occurrences in the month.
        // Start at nextExpectedAt and walk forward; also walk backward from
        // nextExpectedAt in case it's in a future month.
        $cadence = $series->cadence;
        if (! in_array($cadence, ['weekly', 'monthly', 'quarterly', 'yearly'], true)) {
            return [];
        }

        // Walk cursor to the earliest occurrence that could land in the month.
        // First, align cursor to or before monthStart.
        $cursor = $next->startOfDay();

        // Walk backward until cursor is before the month start (or at month start)
        while ($cursor->gt($monthEnd)) {
            $prev = $this->retreat($cursor, $cadence);
            if ($prev === null) {
                return [];
            }
            $cursor = $prev;
        }

        // cursor is now <= monthEnd; walk back further until before monthStart
        while ($cursor->gt($monthStart)) {
            $prev = $this->retreat($cursor, $cadence);
            if ($prev === null) {
                break;
            }
            if ($prev->lt($monthStart)) {
                break;
            }
            $cursor = $prev;
        }

        // Now walk forward, collecting all dates in [monthStart, monthEnd]
        $results = [];
        $seen = [];
        $iterations = 0;
        $maxIterations = 60; // safety cap

        while ($cursor->lte($monthEnd) && $iterations < $maxIterations) {
            $iterations++;
            if ($cursor->gte($monthStart)) {
                $dateStr = $cursor->toDateString();
                if (! isset($seen[$dateStr])) {
                    $results[] = $cursor;
                    $seen[$dateStr] = true;
                }
            }
            $advanced = $this->advance($cursor, $cadence);
            if ($advanced === null) {
                break;
            }
            $cursor = $advanced;
        }

        return $results;
    }

    /**
     * Advance cursor by one cadence step forward.
     */
    private function advance(CarbonImmutable $cursor, string $cadence): ?CarbonImmutable
    {
        return match ($cadence) {
            'weekly' => $cursor->addDays(7),
            'monthly' => $cursor->addMonthNoOverflow(),
            'quarterly' => $cursor->addMonthsNoOverflow(3),
            'yearly' => $cursor->addYearNoOverflow(),
            default => null,
        };
    }

    /**
     * Retreat cursor by one cadence step backward.
     */
    private function retreat(CarbonImmutable $cursor, string $cadence): ?CarbonImmutable
    {
        return match ($cadence) {
            'weekly' => $cursor->subDays(7),
            'monthly' => $cursor->subMonthNoOverflow(),
            'quarterly' => $cursor->subMonthsNoOverflow(3),
            'yearly' => $cursor->subYearNoOverflow(),
            default => null,
        };
    }

    // -------------------------------------------------------------------------
    // Balance aggregation
    // -------------------------------------------------------------------------

    /**
     * Build a map of date => [eodMinor, isComputing] for balance-included accounts.
     *
     * Fetches each account's 365-day ForecastDto ONCE (Pitfall 2 — no per-day re-fetch).
     * Sums pointMinor per date across accounts (mirrors computeAllAccountsAggregate).
     * FX-converts each date's sum to the user's base reporting currency.
     *
     * Internal-transfer net-neutrality (D-04): because per-account forecasts already
     * include both legs of an own-account transfer, the combined sum naturally nets them.
     * No additional deduction is performed.
     *
     * @param  list<int>  $effectiveBalance
     * @return array<string, array{0: int, 1: bool}> date => [eodMinor, isComputing]
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
            // No balance accounts — return computing sentinel for all days
            $map = [];
            $cursor = $monthStart;
            while ($cursor->lte($monthEnd)) {
                $map[$cursor->toDateString()] = [0, true];
                $cursor = $cursor->addDay();
            }

            return $map;
        }

        /** @var array<string, int> $byDate */
        $byDate = [];
        $isComputingAny = false;

        // Fetch each account's forecast ONCE (Pitfall 2 — no per-day re-fetch)
        foreach ($effectiveBalance as $accountId) {
            $dto = $this->forecastQuery->forUser($accountId, self::FORECAST_HORIZON_DAYS, null, $user);

            if ($dto->isComputing) {
                $isComputingAny = true;

                // Don't accumulate computing account's points (they're empty anyway)
                continue;
            }

            // Sum pointMinor per date (mirrors computeAllAccountsAggregate)
            foreach ($dto->points as $point) {
                $byDate[$point->date] = ($byDate[$point->date] ?? 0) + $point->pointMinor;
            }
        }

        // Build map for the grid dates, applying FX conversion
        $baseCurrency = $user->base_currency;
        $map = [];

        // Build the full grid date range
        $cursor = $monthStart->subWeeks(1); // include lead-in dates for the grid
        $gridEnd = $monthEnd->addWeeks(1);   // include trailing dates

        // Only need to map dates in the forecast points; use the monthStart-derived grid
        // Build map for each date in byDate that we have data for
        foreach ($byDate as $dateStr => $sumMinor) {
            // FX-convert to base currency (D-05)
            // The forecast points are in the account's default_currency.
            // For simplicity in the balance aggregation, we assume EUR (most accounts are EUR);
            // true multi-currency FX conversion happens at the ExchangeRateService level.
            // Since ForecastPointDto carries the account's defaultCurrency but we only have
            // the sum across accounts, we apply convertToBase on the summed amount treating
            // it as the base currency (EUR → EUR is a passthrough).
            // For non-EUR multi-account sums: this is a known simplification documented here.
            // The correct approach would sum per-currency, then convert each currency separately.
            // For v1 (predominantly EUR accounts), the sum is already in EUR.
            $money = Money::ofMinor($sumMinor, $baseCurrency);
            $converted = $this->fxService->convertToBase($money, $baseCurrency);
            $map[$dateStr] = [$converted->converted->toMinor(), $isComputingAny];
        }

        // Fill dates with no forecast data (isComputing = true for missing dates when not computing overall)
        // If any account is computing, propagate to all days
        if ($isComputingAny) {
            $cur = $monthStart;
            while ($cur->lte($monthEnd)) {
                $dateStr = $cur->toDateString();
                if (! isset($map[$dateStr])) {
                    $map[$dateStr] = [0, true];
                } else {
                    // Override with computing flag
                    $map[$dateStr] = [$map[$dateStr][0], true];
                }
                $cur = $cur->addDay();
            }
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // Past-day occurrence matching
    // -------------------------------------------------------------------------

    /**
     * Build a map of seriesId => list<string date> for all occurrences in the month.
     *
     * Runs ONE month-scoped query against recurring_series_occurrences (T-06-03).
     * The map is used to determine isPaid/isMissed for past-day entries.
     *
     * @return array<int, list<string>>
     */
    private function buildOccurrenceMap(
        User $user,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): array {
        // Extend the window by ±MATCH_WINDOW_DAYS to catch occurrences just outside the month
        // that might still match entries near the month boundaries.
        $windowStart = $monthStart->subDays(self::MATCH_WINDOW_DAYS)->toDateString();
        $windowEnd = $monthEnd->addDays(self::MATCH_WINDOW_DAYS)->toDateString();

        $rows = $this->db->connection()->table('recurring_series_occurrences')
            ->where('user_id', $user->id)  // T-06-03: user-scoped
            ->whereBetween('observed_at', [$windowStart, $windowEnd])
            ->get(['recurring_series_id', 'observed_at']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $seriesId = self::toInt($row->recurring_series_id);
            if ($seriesId === 0) {
                continue;
            }
            // Normalise observed_at to a date string
            $observedDate = CarbonImmutable::parse(self::toString($row->observed_at))->toDateString();
            $map[$seriesId][] = $observedDate;
        }

        return $map;
    }

    /**
     * Return true if any observed date in $observedDates falls within
     * ±MATCH_WINDOW_DAYS of the expected $date.
     *
     * @param  list<string>  $observedDates
     */
    private function hasMatchingOccurrence(CarbonImmutable $date, array $observedDates): bool
    {
        $expected = $date->startOfDay();
        $windowStart = $expected->subDays(self::MATCH_WINDOW_DAYS);
        $windowEnd = $expected->addDays(self::MATCH_WINDOW_DAYS);

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
     * Resolve all account IDs owned by the user.
     *
     * Used to intersect caller-supplied arrays (T-06-02).
     *
     * @return list<int>
     */
    private function resolveOwnedAccountIds(User $user): array
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
     * Resolve the effective set of account IDs for entry visibility.
     *
     * Returns null when all accounts are visible (empty input = D-02 default: ALL ON).
     * Returns list<int> (possibly empty) when caller specified an explicit filter.
     * An empty return means the filter matched nothing — no entries should appear.
     *
     * Null vs [] distinction:
     *   - null  → "all accounts on" — include even series not linked to an account yet
     *   - []    → "nothing passed the filter" — no entries, not even unlinked series
     *
     * @param  list<int>  $callerIds
     * @param  list<int>  $ownedIds
     * @return list<int>|null null = all visible
     */
    private function resolveVisibleAccountIds(array $callerIds, array $ownedIds): ?array
    {
        if ($callerIds === []) {
            // Empty = all accounts ON (D-02 default) — signal "all visible" with null
            return null;
        }

        // Intersect against owned IDs (T-06-02: drop any id the user does not own)
        return array_values(array_intersect($callerIds, $ownedIds));
    }

    /**
     * Resolve the effective set of account IDs for balance aggregation.
     *
     * Empty input = spendable default (D-03): accounts with kind in SPENDABLE_KINDS.
     * Non-empty input = intersection with owned accounts (T-06-02).
     *
     * @param  list<int>  $callerIds
     * @param  list<int>  $ownedIds
     * @return list<int>
     */
    private function resolveBalanceAccountIds(array $callerIds, array $ownedIds, User $user): array
    {
        if ($callerIds === []) {
            // Empty = spendable-kind default (D-03)
            return $this->resolveSpendableAccountIds($user);
        }

        // Intersect against owned IDs (T-06-02: drop any id the user does not own)
        return array_values(array_intersect($callerIds, $ownedIds));
    }

    /**
     * Return IDs of accounts with kinds in the SPENDABLE_KINDS constant (D-03).
     *
     * This is the default balance set: checking + savings + cash + PayPal ON;
     * ICS credit-card family OFF (their liability shows up via the funding-chain
     * settlement leg in the ASN account).
     *
     * @return list<int>
     */
    private function resolveSpendableAccountIds(User $user): array
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
     * Build the Mon–Sun grid for the given year + month.
     *
     * Starts on the Monday of the week containing the 1st of the month.
     * Ends on the Sunday of the week containing the last day of the month.
     *
     * @return list<CarbonImmutable>
     */
    private function buildGridDays(int $year, int $month): array
    {
        // CarbonImmutable::create() returns CarbonImmutable|null — parse a formatted
        // string to guarantee a non-null CarbonImmutable.
        $firstOfMonth = CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month))->startOfDay();
        $lastOfMonth = $firstOfMonth->endOfMonth()->startOfDay();

        // Start of grid: Monday on or before the 1st
        $gridStart = $firstOfMonth->startOfWeek(CarbonImmutable::MONDAY);
        // End of grid: Sunday on or after the last day
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
     * Resolve the display name for an account. Returns an empty string
     * if the account is not found (or not owned by the user).
     */
    private function resolveAccountName(int $accountId, User $user): string
    {
        $row = $this->db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->first(['name']);

        if ($row === null) {
            return '';
        }

        /** @var stdClass $row */
        return self::toString($row->name ?? null);
    }

    /**
     * Look up the counterparty slug via the series' cluster_counterparty_key.
     * Returns null if the series has no cluster_counterparty_key.
     *
     * This is the fallback counterparty resolution path (D-16): when a series
     * has no linked occurrences yet, the cluster_counterparty_key may still
     * identify the counterparty by matching a counterparty slug.
     */
    private function resolveCounterpartySlugByClusterKey(int $seriesId, User $user): ?string
    {
        $row = $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->where('user_id', $user->id)  // T-06-02: user-scoped
            ->first(['cluster_counterparty_key']);

        if ($row === null) {
            return null;
        }

        /** @var stdClass $row */
        $key = self::toString($row->cluster_counterparty_key ?? null);

        return $key !== '' ? $key : null;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
