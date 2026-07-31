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
 * @link ../../../../.docs/features/drift-alerts/architecture.md
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
     * @return list<DriftAlertDto> open alerts, sorted id DESC (see the class @link's
     *                             open-tab compound filter note)
     */
    public function openForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scopedOpen($user, $cursorId, $limit);
    }

    /**
     * @return list<DriftAlertDto> acknowledged alerts (state='acknowledged')
     */
    public function historyForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, [DriftAlertState::Acknowledged->value], $cursorId, $limit);
    }

    /**
     * @return list<DriftAlertDto> dismissed alerts (state='dismissed_cancelled')
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
     * @return int SUM of annualized_impact_minor across open EXPENSE-direction alerts in
     *             original-currency-minor units (see the class @link for why expense-only)
     */
    public function totalOpenAnnualizedImpactForUser(User $user): int
    {
        return (int) $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where('direction', Direction::Expense->value)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->sum('annualized_impact_minor');
    }

    /**
     * @param  list<int>  $seriesIds
     * @return array<int, string> recurring_series.state per id, scoped to the user. Used
     *                            by the /drift renderer to surface the "Cadence flipped" meta line; delegates to
     *                            RecurringSeriesQuery instead of a raw cross-module SELECT
     */
    public function seriesStatesForUser(User $user, array $seriesIds): array
    {
        return $this->recurringQuery->statesForSeriesIds($seriesIds, $user);
    }

    /**
     * @return array<int, list<DriftAlertDto>> open alerts grouped by recurring_series_id.
     *                                         Series order follows the newest alert in each group (descending); within a group,
     *                                         alerts sort by id DESC
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
     * @return list<DriftAlertDto> open-tab projection via applyOpenStateFilter()
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

    // Rows in state='open', plus state='snoozed' rows whose snoozed_until
    // has elapsed. The clause sits inside one where(function...) group so
    // chaining with other predicates (user_id scope, cursor pagination)
    // reads as intended.
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
     * @return array<int, string> delegates to RecurringSeriesQuery so every cross-module
     *                            read of recurring_series flows through Recurring's Public service surface
     */
    private function loadSeriesDisplayNames(User $user, array $seriesIds): array
    {
        return $this->recurringQuery->displayNamesForSeriesIds($seriesIds, $user);
    }
}
