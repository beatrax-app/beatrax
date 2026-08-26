<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\BookedFutureRowDto;
use Modules\Ledger\Public\Services\BookedFutureRowQuery;
use Modules\Recurring\Public\Services\TransactionSeriesMembershipQuery;
use Modules\Recurring\Public\Support\OccurrenceSupersession;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md#booked-future-dated-rows
 */
final readonly class BookedRowProjector
{
    // The sentinel ChainAwareForecastRouter already reads as "no series behind
    // this": a booked row sits on the account the ledger says it does, so no
    // chain link may re-route it onto a funder.
    private const int NO_SERIES = 0;

    public function __construct(
        private BookedFutureRowQuery $bookedRows,
        private TransactionSeriesMembershipQuery $seriesMembership,
    ) {}

    /**
     * @param  list<ForecastContribution>  $seriesContributions
     * @param  array<int, string>  $currencyByAccountId
     * @return list<ForecastContribution>
     */
    public function mergeInto(
        array $seriesContributions,
        User $user,
        CarbonImmutable $asOf,
        int $horizonDays,
        array $currencyByAccountId,
    ): array {
        $rows = $this->inProjectionCurrency(
            $this->bookedRows->between($user, $asOf, $asOf->addDays($horizonDays)),
            $currencyByAccountId,
        );
        if ($rows === []) {
            return $seriesContributions;
        }

        $contributions = [];
        foreach ($rows as $row) {
            // No band: the amount is not an estimate of a charge, it is the
            // charge. Widening it would invent uncertainty the row does not
            // carry, and centring an envelope on it would move the point.
            $minor = $row->settled->toMinor();
            $contributions[] = new ForecastContribution(
                date: $row->postedAt,
                pointMinor: $minor,
                lowMinor: $minor,
                highMinor: $minor,
                currency: $row->settled->currency(),
                fxRateUsed: null,
                seriesId: self::NO_SERIES,
                accountId: $row->accountId,
            );
        }

        return [...$this->withoutSuperseded($seriesContributions, $rows, $user), ...$contributions];
    }

    /**
     * @param  list<BookedFutureRowDto>  $rows
     * @param  array<int, string>  $currencyByAccountId
     * @return list<BookedFutureRowDto>
     */
    private function inProjectionCurrency(array $rows, array $currencyByAccountId): array
    {
        // A projection runs on the one line its account is denominated in, and
        // its opening balance left every other line out. A row settled in one
        // of those has no anchor here to move.
        return array_values(array_filter(
            $rows,
            static fn (BookedFutureRowDto $row): bool => ($currencyByAccountId[$row->accountId] ?? null) === $row->settled->currency(),
        ));
    }

    /**
     * @param  list<ForecastContribution>  $seriesContributions
     * @param  list<BookedFutureRowDto>  $rows
     * @return list<ForecastContribution>
     */
    private function withoutSuperseded(array $seriesContributions, array $rows, User $user): array
    {
        $seriesByTransaction = $this->seriesMembership->seriesIdsForTransactionIds(
            array_map(static fn (BookedFutureRowDto $row): int => $row->transactionId, $rows),
            $user,
        );
        if ($seriesByTransaction === []) {
            return $seriesContributions;
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
            return $seriesContributions;
        }

        $superseded = $this->supersededDatesBySeries($seriesContributions, $bookedDatesBySeries);

        // The booked row wins over the estimate that guessed at it: one is what
        // the account will be charged, the other is what a cadence suggests it
        // might be. Emitting both drew one rent twice.
        return array_values(array_filter(
            $seriesContributions,
            static fn (ForecastContribution $contribution): bool => ! isset(
                $superseded[$contribution->seriesId][$contribution->date->toDateString()]
            ),
        ));
    }

    /**
     * @param  list<ForecastContribution>  $seriesContributions
     * @param  array<int, list<CarbonImmutable>>  $bookedDatesBySeries
     * @return array<int, array<string, true>>
     */
    private function supersededDatesBySeries(array $seriesContributions, array $bookedDatesBySeries): array
    {
        /** @var array<int, array<string, CarbonImmutable>> $expectedBySeries */
        $expectedBySeries = [];
        foreach ($seriesContributions as $contribution) {
            if (isset($bookedDatesBySeries[$contribution->seriesId])) {
                $expectedBySeries[$contribution->seriesId][$contribution->date->toDateString()] = $contribution->date;
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
