<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Modules\Calendar\Public\Dto\CalendarDayDto;
use Modules\Calendar\Public\Dto\CalendarEntryDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

final readonly class CalendarQuery
{
    public function __construct(
        private Clock $clock,
        private RecurringSeriesQuery $seriesQuery,
        private AccountResolver $accountResolver,
        private SeriesEntryPlacer $entryPlacer,
        private DailyBalanceAggregator $balanceAggregator,
        private OccurrenceMatcher $occurrenceMatcher,
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
        // CarbonImmutable::create() returns CarbonImmutable|null — parse() on
        // a formatted date string guarantees a non-null CarbonImmutable.
        $monthStart = CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month));
        $monthEnd = $monthStart->endOfMonth();

        $entryMap = $this->entryPlacer->buildEntryMap(
            $allSeries,
            $accountIdForSeries,
            $effectiveVisible,
            $monthStart,
            $monthEnd,
            $user,
        );

        ['map' => $balanceMap, 'todayAnchorMinor' => $todayAnchorMinor]
            = $this->balanceAggregator->buildBalanceMap($effectiveBalance, $user, $monthStart, $monthEnd);

        $occurrenceMap = $this->occurrenceMatcher->buildOccurrenceMap($user, $monthStart, $monthEnd);

        /** @var array<int, SeriesCadence> $cadenceBySeries */
        $cadenceBySeries = [];
        foreach ($allSeries as $series) {
            $cadenceBySeries[$series->seriesId] = $series->cadence;
        }

        // SoD chain: null until a known (non-computing) EoD is seen — a day
        // after a data-less day must report "SoD unknown", not a fake 0.
        $prevEod = null;
        $baseCurrency = $user->base_currency;

        $days = [];
        foreach ($gridDays as $date) {
            $dateStr = $date->toDateString();
            $isToday = $date->isSameDay($now);
            $isPast = $date->lt($now->startOfDay());

            [$eodMinor, $isComputing] = $balanceMap[$dateStr] ?? [0, true];

            // SoD = prior day's EoD when known; today falls back to the
            // forecast's as-of anchor sum (yesterday is a past day with no
            // forecast point, so the chain alone would leave today unknown).
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

    /**
     * @return list<int>
     */
    public function ownedAccountIds(User $user): array
    {
        return $this->accountResolver->ownedAccountIds($user);
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

        // Past days resolve each expected entry to paid/missed by matching it
        // against observed occurrences within the cadence-clamped window.
        $entries = [];
        foreach ($rawEntries as $entry) {
            $observedDates = $occurrenceMap[$entry->seriesId] ?? [];
            $windowDays = $this->occurrenceMatcher->matchWindowDays($cadenceBySeries[$entry->seriesId] ?? null);
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
            );
        }

        return $entries;
    }

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
}
