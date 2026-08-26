<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Internal\Mapping\DriftAlertDtoMapper;
use Modules\DriftAlerts\Public\Dto\DriftAlertDto;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use stdClass;

/**
 * @link ../../../../.docs/features/drift-alerts/drift-detection.md
 * @link ../../../../.docs/features/drift-alerts/snooze-lifecycle.md
 */
final readonly class DriftAlertQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private RecurringSeriesQuery $recurringQuery,
    ) {}

    /**
     * @return list<DriftAlertDto> open alerts, sorted id DESC
     */
    public function openForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scopedOpen($user, $cursorId, $limit);
    }

    /**
     * @return list<DriftAlertDto>
     */
    public function historyForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, [DriftAlertState::Acknowledged->value], $cursorId, $limit);
    }

    /**
     * @return list<DriftAlertDto>
     */
    public function dismissedForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, [DriftAlertState::DismissedCancelled->value], $cursorId, $limit);
    }

    public function openCountForUser(User $user): int
    {
        return $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->count();
    }

    /**
     * @return int summed annualized_impact_minor over open expense alerts, in
     *             original-currency minor units. drift-detection.md says why expense-only
     */
    // Bucketed by the currency each alert carries rather than summed across
    // them: drift_alerts.annualized_impact_minor is denominated in the series'
    // own currency, and one total over both is euro cents added to dollar cents.
    /**
     * @return array<string, int>
     */
    public function openAnnualizedImpactByCurrencyForUser(User $user): array
    {
        $rows = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where('direction', Direction::Expense->value)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->groupBy('currency')
            ->selectRaw('currency, COALESCE(SUM(annualized_impact_minor), 0) as impact_minor')
            ->get();

        $byCurrency = [];
        foreach ($rows as $row) {
            $currency = is_string($row->currency) ? $row->currency : '';
            if ($currency !== '') {
                $byCurrency[$currency] = is_numeric($row->impact_minor) ? (int) $row->impact_minor : 0;
            }
        }

        return $byCurrency;
    }

    /**
     * @param  list<int>  $seriesIds
     * @return array<int, string> recurring_series.state per id, scoped to the user
     */
    public function seriesStatesForUser(User $user, array $seriesIds): array
    {
        return $this->recurringQuery->statesForSeriesIds($seriesIds, $user);
    }

    // One row per series with an open alert mounts a threshold editor, and each
    // editor read its own series back on mount. The grouping query already knows
    // every id it will render, so the whole column arrives with it.
    /**
     * @param  list<int>  $seriesIds
     * @return array<int, int|null> recurring_series.drift_threshold_percent per id, scoped
     *                              to the user; null where the series follows the global default
     */
    public function seriesThresholdsForUser(User $user, array $seriesIds): array
    {
        $unique = array_values(array_unique($seriesIds));
        if ($unique === []) {
            return [];
        }

        $rows = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('id', $unique)
            ->get(['id', 'drift_threshold_percent']);

        $thresholds = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $value = $row->drift_threshold_percent ?? null;
            $thresholds[self::toInt($row->id)] = is_numeric($value) ? (int) $value : null;
        }

        return $thresholds;
    }

    /**
     * @return array<int, list<DriftAlertDto>> open alerts grouped by recurring_series_id;
     *                                         series order follows each group's newest alert, and within a group id DESC
     */
    public function groupedBySeriesForUser(User $user): array
    {
        $rows = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->orderByDesc('id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $displayNames = $this->loadSeriesDisplayNames($user, $rows->pluck('recurring_series_id')->all());

        /** @var array<int, list<DriftAlertDto>> $groups */
        $groups = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $seriesId = self::toInt($row->recurring_series_id);
            $dto = DriftAlertDtoMapper::hydrate($row, $displayNames[$seriesId] ?? '');
            if (! isset($groups[$seriesId])) {
                $groups[$seriesId] = [];
            }
            $groups[$seriesId][] = $dto;
        }

        return $groups;
    }

    /**
     * @param  list<string>  $states
     * @return list<DriftAlertDto>
     */
    private function scoped(User $user, array $states, ?int $cursorId, int $limit): array
    {
        $query = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->whereIn('state', $states)
            ->orderByDesc('id')
            ->limit($limit);

        if ($cursorId !== null) {
            $query->where('id', '<', $cursorId);
        }

        return $this->materialise($user, $query->get());
    }

    /**
     * @return list<DriftAlertDto>
     */
    private function scopedOpen(User $user, ?int $cursorId, int $limit): array
    {
        $query = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->orderByDesc('id')
            ->limit($limit);

        if ($cursorId !== null) {
            $query->where('id', '<', $cursorId);
        }

        return $this->materialise($user, $query->get());
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return list<DriftAlertDto>
     */
    private function materialise(User $user, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $displayNames = $this->loadSeriesDisplayNames($user, $rows->pluck('recurring_series_id')->all());

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $seriesId = self::toInt($row->recurring_series_id);
            $result[] = DriftAlertDtoMapper::hydrate($row, $displayNames[$seriesId] ?? '');
        }

        return $result;
    }

    // The OR stays inside one where(function...) group: chained at the top
    // level it would escape the user_id scope and return other users' rows.
    private function applyOpenStateFilter(Builder $query): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $query->where('state', DriftAlertState::Open->value)
            ->orWhere(function (Builder $q) use ($now): void {
                $q->where('state', DriftAlertState::Snoozed->value)
                    ->whereNotNull('snoozed_until')
                    ->where('snoozed_until', '<=', $now);
            });
    }

    /**
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, string>
     */
    private function loadSeriesDisplayNames(User $user, array $seriesIds): array
    {
        return $this->recurringQuery->displayNamesForSeriesIds($seriesIds, $user);
    }
}
