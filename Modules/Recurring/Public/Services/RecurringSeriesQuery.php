<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Recurring\Internal\Queries\RecurringSeriesProjector;
use Modules\Recurring\Internal\Queries\SeriesAccountResolver;
use Modules\Recurring\Internal\Queries\SeriesPageSort;
use Modules\Recurring\Internal\Support\SeriesIds;
use Modules\Recurring\Internal\Support\SeriesTables;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use stdClass;

final readonly class RecurringSeriesQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private SeriesAccountResolver $accounts,
        private RecurringSeriesProjector $projector,
    ) {}

    /**
     * @return list<RecurringSeriesDto> strictly `pending`; `cadence_changed` has its own
     *                                  tab, so widening this would double-count across both
     */
    public function pendingForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->projector->scoped($user, [RecurringSeriesState::Pending->value], $cursorId, $limit, SeriesPageSort::NewestFirst);
    }

    // The same two states allApprovedForUser walks, asked as a bare existence
    // question: a surface that only needs to know whether the reader has
    // anything to project must not pay to hydrate every series to find out.
    public function hasApprovedForUser(User $user): bool
    {
        return $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('state', RecurringSeriesState::projectableValues())
            ->exists();
    }

    public function pendingCountForUser(User $user): int
    {
        return $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->where('state', RecurringSeriesState::Pending->value)
            ->count();
    }

    /**
     * @return list<RecurringSeriesDto>
     */
    public function rejectedForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->projector->scoped($user, [RecurringSeriesState::Rejected->value], $cursorId, $limit, SeriesPageSort::NewestFirst);
    }

    /**
     * @return list<RecurringSeriesDto> strictly `approved`, biggest monthly
     *                                  equivalent first by magnitude — a plain DESC on the signed column
     *                                  reads as smallest-expense-first
     */
    public function approvedForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->projector->scoped($user, [RecurringSeriesState::Approved->value], $cursorId, $limit, SeriesPageSort::LargestMonthlyEquivalentFirst);
    }

    /**
     * @return list<RecurringSeriesDto>
     */
    public function cadenceChangedForUser(User $user, int $limit = 100): array
    {
        return $this->projector->scoped($user, [RecurringSeriesState::CadenceChanged->value], null, $limit, SeriesPageSort::NewestFirst);
    }

    public function forSeries(int $seriesId, User $user): ?RecurringSeriesDto
    {
        $row = $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var stdClass $row */
        return $this->projector->toDto($row);
    }

    /**
     * @return int|null the drift_threshold_percent override, null when the series is
     *                  missing, cross-user, or has no override set
     */
    public function driftThresholdForSeries(int $seriesId, User $user): ?int
    {
        $row = $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->first(['drift_threshold_percent']);
        if ($row === null) {
            return null;
        }
        $value = $row->drift_threshold_percent ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, string> recurring_series.state per id; missing or cross-user ids
     *                            are silently absent
     */
    public function statesForSeriesIds(array $seriesIds, User $user): array
    {
        $unique = SeriesIds::normalise($seriesIds);
        if ($unique === []) {
            return [];
        }

        $rows = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('id', $unique)
            ->get(['id', 'state']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $map[self::toInt($row->id)] = self::toString($row->state);
        }

        return $map;
    }

    /**
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, string> display_name_override when set, else detected_name;
     *                            missing or cross-user ids are silently absent
     */
    public function displayNamesForSeriesIds(array $seriesIds, User $user): array
    {
        $unique = SeriesIds::normalise($seriesIds);
        if ($unique === []) {
            return [];
        }

        $rows = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('id', $unique)
            ->get(['id', 'display_name_override', 'detected_name']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $id = self::toInt($row->id);
            $override = $row->display_name_override ?? null;
            $override = is_string($override) ? $override : null;
            $detected = self::toString($row->detected_name);
            $map[$id] = $override !== null && $override !== '' ? $override : $detected;
        }

        return $map;
    }

    /**
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, RecurringSeriesDto> batched forSeries() — one SELECT instead of N
     *                                        per page render; missing or cross-user ids are silently absent
     */
    public function forSeriesIds(array $seriesIds, User $user): array
    {
        $unique = SeriesIds::normalise($seriesIds);
        if ($unique === []) {
            return [];
        }

        $rows = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('id', $unique)
            ->get();

        $map = [];
        foreach ($this->projector->toDtos($rows) as $dto) {
            $map[$dto->seriesId] = $dto;
        }

        return $map;
    }

    /**
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, int|null> batched driftThresholdForSeries() — one SELECT instead of
     *                              N; missing or cross-user ids are silently absent, a present id with no
     *                              override maps to null
     */
    public function driftThresholdsForSeriesIds(array $seriesIds, User $user): array
    {
        $unique = SeriesIds::normalise($seriesIds);
        if ($unique === []) {
            return [];
        }

        $rows = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('id', $unique)
            ->get(['id', 'drift_threshold_percent']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $value = $row->drift_threshold_percent ?? null;
            $map[self::toInt($row->id)] = is_numeric($value) ? (int) $value : null;
        }

        return $map;
    }

    /**
     * @return int|null the most frequent transactions.counterparty_id across the series'
     *                  occurrences, or null when none resolved to a counterparty
     */
    public function counterpartyIdForSeries(int $seriesId, User $user): ?int
    {
        $row = $this->db->connection()->table(SeriesTables::OCCURRENCES)
            ->join(SeriesTables::TRANSACTIONS, 't.id', '=', 'o.transaction_id')
            ->where('o.recurring_series_id', $seriesId)
            ->where('o.user_id', $user->id)
            ->whereNotNull('t.counterparty_id')
            ->groupBy('t.counterparty_id')
            ->orderByRaw('COUNT(*) DESC')
            ->select('t.counterparty_id')
            ->first();

        if ($row === null) {
            return null;
        }

        $id = self::toInt($row->counterparty_id);

        return $id > 0 ? $id : null;
    }

    /**
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, int> batched counterpartyIdForSeries() — one grouped query instead
     *                         of N; series that never resolved to a counterparty are silently absent
     */
    public function counterpartyIdsForSeriesIds(array $seriesIds, User $user): array
    {
        $unique = SeriesIds::normalise($seriesIds);
        if ($unique === []) {
            return [];
        }

        $rows = $this->db->connection()->table(SeriesTables::OCCURRENCES)
            ->join(SeriesTables::TRANSACTIONS, 't.id', '=', 'o.transaction_id')
            ->whereIn('o.recurring_series_id', $unique)
            ->where('o.user_id', $user->id)
            ->whereNotNull('t.counterparty_id')
            ->groupBy('o.recurring_series_id', 't.counterparty_id')
            ->orderByRaw('COUNT(*) DESC')
            ->selectRaw('o.recurring_series_id as series_id, t.counterparty_id as counterparty_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $seriesId = self::toInt($row->series_id);
            if ($seriesId === 0 || isset($map[$seriesId])) {
                // First row per series wins: ORDER BY COUNT(*) DESC puts the
                // most frequent counterparty first.
                continue;
            }
            $counterpartyId = self::toInt($row->counterparty_id);
            if ($counterpartyId > 0) {
                $map[$seriesId] = $counterpartyId;
            }
        }

        return $map;
    }

    /**
     * @return array<int, RecurringSeriesDto> approved / cadence-changed series whose
     *                                        occurrences belong to the given counterparty
     */
    public function approvedSeriesForCounterparty(int $counterpartyId, User $user): array
    {
        $ids = $this->db->connection()->table(SeriesTables::SERIES)
            ->join(SeriesTables::OCCURRENCES, 'o.recurring_series_id', '=', 's.id')
            ->join(SeriesTables::TRANSACTIONS, 't.id', '=', 'o.transaction_id')
            ->where('s.user_id', $user->id)
            ->where('t.counterparty_id', $counterpartyId)
            ->whereIn('s.state', RecurringSeriesState::projectableValues())
            ->distinct()
            ->pluck('s.id')
            ->map(static fn (mixed $v): int => self::toInt($v))
            ->all();

        if ($ids === []) {
            return [];
        }

        return $this->forSeriesIds($ids, $user);
    }

    /**
     * @return list<RecurringSeriesDto> every approved row, unpaged, for Forecasting's
     *                                  projection walk. cadence_changed is included: the pattern is still
     *                                  approved (only the reclassification awaits confirmation), and excluding
     *                                  it would silently drop series from the forecast band
     */
    public function allApprovedForUser(User $user): array
    {
        $rows = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('state', RecurringSeriesState::projectableValues())
            ->orderByDesc('monthly_equivalent_minor')
            ->orderByDesc('id')
            ->get();

        return $this->projector->toDtos($rows);
    }

    /**
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, int> originating account_id per id. recurring_series carries no
     *                         account column, so this walks the newest occurrence to its transaction;
     *                         a series with no occurrences yet falls back to the user's
     *                         alphabetically-first account
     */
    public function accountIdsForSeriesIds(array $seriesIds, User $user): array
    {
        $unique = SeriesIds::normalise($seriesIds);
        if ($unique === []) {
            return [];
        }

        return $this->accounts->forSeriesIds($unique, $user);
    }
}
