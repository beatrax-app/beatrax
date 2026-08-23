<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Modules\Calendar\Internal\Dto\CalendarDayDto;
use Modules\Calendar\Internal\Dto\CalendarEntryDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Services\BookedFutureRowQuery;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

final readonly class CalendarQuery
{
    // How far ahead /calendar will render. The page clamps its month
    // navigation to it and the empty state asks over the same reach, so a
    // reader is never told there is nothing on a horizon they cannot open.
    public const int HORIZON_MONTHS = 12;

    public function __construct(
        private Clock $clock,
        private RecurringSeriesQuery $seriesQuery,
        private AccountResolver $accountResolver,
        private SeriesEntryPlacer $entryPlacer,
        private DailyBalanceAggregator $balanceAggregator,
        private OccurrenceMatcher $occurrenceMatcher,
        private BookedEntryPlacer $bookedEntryPlacer,
        private BookedFutureRowQuery $bookedRows,
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

        $gridDays = $this->buildGridDays($year, $month);

        $allSeries = $this->seriesQuery->allApprovedForUser($user);
        $seriesIds = array_map(static fn ($s): int => $s->seriesId, $allSeries);
        $accountIdForSeries = $seriesIds !== []
            ? $this->seriesQuery->accountIdsForSeriesIds($seriesIds, $user)
            : [];

        $now = $this->clock->now();
        // parse() over create(), whose CarbonImmutable|null return would need
        // a null branch PHPStan cannot see is unreachable.
        $monthStart = CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month));
        $monthEnd = $monthStart->endOfMonth();

        $entryMap = $this->bookedEntryPlacer->mergeInto(
            $this->entryPlacer->buildEntryMap(
                $allSeries,
                $accountIdForSeries,
                $effectiveVisible,
                $monthStart,
                $monthEnd,
                $user,
            ),
            $user,
            $monthStart,
            $monthEnd,
            $effectiveVisible,
        );

        ['map' => $balanceMap, 'todayAnchorMinor' => $todayAnchorMinor]
            = $this->balanceAggregator->buildBalanceMap($effectiveBalance, $user, $monthStart, $monthEnd);

        $occurrenceMap = $this->occurrenceMatcher->buildOccurrenceMap($user, $monthStart, $monthEnd);

        /** @var array<int, SeriesCadence> $cadenceBySeries */
        $cadenceBySeries = [];
        foreach ($allSeries as $series) {
            $cadenceBySeries[$series->seriesId] = $series->cadence;
        }

        // Stays null until a known EoD is seen: the day after a data-less day
        // must report "SoD unknown" rather than a fabricated 0.
        $prevEod = null;
        $baseCurrency = $user->base_currency;

        $days = [];
        foreach ($gridDays as $date) {
            $dateStr = $date->toDateString();
            $isToday = $date->isSameDay($now);
            $isPast = $date->lt($now->startOfDay());

            [$eodMinor, $isComputing] = $balanceMap[$dateStr] ?? [0, true];

            // Today needs the anchor fallback: yesterday is a past day with no
            // forecast point, so the chain alone would leave today unknown.
            $sodMinor = $prevEod ?? ($isToday ? $todayAnchorMinor : null);

            $days[] = new CalendarDayDto(
                date: $date,
                isToday: $isToday,
                isPast: $isPast,
                isRisk: ! $isComputing && $eodMinor < 0,
                sodBalanceMinor: $sodMinor,
                eodBalanceMinor: $eodMinor,
                currency: $baseCurrency,
                isComputing: $isComputing,
                entries: $this->buildDayEntries($entryMap[$dateStr] ?? [], $isPast, $date, $occurrenceMap, $cadenceBySeries),
            );

            $prevEod = $isComputing ? null : $eodMinor;
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

        return $this->bookedRows->hasAnyAfter($user, $today, $today->addMonths(self::HORIZON_MONTHS)->endOfMonth());
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
            $isPaid = $this->occurrenceMatcher->hasMatchingOccurrence($date, $observedDates, $windowDays);

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

    /**
     * @return list<CarbonImmutable>
     */
    private function buildGridDays(int $year, int $month): array
    {
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
}
