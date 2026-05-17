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
 * Cursor pagination is keyed on `id DESC` only — `detected_at` is
 * essentially monotonic for a given user so the composite-cursor
 * approach used in RecurringSeriesQuery's `approvedForUser` is not
 * needed here.
 *
 * `totalOpenAnnualizedImpactForUser` returns a SUM aggregate in
 * original-currency-minor units. When alerts span multiple currencies
 * the headline arithmetic is the responsibility of the renderer (the
 * UI rolls the value up to EUR using each row's stored fx rate; in
 * Wave 3 the EUR shadow is left to a follow-up plan that joins the
 * occurrence row's `fx_rate_used`).
 *
 * `groupedBySeriesForUser` returns a map from `recurring_series_id` to
 * the list of open alerts in that series — used by the /drift Open
 * tab's grouped-by-series collapsible header.
 */
final readonly class DriftAlertQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
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
     * Sort: `detected_at DESC, id DESC`.
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
     * SUM of `annualized_impact_minor` across open alerts in
     * original-currency-minor units. The dashboard tile renders the
     * EUR-rolled-up headline; UI-SPEC § 5 documents the rollup.
     */
    public function totalOpenAnnualizedImpactForUser(User $user): int
    {
        return (int) $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->sum('annualized_impact_minor');
    }

    /**
     * Resolve the underlying recurring_series.state for each id in the
     * supplied list, scoped to the user. Used by the /drift renderer
     * to surface the "Cadence flipped — also showing in /recurring/review"
     * meta line on rows whose underlying series is in cadence_changed.
     *
     * READ-only against recurring_series; the
     * `noRecurringSeriesWritesFromDriftAlerts` invariant covers only
     * update/insert/delete verbs.
     *
     * @param  list<int>  $seriesIds
     * @return array<int, string>
     */
    public function seriesStatesForUser(User $user, array $seriesIds): array
    {
        $clean = [];
        foreach ($seriesIds as $id) {
            if ($id > 0) {
                $clean[] = $id;
            }
        }
        $unique = array_values(array_unique($clean));
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
     * Open alerts grouped by `recurring_series_id`. Series order
     * follows the newest alert in each group (descending). Within a
     * group, alerts sort by `detected_at DESC, id DESC`.
     *
     * @return array<int, list<DriftAlertDto>>
     */
    public function groupedBySeriesForUser(User $user): array
    {
        $rows = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->orderByDesc('detected_at')
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
            ->orderByDesc('detected_at')
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
            ->orderByDesc('detected_at')
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
     * Batch-resolve display names for the supplied series ids in one
     * SELECT against recurring_series, scoped to the same user. This
     * is a READ from another module's table — permitted by the
     * `noRecurringSeriesWritesFromDriftAlerts` invariant (the rule
     * fires only on update / insert / delete verbs).
     *
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, string>
     */
    private function loadSeriesDisplayNames(User $user, array $seriesIds): array
    {
        $clean = [];
        foreach ($seriesIds as $id) {
            $i = is_numeric($id) ? (int) $id : 0;
            if ($i > 0) {
                $clean[] = $i;
            }
        }
        $unique = array_values(array_unique($clean));
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
            $override = self::nullableString($row->display_name_override ?? null);
            $detected = self::toString($row->detected_name);
            $map[$id] = $override !== null && $override !== '' ? $override : $detected;
        }

        return $map;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
