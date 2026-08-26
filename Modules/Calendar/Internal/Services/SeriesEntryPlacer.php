<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Calendar\Internal\Dto\CalendarEntryDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Duration;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Modules\Recurring\Public\Support\MatchWindow;
use stdClass;

final readonly class SeriesEntryPlacer
{
    use CoercesScalars;

    private const int DAYS_PER_WEEK = 7;

    private const int MONTHS_PER_QUARTER = 3;

    private const int MONTHS_PER_YEAR = 12;

    // Bounds the placement loop against a pathological anchor; 60 steps
    // covers any single month.
    private const int MAX_PLACEMENT_ITERATIONS = 60;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private RecurringSeriesQuery $seriesQuery,
        private CounterpartyProfileQuery $counterpartyQuery,
        private AccountResolver $accountResolver,
    ) {}

    /**
     * @param  list<RecurringSeriesDto>  $allSeries
     * @param  array<int, int>  $accountIdForSeries
     * @param  list<int>|null  $effectiveVisible  null = all visible; [] = none visible
     * @return array<string, list<CalendarEntryDto>>
     */
    public function buildEntryMap(
        array $allSeries,
        array $accountIdForSeries,
        ?array $effectiveVisible,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        User $user,
    ): array {
        $candidates = $this->entryCandidates($allSeries, $accountIdForSeries, $effectiveVisible);
        if ($candidates === []) {
            return [];
        }

        $placed = $this->placeCandidates($candidates, $monthStart, $monthEnd, $user);
        if ($placed === []) {
            return [];
        }

        $resolved = $this->resolveCounterparties($placed, $user);

        return $this->assembleEntryMap($placed, $resolved, $this->accountResolver->accountNamesForUser($user));
    }

    /**
     * @param  list<RecurringSeriesDto>  $allSeries
     * @param  array<int, int>  $accountIdForSeries
     * @param  list<int>|null  $effectiveVisible  null = all visible; [] = none visible
     * @return list<array{series: RecurringSeriesDto, accountId: int|null}>
     */
    private function entryCandidates(array $allSeries, array $accountIdForSeries, ?array $effectiveVisible): array
    {
        $candidates = [];
        foreach ($allSeries as $series) {
            if ($series->cadence === SeriesCadence::Irregular && $series->nextExpectedAt === null) {
                continue;
            }

            $accountId = $accountIdForSeries[$series->seriesId] ?? null;

            if ($effectiveVisible !== null && ($accountId === null || ! in_array($accountId, $effectiveVisible, true))) {
                continue;
            }

            $candidates[] = ['series' => $series, 'accountId' => $accountId];
        }

        return $candidates;
    }

    /**
     * @param  list<array{series: RecurringSeriesDto, accountId: int|null}>  $candidates
     * @return list<array{series: RecurringSeriesDto, accountId: int|null, dates: list<CarbonImmutable>}>
     */
    private function placeCandidates(
        array $candidates,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        User $user,
    ): array {
        // The backward projection must not fabricate expected occurrences
        // from before the series existed.
        $candidateIds = array_map(static fn (array $c): int => $c['series']->seriesId, $candidates);
        $startFloors = $this->seriesStartFloors($candidateIds, $user);

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

        return $placed;
    }

    /**
     * @param  list<array{series: RecurringSeriesDto, accountId: int|null, dates: list<CarbonImmutable>}>  $placed
     * @return array<int, array{id: int|null, slug: string|null}>
     */
    private function resolveCounterparties(array $placed, User $user): array
    {
        $placedSeriesIds = array_map(static fn (array $p): int => $p['series']->seriesId, $placed);

        $counterpartyIdBySeries = $this->seriesQuery->counterpartyIdsForSeriesIds($placedSeriesIds, $user);
        $identityByCounterpartyId = $this->counterpartyQuery->identitiesForIds(
            $user,
            array_values(array_unique(array_values($counterpartyIdBySeries))),
        );

        // A series with no occurrence-linked counterparty can still resolve
        // when cluster_counterparty_key happens to equal a counterparties.slug
        // — single-token merchants only, and never once at-rest encryption has
        // made that key a digest. See sensitive-columns-at-rest.md.
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

        $resolved = [];
        foreach ($placedSeriesIds as $seriesId) {
            $counterpartyId = $counterpartyIdBySeries[$seriesId] ?? null;
            $slug = null;
            if ($counterpartyId !== null) {
                $slug = $identityByCounterpartyId[$counterpartyId]['slug'] ?? null;
            } else {
                $clusterKey = $clusterKeyBySeries[$seriesId] ?? null;
                if ($clusterKey !== null && isset($counterpartyIdBySlug[$clusterKey])) {
                    $counterpartyId = $counterpartyIdBySlug[$clusterKey];
                    $slug = $clusterKey;
                }
            }

            $resolved[$seriesId] = ['id' => $counterpartyId, 'slug' => $slug];
        }

        return $resolved;
    }

    /**
     * @param  list<array{series: RecurringSeriesDto, accountId: int|null, dates: list<CarbonImmutable>}>  $placed
     * @param  array<int, array{id: int|null, slug: string|null}>  $resolved
     * @param  array<int, string>  $accountNames
     * @return array<string, list<CalendarEntryDto>>
     */
    private function assembleEntryMap(array $placed, array $resolved, array $accountNames): array
    {
        $entryMap = [];
        foreach ($placed as $placement) {
            $series = $placement['series'];
            $accountId = $placement['accountId'];
            $counterparty = $resolved[$series->seriesId] ?? ['id' => null, 'slug' => null];
            $accountName = $accountId !== null ? ($accountNames[$accountId] ?? '') : '';

            foreach ($placement['dates'] as $date) {
                $entryMap[$date->toDateString()][] = new CalendarEntryDto(
                    seriesId: $series->seriesId,
                    name: $series->displayName(),
                    amountMinor: $series->latestAmount->toMinor(),
                    currency: $series->latestAmount->currency(),
                    direction: $series->direction,
                    accountId: $accountId,
                    accountName: $accountName,
                    counterpartyId: $counterparty['id'],
                    counterpartySlug: $counterparty['slug'],
                    isPaid: false,
                    isMissed: false,
                    isApproximate: $series->nextExpectedConfidenceLow,
                );
            }
        }

        return $entryMap;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function placeSeriesInMonth(
        RecurringSeriesDto $series,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        ?CarbonImmutable $seriesStart = null,
    ): array {
        $next = $series->nextExpectedAt;
        if ($next === null || ($seriesStart !== null && $monthEnd->lt($seriesStart))) {
            return [];
        }

        if ($series->cadence === SeriesCadence::Irregular) {
            return $this->placeIrregular($next, $monthStart, $monthEnd);
        }

        return $this->placeRegular($next, $series->cadence, $monthStart, $monthEnd, $seriesStart);
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function placeIrregular(
        CarbonImmutable $next,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): array {
        if ($next->gte($monthStart) && $next->lte($monthEnd)) {
            return [$next->startOfDay()];
        }

        return [];
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function placeRegular(
        CarbonImmutable $next,
        SeriesCadence $cadence,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        ?CarbonImmutable $seriesStart,
    ): array {
        // k runs negative below, for the history fill-in; SeriesCadence::occurrenceAt
        // is what makes both directions read off the same anchor.
        $anchor = $next->startOfDay();
        $k = $this->firstOccurrenceIndex($anchor, $cadence, $monthStart);

        // Backward steps are history fill-in. The anchor is the app's own
        // answer to when the next charge falls, and the forecast walks
        // forward from it, so a step behind the anchor that lands on a day
        // still to come is an entry no balance line will ever account for.
        $today = $this->clock->now()->startOfDay();

        // Dates increase strictly in k, so the first one past monthEnd ends the
        // walk; there is nothing behind it that could still land in the month.
        $results = [];
        $iterations = 0;

        while ($iterations < self::MAX_PLACEMENT_ITERATIONS) {
            $iterations++;
            $occurrence = $cadence->occurrenceAt($anchor, $k);
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
            if ($occurrence->lt($anchor) && $occurrence->gte($today)) {
                continue;
            }
            $results[] = $occurrence;
        }

        return $results;
    }

    // Estimates the first index that could land in the month, then backs off one
    // step as a margin. Irregular never gets here; placeSeriesInMonth diverts it.
    private function firstOccurrenceIndex(CarbonImmutable $anchor, SeriesCadence $cadence, CarbonImmutable $monthStart): int
    {
        $monthsDelta = ($monthStart->year - $anchor->year) * self::MONTHS_PER_YEAR + ($monthStart->month - $anchor->month);
        $deltaDays = (int) floor(($monthStart->getTimestamp() - $anchor->getTimestamp()) / Duration::Day->seconds());

        return match ($cadence) {
            SeriesCadence::Weekly => (int) floor($deltaDays / self::DAYS_PER_WEEK) - 1,
            SeriesCadence::Monthly => $monthsDelta - 1,
            SeriesCadence::Quarterly => (int) floor($monthsDelta / self::MONTHS_PER_QUARTER) - 1,
            SeriesCadence::Yearly => ($monthStart->year - $anchor->year) - 1,
            SeriesCadence::Irregular => 0,
        };
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
                ->subDays(MatchWindow::DAYS);
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
