<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Modules\Calendar\Internal\Dto\CalendarDayDto;
use Modules\Calendar\Internal\Dto\CalendarEntryDto;
use Modules\Calendar\Internal\Dto\DayBalanceDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\BookedFutureRowQuery;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

final readonly class CalendarQuery
{
    public function __construct(
        private Clock $clock,
        private CalendarMonthWindow $monthWindow,
        private RecurringSeriesQuery $seriesQuery,
        private AccountResolver $accountResolver,
        private SeriesEntryPlacer $entryPlacer,
        private DailyBalanceAggregator $balanceAggregator,
        private OccurrenceMatcher $occurrenceMatcher,
        private BookedEntryPlacer $bookedEntryPlacer,
        private BookedFutureRowQuery $bookedRows,
        private BaseCurrency $baseCurrencies,
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
        $ownedIds = $this->accountResolver->ownedAccountIds($user);
        $effectiveVisible = $this->accountResolver->resolveVisibleAccountIds($visibleAccountIds, $ownedIds);
        $effectiveBalance = $this->accountResolver->resolveBalanceAccountIds($balanceAccountIds, $ownedIds, $user);

        ['start' => $gridStart, 'end' => $gridEnd] = CalendarGrid::range($year, $month);
        $gridDays = CalendarGrid::days($gridStart, $gridEnd);

        $allSeries = $this->seriesQuery->allApprovedForUser($user);
        $seriesIds = array_map(static fn (RecurringSeriesDto $s): int => $s->seriesId, $allSeries);
        $accountIdForSeries = $seriesIds !== []
            ? $this->seriesQuery->accountIdsForSeriesIds($seriesIds, $user)
            : [];

        $now = $this->clock->now();

        // Every map spans the grid, not the month. A lead-in or lead-out cell
        // draws a balance corner and takes a click like any other, so building
        // its entries over the month left it announcing "0 entries" under a
        // step the reader could see and could not open.
        $entryMap = $this->bookedEntryPlacer->mergeInto(
            $this->entryPlacer->buildEntryMap(
                $allSeries,
                $accountIdForSeries,
                $effectiveVisible,
                $gridStart,
                $gridEnd,
                $user,
            ),
            $user,
            $gridStart,
            $gridEnd,
            $effectiveVisible,
        );

        ['map' => $balanceMap, 'todayAnchorMinor' => $todayAnchorMinor, 'gridStartOpening' => $gridStartOpening]
            = $this->balanceAggregator->buildBalanceMap($effectiveBalance, $user, $gridStart, $gridEnd);

        $occurrenceMap = $this->occurrenceMatcher->buildOccurrenceMap($user, $gridStart, $gridEnd);

        /** @var array<int, SeriesCadence> $cadenceBySeries */
        $cadenceBySeries = [];
        foreach ($allSeries as $series) {
            $cadenceBySeries[$series->seriesId] = $series->cadence;
        }

        // Seeded with what the grid's first day opened on where the actuals
        // overlay reaches back that far, and null everywhere else: the day
        // after a data-less day must report "SoD unknown", never a fabricated 0.
        $prevEod = $gridStartOpening !== null && $gridStartOpening->isKnown()
            ? $gridStartOpening->minor
            : null;
        $baseCurrency = $this->baseCurrencies->forUser($user);

        $days = [];
        foreach ($gridDays as $date) {
            $dateStr = $date->toDateString();
            $isToday = $date->isSameDay($now);
            $isPast = $date->lt($now->startOfDay());

            $balance = $balanceMap[$dateStr] ?? new DayBalanceDto(minor: 0, isComputing: true, hasFigure: false);

            // Today needs the anchor fallback: yesterday is a past day with no
            // forecast point, so the chain alone would leave today unknown.
            $sodMinor = $prevEod ?? ($isToday ? $todayAnchorMinor : null);

            $entries = $this->buildDayEntries($entryMap[$dateStr] ?? [], $isPast, $date, $occurrenceMap, $cadenceBySeries);

            $days[] = new CalendarDayDto(
                date: $date,
                isToday: $isToday,
                isPast: $isPast,
                // Not `$eodMinor < 0`: a line the rate table could not reach
                // converts to nothing, so a day overdrawn only in that currency
                // read as exactly zero and never tinted.
                isRisk: ! $balance->isComputing && $balance->isNegative,
                sodBalanceMinor: $sodMinor,
                eodBalanceMinor: $balance->minor,
                currency: $baseCurrency,
                isComputing: $balance->isComputing,
                entries: $entries,
                unconvertedCurrencies: $balance->unconvertedCurrencies,
                hasBalanceFigure: $balance->isKnown(),
                // Asked only where the day states a figure: a day rendering
                // "—" makes no arithmetic claim to disown, and with no
                // balance source at all every account would be named at once.
                uncountedAccounts: $balance->isKnown()
                    ? self::accountsOutsideBalance($entries, $effectiveBalance)
                    : [],
            );

            $prevEod = $balance->isKnown() ? $balance->minor : null;
        }

        return $days;
    }

    // Whether the reader has anything the calendar could ever draw, which is not
    // the same question as whether the month on screen is quiet. A booked row
    // dated ahead counts: the grid draws it, so telling its owner to go approve
    // a series was the calendar disowning what it was already showing.
    public function hasProjectableEntries(User $user): bool
    {
        if ($this->seriesQuery->hasApprovedForUser($user)) {
            return true;
        }

        $today = $this->clock->now()->startOfDay();

        // The last cell the grid draws, taken from the window that draws it.
        // Asked to the ceiling month's own end instead, this went blind to the
        // lead-out days of that month's strip: the grid drew a booked charge
        // under a banner reading "No upcoming payments".
        return $this->bookedRows->hasAnyAfter($user, $today, $this->monthWindow->lastDrawableDay());
    }

    /**
     * @return list<int>
     */
    public function ownedAccountIds(User $user): array
    {
        return $this->accountResolver->ownedAccountIds($user);
    }

    // Accounts that actually feed the balance line. Empty means the balance is
    // unknown for want of a source, which is not the same as being computed.
    /**
     * @param  list<int>|null  $balanceAccountIds
     * @param  list<int>  $ownedIds
     * @return list<int>
     */
    public function effectiveBalanceAccountIds(?array $balanceAccountIds, array $ownedIds, User $user): array
    {
        return $this->accountResolver->resolveBalanceAccountIds($balanceAccountIds, $ownedIds, $user);
    }

    /**
     * @return list<int>
     */
    public function spendableAccountIds(User $user): array
    {
        return $this->accountResolver->spendableAccountIds($user);
    }

    // The two halves of a day panel are drawn from two account sets, and the
    // reader reads them as one sum. An entry the balance set leaves out cannot
    // move either figure, so the day names the account it is on rather than
    // presenting a start and an end the rows between them do not reach.
    /**
     * @param  list<CalendarEntryDto>  $entries
     * @param  list<int>  $effectiveBalance
     * @return list<string>
     */
    private static function accountsOutsideBalance(array $entries, array $effectiveBalance): array
    {
        $names = [];
        foreach ($entries as $entry) {
            // An entry on no account at all has no name to give, and the page
            // only ever asks with an explicit visible set, which drops those.
            if ($entry->accountId === null || in_array($entry->accountId, $effectiveBalance, true)) {
                continue;
            }
            if ($entry->accountName !== '') {
                $names[$entry->accountName] = true;
            }
        }

        $sorted = array_keys($names);
        sort($sorted);

        return $sorted;
    }

    /**
     * @param  list<CalendarEntryDto>  $rawEntries
     * @param  array<int, list<string>>  $occurrenceMap
     * @param  array<int, SeriesCadence>  $cadenceBySeries
     * @return list<CalendarEntryDto>
     */
    private function buildDayEntries(
        array $rawEntries,
        bool $isPast,
        CarbonImmutable $date,
        array $occurrenceMap,
        array $cadenceBySeries,
    ): array {
        if (! $isPast) {
            return $rawEntries;
        }

        $entries = [];
        foreach ($rawEntries as $entry) {
            $observedDates = $entry->seriesId === null ? [] : ($occurrenceMap[$entry->seriesId] ?? []);
            $windowDays = $this->occurrenceMatcher->matchWindowDays(
                $entry->seriesId === null ? null : ($cadenceBySeries[$entry->seriesId] ?? null),
            );
            // A row the ledger holds is the payment, not a prediction of it,
            // so it is settled by its own existence. Through the occurrence
            // match instead, every plain imported row on a past day read
            // "missed" — an amber ! beside money that demonstrably moved.
            $isPaid = $entry->transactionId !== null
                || $this->occurrenceMatcher->hasMatchingOccurrence($date, $observedDates, $windowDays);

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
                isMissed: ! $isPaid,
                isApproximate: $entry->isApproximate,
                transactionId: $entry->transactionId,
            );
        }

        return $entries;
    }
}
