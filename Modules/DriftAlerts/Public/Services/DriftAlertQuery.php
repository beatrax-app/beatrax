<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Internal\Mapping\DriftAlertDtoMapper;
use Modules\DriftAlerts\Public\Dto\DriftAlertDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use stdClass;

/**
 * Public read API over `drift_alerts`. Every method scopes by
 * `user_id` and returns Spatie-Data DTOs so the drift page, the
 * dashboard tile, and downstream module listeners read a single
 * canonical shape.
 *
 * Cross-user reads return an empty list (or zero on count aggregates);
 * cross-user 404s are surfaced at the Public Action layer.
 *
 * Cursor pagination is keyed strictly on `id DESC`. The single-column
 * cursor stays monotone: `drift_alerts.id` is a SQLite autoincrementing
 * surrogate, so newer alerts always have larger ids. Ordering and
 * filtering by id alone keeps the cursor consistent when multiple alerts
 * share an exact `detected_at` second (the revival sweep and the
 * detector listener can each batch several writes inside a single
 * scheduler tick).
 *
 * `totalOpenAnnualizedImpactForUser` returns a SUM aggregate in
 * original-currency-minor units. When alerts span multiple currencies
 * the headline arithmetic is the responsibility of the renderer; the
 * tile presents the absolute magnitude in EUR. The SUM is in the
 * original-currency minor units — an EUR FX join is out of scope for
 * this query.
 *
 * `groupedBySeriesForUser` returns a map from `recurring_series_id` to
 * the list of open alerts in that series — used by the /drift Open
 * tab's grouped-by-series collapsible header.
 *
 * Cross-module reads of `recurring_series` (display name, state)
 * are delegated to `RecurringSeriesQuery` so the DriftAlerts module
 * never issues a raw SELECT against another module's table.
 */
final readonly class DriftAlertQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private RecurringSeriesQuery $recurringQuery,
    ) {}

    /**
     * Open alerts. The state filter is widened to include rows in
     * `state='snoozed'` whose `snoozed_until` has elapsed: the hourly
     * `RevivedExpiredDriftSnoozesJob` flips those rows back to
     * `state='open'` and writes an audit transition, but between
     * sweeps the count + the listing must already reflect the user-
     * visible "this is open again" reality. The two paths produce the
     * same set; the sweep is the durable write, the query is the
     * fresh read.
     *
     * Sort: `id DESC`. Cursor pagination filters strictly on `id`
     * so paging stays consistent even when multiple alerts share an
     * exact `detected_at` second.
     *
     * @return list<DriftAlertDto>
     */
    public function openForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scopedOpen($user, $cursorId, $limit);
    }

    /**
     * Acknowledged alerts (state='acknowledged').
     *
     * @return list<DriftAlertDto>
     */
    public function historyForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['acknowledged'], $cursorId, $limit);
    }

    /**
     * Dismissed alerts (state='dismissed_cancelled').
     *
     * @return list<DriftAlertDto>
     */
    public function dismissedForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['dismissed_cancelled'], $cursorId, $limit);
    }

    /**
     * Count of open alerts for the user. Used by the top-nav badge
     * composer and the dashboard "Drift alerts" tile.
     */
    public function openCountForUser(User $user): int
    {
        return $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->count();
    }

    /**
     * SUM of `annualized_impact_minor` across open EXPENSE-direction
     * alerts in original-currency-minor units. The dashboard tile
     * renders the absolute magnitude in EUR as its "potential
     * annualized cost" headline, so the rollup must stay scoped to
     * expenses — folding an income raise's positive delta into the
     * same headline would conflate "subscriptions going up" with
     * "salary going up" under the single up-arrow tile chrome.
     */
    public function totalOpenAnnualizedImpactForUser(User $user): int
    {
        return (int) $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where('direction', 'expense')
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->sum('annualized_impact_minor');
    }

    /**
     * Resolve the underlying recurring_series.state for each id in the
     * supplied list, scoped to the user. Used by the /drift renderer
     * to surface the "Cadence flipped — also showing in /recurring/review"
     * meta line on rows whose underlying series is in cadence_changed.
     *
     * Delegates to RecurringSeriesQuery so the read flows through the
     * Recurring module's Public service surface instead of a raw
     * cross-module SELECT.
     *
     * @param  list<int>  $seriesIds
     * @return array<int, string>
     */
    public function seriesStatesForUser(User $user, array $seriesIds): array
    {
        return $this->recurringQuery->statesForSeriesIds($seriesIds, $user);
    }

    /**
     * Open alerts grouped by `recurring_series_id`. Series order
     * follows the newest alert in each group (descending). Within a
     * group, alerts sort by `id DESC` — `drift_alerts.id` is a
     * monotonically-increasing surrogate so newer alerts always have
     * larger ids, even when several rows share an exact `detected_at`
     * second.
     *
     * @return array<int, list<DriftAlertDto>>
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
     * Open-tab projection. Applies the "state='open' OR (state='snoozed'
     * AND snoozed_until <= now())" compound filter so snoozed-but-
     * expired rows surface immediately, before the next hourly revival
     * sweep writes the audit transition.
     *
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

    /**
     * Apply the open-tab state filter onto a query builder: rows in
     * `state='open'`, plus rows in `state='snoozed'` whose
     * `snoozed_until` has elapsed. The conditional clause sits inside a
     * single `where(function (...) { ... })` group so chaining with
     * other predicates (user_id scope, cursor pagination) reads as
     * intended.
     */
    private function applyOpenStateFilter(Builder $query): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $query->where('state', 'open')
            ->orWhere(function (Builder $q) use ($now): void {
                $q->where('state', 'snoozed')
                    ->whereNotNull('snoozed_until')
                    ->where('snoozed_until', '<=', $now);
            });
    }

    /**
     * Delegates to RecurringSeriesQuery so every cross-module read of
     * the recurring_series table flows through Recurring's Public
     * service surface.
     *
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, string>
     */
    private function loadSeriesDisplayNames(User $user, array $seriesIds): array
    {
        return $this->recurringQuery->displayNamesForSeriesIds($seriesIds, $user);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
