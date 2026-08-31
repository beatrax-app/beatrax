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
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
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
        private BaseCurrency $baseCurrency,
        private CrossCurrencyTotal $fx,
    ) {}

    /**
     * @return list<DriftAlertDto> open alerts, newest detected_at first
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

    // Bucketed by the currency each alert carries rather than summed across
    // them: drift_alerts.annualized_impact_minor is denominated in the series'
    // own currency, and one total over both is euro cents added to dollar cents.
    /**
     * @return array<string, int> per-currency magnitude of the open expense rises,
     *                            in original-currency minor units. A signed SUM let a price drop
     *                            cancel an unrelated rise and render EUR 0.00 with real drift open, so
     *                            only the adverse rows are counted and the total is a magnitude.
     *                            drift-detection.md says why expense-only
     */
    public function openAnnualizedImpactByCurrencyForUser(User $user): array
    {
        $rows = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where('direction', Direction::Expense->value)
            ->where('annualized_impact_minor', '<', 0)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->groupBy('currency')
            ->selectRaw('currency, COALESCE(SUM(-annualized_impact_minor), 0) as impact_minor')
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

    // The one place the module answers "which series is the reader still being
    // shown an alert for". Two callers each re-derived it as state='open' and
    // dropped every snoozed-but-expired row for the hour before the sweep runs.
    /**
     * @return array<int, true>
     */
    public function openSeriesIdsForUser(User $user): array
    {
        $rows = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->distinct()
            ->pluck('recurring_series_id');

        $ids = [];
        foreach ($rows as $value) {
            $ids[self::toInt($value)] = true;
        }

        return $ids;
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
        return $this->recurringQuery->driftThresholdsForSeriesIds($seriesIds, $user);
    }

    /**
     * @return array<int, list<DriftAlertDto>> open alerts grouped by recurring_series_id;
     *                                         series order follows each group's newest alert, and within a group
     *                                         detected_at DESC.
     *                                         Bounded to $seriesLimit series — the whole open set was hydrated on every
     *                                         render of a page that shows one screenful
     */
    public function groupedBySeriesForUser(User $user, int $seriesLimit = 26): array
    {
        $seriesIds = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->groupBy('recurring_series_id')
            ->orderByRaw('MAX(detected_at) DESC')
            ->limit($seriesLimit)
            ->pluck('recurring_series_id')
            ->all();

        if ($seriesIds === []) {
            return [];
        }

        $rows = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->whereIn('recurring_series_id', $seriesIds)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $displayNames = $this->loadSeriesDisplayNames($user, $rows->pluck('recurring_series_id')->all());
        [$baseCurrency, $rates] = $this->ratesFor($user, $rows);

        /** @var array<int, list<DriftAlertDto>> $groups */
        $groups = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $seriesId = self::toInt($row->recurring_series_id);
            $dto = DriftAlertDtoMapper::hydrate(
                $row,
                $displayNames[$seriesId] ?? '',
                $this->annualizedInBase($row, $baseCurrency, $rates),
            );
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

        $this->applyCursor($query, $user, $cursorId);

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
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->limit($limit);

        $this->applyCursor($query, $user, $cursorId);

        return $this->materialise($user, $query->get());
    }

    // The id is derived from the alert's own columns, so it sorts in hash
    // order, not insertion order — paging on `id < cursor` alone would skip and
    // repeat rows at random. detected_at leads; id only breaks ties.

    // Scoped to the reader: unscoped, another household member's row would
    // decide which of this reader's rows come back.
    private function applyCursor(Builder $query, User $user, ?int $cursorId): void
    {
        if ($cursorId === null) {
            return;
        }

        $cursorRow = $this->db->connection()->table('drift_alerts')
            ->where('id', $cursorId)
            ->where('user_id', $user->id)
            ->first(['detected_at']);
        if ($cursorRow === null) {
            return;
        }

        $cursorDetectedAt = self::toString($cursorRow->detected_at);
        $query->where(function (Builder $page) use ($cursorDetectedAt, $cursorId): void {
            $page->where('detected_at', '<', $cursorDetectedAt)
                ->orWhere(function (Builder $tie) use ($cursorDetectedAt, $cursorId): void {
                    $tie->where('detected_at', $cursorDetectedAt)->where('id', '<', $cursorId);
                });
        });
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
        [$baseCurrency, $rates] = $this->ratesFor($user, $rows);

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $seriesId = self::toInt($row->recurring_series_id);
            $result[] = DriftAlertDtoMapper::hydrate(
                $row,
                $displayNames[$seriesId] ?? '',
                $this->annualizedInBase($row, $baseCurrency, $rates),
            );
        }

        return $result;
    }

    // Batched: ratesTo() reads the whole exchange_rates table per currency, so
    // a page asks for each pair once rather than once per row.
    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array{0: string, 1: array<string, string>}
     */
    private function ratesFor(User $user, Collection $rows): array
    {
        $baseCurrency = $this->baseCurrency->forUser($user);
        $currencies = [];
        foreach ($rows as $row) {
            $currencies[] = self::toString($row->currency);
        }

        return [$baseCurrency, $this->fx->ratesTo($currencies, $baseCurrency)];
    }

    // The row's yearly figure in the reader's own currency. Null for a pair the
    // rate table cannot reach, so the shadow line is withheld rather than
    // printing a foreign amount under the reader's sign.
    /**
     * @param  array<string, string>  $rates
     */
    private function annualizedInBase(stdClass $row, string $baseCurrency, array $rates): ?Money
    {
        $money = Money::tryOfMinor(self::toInt($row->annualized_impact_minor), self::toString($row->currency));

        return $money === null ? null : $this->fx->convert($money, $baseCurrency, $rates);
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
