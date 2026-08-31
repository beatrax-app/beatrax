<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Modules\Calendar\Internal\Dto\CalendarEntryDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\Dto\BookedFutureRowDto;
use Modules\Ledger\Public\Services\BookedFutureRowQuery;
use Modules\Recurring\Public\Services\TransactionSeriesMembershipQuery;
use Modules\Recurring\Public\Support\OccurrenceSupersession;

// A payment the ledger holds, on any day of the grid. SeriesEntryPlacer answers
// what a cadence expects; this answers what is actually booked, and where both
// answer for the same payment the booked one is the one that happens.
/**
 * @link ../../../../.docs/features/calendar/architecture.md#booked-rows-and-the-cadences-that-predicted-them
 */
final readonly class BookedEntryPlacer
{
    public function __construct(
        private BookedFutureRowQuery $bookedRows,
        private TransactionSeriesMembershipQuery $seriesMembership,
        private AccountResolver $accountResolver,
    ) {}

    /**
     * @param  array<string, list<CalendarEntryDto>>  $seriesEntries
     * @param  list<int>|null  $effectiveVisible  null = all visible; [] = none visible
     * @return array<string, list<CalendarEntryDto>>
     */
    public function mergeInto(
        array $seriesEntries,
        User $user,
        CarbonImmutable $gridStart,
        CarbonImmutable $gridEnd,
        ?array $effectiveVisible,
    ): array {
        if ($effectiveVisible === []) {
            return $seriesEntries;
        }

        // BookedFutureRowQuery::between() takes an EXCLUSIVE lower bound, so
        // the first grid cell is asked for as the day before it — that
        // subtraction is compensation for the exclusivity, not a wider reach,
        // and anything wider reaches a cell SeriesEntryPlacer cannot.
        $rows = $this->bookedRows->between($user, $gridStart->subDay(), $gridEnd, $effectiveVisible);
        if ($rows === []) {
            return $seriesEntries;
        }

        $seriesByTransaction = $this->seriesMembership->seriesIdsForTransactionIds(
            array_map(static fn (BookedFutureRowDto $row): int => $row->transactionId, $rows),
            $user,
        );

        ['entries' => $entries, 'retired' => $retired]
            = $this->withoutSuperseded($seriesEntries, $rows, $seriesByTransaction);
        $accountNames = $this->accountResolver->accountNamesForUser($user);

        foreach ($rows as $row) {
            $seriesId = $seriesByTransaction[$row->transactionId] ?? null;
            $estimate = $seriesId === null ? null : ($retired[$seriesId] ?? null);

            $entries[$row->postedAt->toDateString()][] = new CalendarEntryDto(
                // The estimate this row retired is the same payment under the
                // reader's own name for it, so the survivor keeps that name and
                // its series drill-through: a bare counterparty string in their
                // place loses information the grid was already showing.
                seriesId: $estimate?->seriesId,
                name: $estimate->name ?? $row->counterpartyName ?? Lang::get('calendar::messages.entry.booked_unnamed'),
                amountMinor: $row->settled->toMinor(),
                currency: $row->settled->currency(),
                direction: $row->direction->value,
                accountId: $row->accountId,
                accountName: $accountNames[$row->accountId] ?? '',
                counterpartyId: $estimate?->counterpartyId,
                counterpartySlug: $row->counterpartySlug ?? $estimate?->counterpartySlug,
                isPaid: false,
                isMissed: false,
                isApproximate: false,
                transactionId: $row->transactionId,
            );
        }

        return $entries;
    }

    /**
     * @param  array<string, list<CalendarEntryDto>>  $seriesEntries
     * @param  list<BookedFutureRowDto>  $rows
     * @param  array<int, int>  $seriesByTransaction
     * @return array{entries: array<string, list<CalendarEntryDto>>, retired: array<int, CalendarEntryDto>}
     */
    private function withoutSuperseded(array $seriesEntries, array $rows, array $seriesByTransaction): array
    {
        if ($seriesByTransaction === []) {
            return ['entries' => $seriesEntries, 'retired' => []];
        }

        /** @var array<int, list<CarbonImmutable>> $bookedDatesBySeries */
        $bookedDatesBySeries = [];
        foreach ($rows as $row) {
            $seriesId = $seriesByTransaction[$row->transactionId] ?? null;
            if ($seriesId !== null) {
                $bookedDatesBySeries[$seriesId][] = $row->postedAt;
            }
        }
        if ($bookedDatesBySeries === []) {
            return ['entries' => $seriesEntries, 'retired' => []];
        }

        $superseded = $this->supersededDatesBySeries($seriesEntries, $bookedDatesBySeries);

        $kept = [];
        $retired = [];
        foreach ($seriesEntries as $dateStr => $entries) {
            $survivors = [];
            foreach ($entries as $entry) {
                if ($entry->seriesId !== null && isset($superseded[$entry->seriesId][$dateStr])) {
                    $retired[$entry->seriesId] ??= $entry;

                    continue;
                }
                $survivors[] = $entry;
            }

            if ($survivors !== []) {
                $kept[$dateStr] = $survivors;
            }
        }

        return ['entries' => $kept, 'retired' => $retired];
    }

    /**
     * @param  array<string, list<CalendarEntryDto>>  $seriesEntries
     * @param  array<int, list<CarbonImmutable>>  $bookedDatesBySeries
     * @return array<int, array<string, true>>
     */
    private function supersededDatesBySeries(array $seriesEntries, array $bookedDatesBySeries): array
    {
        /** @var array<int, array<string, CarbonImmutable>> $expectedBySeries */
        $expectedBySeries = [];
        foreach ($seriesEntries as $dateStr => $entries) {
            $date = SafeDate::normalisedDayOrNull($dateStr);
            if ($date === null) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry->seriesId !== null && isset($bookedDatesBySeries[$entry->seriesId])) {
                    $expectedBySeries[$entry->seriesId][$dateStr] = $date;
                }
            }
        }

        $superseded = [];
        foreach ($expectedBySeries as $seriesId => $expectedDates) {
            $superseded[$seriesId] = OccurrenceSupersession::supersededDates(
                $bookedDatesBySeries[$seriesId],
                array_values($expectedDates),
            );
        }

        return $superseded;
    }
}
