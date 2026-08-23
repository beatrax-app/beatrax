<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Internal\Queries\RecurringSeriesProjector;
use Modules\Recurring\Internal\Queries\SeriesAccountResolver;
use Modules\Recurring\Internal\Support\SeriesIds;
use Modules\Recurring\Public\Dto\RecurringOccurrenceDto;
use Modules\Recurring\Public\Dto\RecurringSeriesAmountTrendDto;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use stdClass;

final readonly class RecurringSeriesQuery
{
    use CoercesScalars;

    private const string OCCURRENCES = 'recurring_series_occurrences as o';

    private const string TRANSACTIONS = 'transactions as t';

    private const string SERIES = 'recurring_series as s';

    /** @var list<string> the two states allApprovedForUser walks, and so the only two a booked row can be reconciled against */
    private const array PROJECTABLE_STATES = [RecurringSeriesState::Approved->value, RecurringSeriesState::CadenceChanged->value];

    public function __construct(
        private DatabaseManager $db,
        private SeriesAccountResolver $accounts,
        private RecurringSeriesProjector $projector,
        private BaseCurrency $baseCurrency,
    ) {}

    /**
     * @return list<RecurringSeriesDto> strictly `pending`; `cadence_changed` has its own
     *                                  tab, so widening this would double-count across both
     */
    public function pendingForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->projector->scoped($user, [RecurringSeriesState::Pending->value], $cursorId, $limit, 'id');
    }

    // The same two states allApprovedForUser walks, asked as a bare existence
    // question: a surface that only needs to know whether the reader has
    // anything to project must not pay to hydrate every series to find out.
    public function hasApprovedForUser(User $user): bool
    {
        return $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('state', self::PROJECTABLE_STATES)
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
        return $this->projector->scoped($user, [RecurringSeriesState::Rejected->value], $cursorId, $limit, 'id');
    }

    /**
     * @return list<RecurringSeriesDto>
     */
    public function approvedForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->projector->scoped($user, [RecurringSeriesState::Approved->value], $cursorId, $limit, 'monthly_equivalent_minor');
    }

    /**
     * @return list<RecurringSeriesDto>
     */
    public function cadenceChangedForUser(User $user): array
    {
        return $this->projector->scoped($user, [RecurringSeriesState::CadenceChanged->value], null, 100, 'id');
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
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $map[self::toInt($row->id)] = $this->projector->toDto($row);
        }

        return $map;
    }

    /**
     * @return list<RecurringOccurrenceDto> every observation row that contributed to the
     *                                      cluster, ordered by observed_at DESC. Cross-user lookups return an empty list
     */
    public function occurrencesForSeries(int $seriesId, User $user): array
    {
        $owns = $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->exists();
        if (! $owns) {
            return [];
        }

        $rows = $this->db->connection()->table('recurring_series_occurrences')
            ->where('recurring_series_id', $seriesId)
            ->where('user_id', $user->id)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $observedCurrency = self::toString($row->observed_currency);
            $observedAmount = Money::ofMinor(self::toInt($row->observed_amount_minor), $observedCurrency);

            $result[] = new RecurringOccurrenceDto(
                occurrenceId: self::toInt($row->id),
                recurringSeriesId: self::toInt($row->recurring_series_id),
                transactionId: self::toInt($row->transaction_id),
                observedAt: CarbonImmutable::parse(self::toString($row->observed_at)),
                observedAmount: $observedAmount,
                observedCurrency: $observedCurrency,
            );
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $transactionIds
     * @return array<int, bool> transaction_id => is a member of some recurring series.
     *                          Anomaly's duplicate-charge detector uses it to spare a fortnightly/weekly
     *                          subscription that legitimately lands twice inside the duplicate window.
     *                          user_id is filtered explicitly: the global scope does not fire on the
     *                          queue/console where the evaluator runs
     */
    public function seriesMembershipForTransactionIds(array $transactionIds, User $user): array
    {
        $clean = [];
        foreach ($transactionIds as $id) {
            $i = is_numeric($id) ? (int) $id : 0;
            if ($i > 0) {
                $clean[] = $i;
            }
        }
        $unique = array_values(array_unique($clean));
        if ($unique === []) {
            return [];
        }

        $members = $this->db->connection()->table('recurring_series_occurrences')
            ->where('user_id', $user->id)
            ->whereIn('transaction_id', $unique)
            ->distinct()
            ->pluck('transaction_id');

        $memberSet = [];
        foreach ($members as $value) {
            $memberSet[self::toInt($value)] = true;
        }

        $map = [];
        foreach ($unique as $id) {
            $map[$id] = isset($memberSet[$id]);
        }

        return $map;
    }

    /**
     * @param  array<int|string, mixed>  $transactionIds
     * @return array<int, int> transaction_id => the projectable series the row belongs to.
     *                         Rows belonging to none are silently absent
     *
     * @link ../../../../.docs/features/forecasting/architecture.md#booked-future-dated-rows
     */
    public function seriesIdsForTransactionIds(array $transactionIds, User $user): array
    {
        $unique = SeriesIds::normalise($transactionIds);
        if ($unique === []) {
            return [];
        }

        $linked = $this->linkedSeriesIds($unique, $user);

        // A detection sweep has not read a row imported since it last ran, so
        // the occurrence link is missing for exactly the future-dated rows a
        // projection has to reconcile against its own estimate. The cluster
        // identity the detector groups on is already on the row.
        $unlinked = array_values(array_filter($unique, static fn (int $id): bool => ! isset($linked[$id])));

        return $unlinked === [] ? $linked : $linked + $this->clusteredSeriesIds($unlinked, $user);
    }

    /**
     * @param  list<int>  $transactionIds
     * @return array<int, int>
     */
    private function linkedSeriesIds(array $transactionIds, User $user): array
    {
        $rows = $this->db->connection()->table(self::OCCURRENCES)
            ->join(self::SERIES, 's.id', '=', 'o.recurring_series_id')
            ->where('o.user_id', $user->id)
            ->whereIn('o.transaction_id', $transactionIds)
            ->whereIn('s.state', self::PROJECTABLE_STATES)
            ->get(['o.transaction_id as transaction_id', 'o.recurring_series_id as series_id']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $map[self::toInt($row->transaction_id)] = self::toInt($row->series_id);
        }

        return $map;
    }

    /**
     * @param  list<int>  $transactionIds
     * @return array<int, int>
     */
    private function clusteredSeriesIds(array $transactionIds, User $user): array
    {
        // UNIQUE(user_id, direction, cluster_counterparty_key, latest_currency)
        // makes the joined triple plus a direction at most one series, so no
        // tie-break is needed once the direction filter below has run.
        $rows = $this->db->connection()->table(self::TRANSACTIONS)
            ->join(self::SERIES, function (JoinClause $join): void {
                $join->on('s.user_id', '=', 't.user_id')
                    ->on('s.cluster_counterparty_key', '=', 't.counterparty_normalized')
                    ->on('s.latest_currency', '=', 't.currency');
            })
            ->where('t.user_id', $user->id)
            ->whereIn('t.id', $transactionIds)
            ->where('t.counterparty_normalized', '!=', CounterpartyKey::NONE)
            ->whereIn('s.state', self::PROJECTABLE_STATES)
            ->get(['t.id as transaction_id', 't.type as type', 's.id as series_id', 's.direction as direction']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            if (TransactionType::directionOf($row->type) !== Direction::tryFrom(self::toString($row->direction))) {
                continue;
            }
            $map[self::toInt($row->transaction_id)] = self::toInt($row->series_id);
        }

        return $map;
    }

    /**
     * @return int|null the most frequent transactions.counterparty_id across the series'
     *                  occurrences, or null when none resolved to a counterparty
     */
    public function counterpartyIdForSeries(int $seriesId, User $user): ?int
    {
        $row = $this->db->connection()->table(self::OCCURRENCES)
            ->join(self::TRANSACTIONS, 't.id', '=', 'o.transaction_id')
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

        $rows = $this->db->connection()->table(self::OCCURRENCES)
            ->join(self::TRANSACTIONS, 't.id', '=', 'o.transaction_id')
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
        $ids = $this->db->connection()->table(self::SERIES)
            ->join(self::OCCURRENCES, 'o.recurring_series_id', '=', 's.id')
            ->join(self::TRANSACTIONS, 't.id', '=', 'o.transaction_id')
            ->where('s.user_id', $user->id)
            ->where('t.counterparty_id', $counterpartyId)
            ->whereIn('s.state', self::PROJECTABLE_STATES)
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
     * @return RecurringSeriesAmountTrendDto up to $maxPoints points, oldest first. Each
     *                                       carries the native amount plus the settled-EUR shadow; eur_amount_minor
     *                                       is null when the observation is already in EUR
     */
    public function amountTrendForSeries(int $seriesId, User $user, int $maxPoints = 24): RecurringSeriesAmountTrendDto
    {
        $seriesRow = $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->first();
        if ($seriesRow === null) {
            return new RecurringSeriesAmountTrendDto(
                seriesId: $seriesId,
                currency: $this->baseCurrency->code(),
                points: [],
                maxPoints: $maxPoints,
            );
        }

        /** @var stdClass $seriesRow */
        $currency = self::toString($seriesRow->latest_currency);
        if ($currency === '') {
            // Schema guarantees latest_currency is non-null + 3 chars, so an
            // empty value means a corrupt row. Fabricating an EUR label here
            // would mislabel the chart axis, so return nothing instead.
            return new RecurringSeriesAmountTrendDto(
                seriesId: $seriesId,
                currency: '',
                points: [],
                maxPoints: $maxPoints,
            );
        }

        $effectiveLimit = max(1, $maxPoints);
        $rows = $this->db->connection()->table('recurring_series_occurrences as rso')
            ->leftJoin(self::TRANSACTIONS, 't.id', '=', 'rso.transaction_id')
            ->where('rso.recurring_series_id', $seriesId)
            ->where('rso.user_id', $user->id)
            ->orderByDesc('rso.observed_at')
            ->orderByDesc('rso.id')
            ->limit($effectiveLimit)
            ->get([
                'rso.observed_at',
                'rso.observed_amount_minor',
                'rso.observed_currency',
                't.settled_amount_minor as settled_amount_minor',
                't.settled_currency as settled_currency',
            ]);

        // DESC + LIMIT is how the newest N are fetched; reverse so the chart's
        // time axis runs left-to-right.
        $ordered = $rows->reverse()->values();

        $points = [];
        foreach ($ordered as $row) {
            /** @var stdClass $row */
            $observedAt = CarbonImmutable::parse(self::toString($row->observed_at))->toDateString();
            $amountMinor = self::toInt($row->observed_amount_minor);
            $observedCurrency = self::toString($row->observed_currency);
            $eurAmountMinor = null;
            if ($observedCurrency !== Currency::Eur->value) {
                $settledCurrency = self::toString($row->settled_currency ?? null);
                if ($settledCurrency === Currency::Eur->value) {
                    $eurAmountMinor = self::toInt($row->settled_amount_minor ?? null);
                }
            }
            $points[] = [
                'date' => $observedAt,
                'amount_minor' => $amountMinor,
                'eur_amount_minor' => $eurAmountMinor,
            ];
        }

        return new RecurringSeriesAmountTrendDto(
            seriesId: $seriesId,
            currency: $currency,
            points: $points,
            maxPoints: $maxPoints,
        );
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
            ->whereIn('state', self::PROJECTABLE_STATES)
            ->orderByDesc('monthly_equivalent_minor')
            ->orderByDesc('id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $result[] = $this->projector->toDto($row);
        }

        return $result;
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
