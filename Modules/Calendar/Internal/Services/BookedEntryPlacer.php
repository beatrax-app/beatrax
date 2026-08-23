<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Modules\Calendar\Internal\Dto\CalendarEntryDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\Dto\BookedFutureRowDto;
use Modules\Ledger\Public\Services\BookedFutureRowQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Modules\Recurring\Public\Support\MatchWindow;

// A payment the ledger already holds, dated ahead. SeriesEntryPlacer answers
// what a cadence expects; this answers what is already booked, and where both
// answer for the same payment the booked one is the one that happens.
/**
 * @link ../../../../.docs/features/calendar/architecture.md#booked-rows-dated-ahead
 */
final readonly class BookedEntryPlacer
{
    public function __construct(
        private BookedFutureRowQuery $bookedRows,
        private RecurringSeriesQuery $seriesQuery,
        private AccountResolver $accountResolver,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, list<CalendarEntryDto>>  $seriesEntries
     * @param  list<int>|null  $effectiveVisible  null = all visible; [] = none visible
     * @return array<string, list<CalendarEntryDto>>
     */
    public function mergeInto(
        array $seriesEntries,
        User $user,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        ?array $effectiveVisible,
    ): array {
        if ($effectiveVisible === []) {
            return $seriesEntries;
        }

        $today = $this->clock->now()->startOfDay();
        // A past day already draws its entries against what was actually
        // observed, so a booked row behind today is a payment the paid/missed
        // pass has covered and would be listed twice here.
        $from = $monthStart->subDay()->greaterThan($today) ? $monthStart->subDay() : $today;

        $rows = $this->bookedRows->between($user, $from, $monthEnd, $effectiveVisible);
        if ($rows === []) {
            return $seriesEntries;
        }

        $entries = $this->withoutSuperseded($seriesEntries, $rows, $user, $today);
        $accountNames = $this->accountResolver->accountNamesForUser($user);

        foreach ($rows as $row) {
            $entries[$row->postedAt->toDateString()][] = new CalendarEntryDto(
                seriesId: null,
                name: $row->counterpartyName ?? Lang::get('calendar::messages.entry.booked_unnamed'),
                amountMinor: $row->settled->toMinor(),
                currency: $row->settled->currency(),
                direction: $row->direction->value,
                accountId: $row->accountId,
                accountName: $accountNames[$row->accountId] ?? '',
                counterpartyId: null,
                counterpartySlug: $row->counterpartySlug,
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
     * @return array<string, list<CalendarEntryDto>>
     */
    private function withoutSuperseded(array $seriesEntries, array $rows, User $user, CarbonImmutable $today): array
    {
        $seriesByTransaction = $this->seriesQuery->seriesIdsForTransactionIds(
            array_map(static fn (BookedFutureRowDto $row): int => $row->transactionId, $rows),
            $user,
        );
        if ($seriesByTransaction === []) {
            return $seriesEntries;
        }

        /** @var array<int, list<CarbonImmutable>> $bookedDatesBySeries */
        $bookedDatesBySeries = [];
        foreach ($rows as $row) {
            $seriesId = $seriesByTransaction[$row->transactionId] ?? null;
            if ($seriesId !== null) {
                $bookedDatesBySeries[$seriesId][] = $row->postedAt;
            }
        }

        $kept = [];
        foreach ($seriesEntries as $dateStr => $entries) {
            $date = SafeDate::parseDayOrNull($dateStr);
            // Only ahead of today: the window reaches back a week, and a day
            // behind today owes the reader its paid-or-missed verdict rather
            // than a row silently removed from it.
            $survivors = $date === null || $date->lessThanOrEqualTo($today)
                ? $entries
                : array_values(array_filter(
                    $entries,
                    static function (CalendarEntryDto $entry) use ($bookedDatesBySeries, $date): bool {
                        if ($entry->seriesId === null) {
                            return true;
                        }

                        foreach ($bookedDatesBySeries[$entry->seriesId] ?? [] as $bookedDate) {
                            if (abs($bookedDate->diffInDays($date)) <= MatchWindow::DAYS) {
                                return false;
                            }
                        }

                        return true;
                    },
                ));

            if ($survivors !== []) {
                $kept[$dateStr] = $survivors;
            }
        }

        return $kept;
    }
}
