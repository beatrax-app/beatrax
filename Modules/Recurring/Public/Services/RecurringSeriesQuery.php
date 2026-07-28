<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Internal\Mapping\RecurringSeriesDtoMapper;
use Modules\Recurring\Internal\Queries\SeriesAccountResolver;
use Modules\Recurring\Internal\Support\SeriesIds;
use Modules\Recurring\Public\Dto\RecurringOccurrenceDto;
use Modules\Recurring\Public\Dto\RecurringSeriesAmountTrendDto;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use stdClass;

/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
final readonly class RecurringSeriesQuery
{
    // The aliases every join in this class shares. Naming them keeps the
    // `o.` and `t.` prefixes scattered through the column lists below
    // anchored to one definition rather than to whichever string a given
    // query happened to spell.
    private const string OCCURRENCES = 'recurring_series_occurrences as o';

    private const string TRANSACTIONS = 'transactions as t';

    public function __construct(
        private DatabaseManager $db,
        private SeriesAccountResolver $accounts,
    ) {}

    /**
     * @return list<RecurringSeriesDto> strictly `pending` rows only, never `cadence_changed`
     *                                  (the review page surfaces those on their own dedicated tab via cadenceChangedForUser,
     *                                  so widening this projection would double-count them across both tabs)
     */
    public function pendingForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['pending'], $cursorId, $limit, 'id');
    }

    // Mirrors pendingForUser's strict scope: the cadence-changed tab has its
    // own queue and is intentionally NOT folded into this count.
    public function pendingCountForUser(User $user): int
    {
        return $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->where('state', 'pending')
            ->count();
    }

    /**
     * @return list<RecurringSeriesDto>
     */
    public function rejectedForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['rejected'], $cursorId, $limit, 'id');
    }

    /**
     * @return list<RecurringSeriesDto>
     */
    public function approvedForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['approved'], $cursorId, $limit, 'monthly_equivalent_minor');
    }

    /**
     * @return list<RecurringSeriesDto>
     */
    public function cadenceChangedForUser(User $user): array
    {
        return $this->scoped($user, ['cadence_changed'], null, 100, 'id');
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
        return $this->toDto($row);
    }

    /**
     * @return int|null the drift_threshold_percent override for one (series, user) pair,
     *                  null when the series is missing, cross-user, or has no override set. Callers outside
     *                  Recurring use this instead of a raw SELECT, keeping every cross-module read
     *                  funnelled through this Public service
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
     * @return array<int, string> recurring_series.state per id, for readers that want a
     *                            cross-reference hint without a raw SELECT against another module's table.
     *                            Missing or cross-user ids are silently absent from the result
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
     * @return array<int, string> display_name_override when set, else detected_name.
     *                            Missing or cross-user ids are silently absent from the result
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
     * @return array<int, RecurringSeriesDto> batched variant of forSeries() in a single
     *                                        SELECT — callers needing a per-series projection for a list of ids on one page
     *                                        render use this instead of looping forSeries(), which would issue N queries.
     *                                        Missing or cross-user ids are silently absent from the result
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
            $map[self::toInt($row->id)] = $this->toDto($row);
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
     * @return array<int, bool> transaction_id => bool, true iff a recurring_series_occurrences
     *                          row carries that transaction_id (user-scoped); ids with no occurrence row map to
     *                          false. The Anomaly module's duplicate-charge detector consumes this to exclude a
     *                          near-twin pair where both sides belong to an approved recurring series — a legit
     *                          fortnightly/weekly subscription landing twice in the duplicate window is not a real
     *                          duplicate. Going through this Public surface keeps recurring_series_occurrences owned
     *                          by Recurring; Anomaly never raw-joins it. Cross-user safe: the batched query carries
     *                          an explicit where('user_id', ...) since the global scope does not fire under
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
     * @return int|null the most frequent transactions.counterparty_id across the series'
     *                  occurrences, or null when none resolved to a counterparty. Lets the series detail
     *                  page deep-link to the counterparty profile. Cross-user safe (occurrences are
     *                  scoped to the user)
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
     * @return array<int, int> batched variant of counterpartyIdForSeries() in a single
     *                         grouped query — callers needing the counterparty for a list of series on one page
     *                         render (the calendar's entry map) use this instead of looping, which would issue
     *                         N queries. Series whose occurrences never resolved to a counterparty are silently
     *                         absent from the result. Cross-user safe (occurrences are scoped to the user)
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
                // The highest-COUNT(*) row per series wins thanks to the
                // ORDER BY COUNT(*) DESC ordering — mirrors the single-id
                // variant's most-frequent-counterparty rule.
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
     * @return array<int, RecurringSeriesDto> approved / cadence-changed recurring series
     *                                        whose occurrences belong to the given counterparty — powers the "Recurring" card
     *                                        on a counterparty profile. Cross-user safe (every join is scoped to the user)
     */
    public function approvedSeriesForCounterparty(int $counterpartyId, User $user): array
    {
        $ids = $this->db->connection()->table('recurring_series as s')
            ->join(self::OCCURRENCES, 'o.recurring_series_id', '=', 's.id')
            ->join(self::TRANSACTIONS, 't.id', '=', 'o.transaction_id')
            ->where('s.user_id', $user->id)
            ->where('t.counterparty_id', $counterpartyId)
            ->whereIn('s.state', ['approved', 'cadence_changed'])
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
     * @return RecurringSeriesAmountTrendDto up to $maxPoints data points sorted
     *                                       oldest-to-newest so the ApexCharts datetime axis renders left-to-right. Each point
     *                                       carries the native-currency amount plus the settled-EUR amount; eur_amount_minor is
     *                                       null when the observation is already in EUR (no shadow series needed). Cross-user
     *                                       lookups return an empty payload
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
                currency: 'EUR',
                points: [],
                maxPoints: $maxPoints,
            );
        }

        /** @var stdClass $seriesRow */
        $currency = self::toString($seriesRow->latest_currency);
        if ($currency === '') {
            // Schema guarantees latest_currency is non-null + 3 chars; an
            // empty value here means the row is corrupt or the detector
            // wrote a malformed insert. Return an empty payload rather than
            // fabricating an EUR label that would mislead the chart axis.
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

        // The DB query orders DESC + limits to N to fetch the "newest N"
        // observations efficiently. Reverse to ASC here so the chart
        // renders the time axis left-to-right.
        $ordered = $rows->reverse()->values();

        $points = [];
        foreach ($ordered as $row) {
            /** @var stdClass $row */
            $observedAt = CarbonImmutable::parse(self::toString($row->observed_at))->toDateString();
            $amountMinor = self::toInt($row->observed_amount_minor);
            $observedCurrency = self::toString($row->observed_currency);
            $eurAmountMinor = null;
            if ($observedCurrency !== 'EUR') {
                $settledCurrency = self::toString($row->settled_currency ?? null);
                if ($settledCurrency === 'EUR') {
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
     * @param  list<string>  $states
     * @return list<RecurringSeriesDto>
     */
    private function scoped(User $user, array $states, ?int $cursorId, int $limit, string $primarySort): array
    {
        $query = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('state', $states)
            ->limit($limit);

        if ($primarySort === 'monthly_equivalent_minor') {
            $query->orderByDesc('monthly_equivalent_minor')->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
        }

        if ($cursorId !== null) {
            if ($primarySort === 'monthly_equivalent_minor') {
                // Composite cursor: the next page is every row whose value is
                // strictly smaller than the cursor row's, plus rows that tie
                // the cursor but whose id sorts lower — otherwise rows are
                // skipped or repeated when neighbours share the same value.
                $cursorRow = $this->db->connection()->table('recurring_series')
                    ->where('id', $cursorId)
                    ->first(['monthly_equivalent_minor']);
                if ($cursorRow !== null) {
                    $cursorEq = self::toInt($cursorRow->monthly_equivalent_minor);
                    $query->where(function (Builder $q) use ($cursorEq, $cursorId): void {
                        $q->where('monthly_equivalent_minor', '<', $cursorEq)
                            ->orWhere(function (Builder $q2) use ($cursorEq, $cursorId): void {
                                $q2->where('monthly_equivalent_minor', $cursorEq)
                                    ->where('id', '<', $cursorId);
                            });
                    });
                }
            } else {
                $query->where('id', '<', $cursorId);
            }
        }

        $rows = $query->get();
        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $result[] = $this->toDto($row);
        }

        return $result;
    }

    /**
     * @return list<RecurringSeriesDto> every approved (and cadence_changed) row, unpaged —
     *                                  Forecasting's projection pipeline needs the full set in one read; the paged
     *                                  approvedForUser() is sized for the review surface's pages, not a batch walk.
     *                                  cadence_changed rows are included because they still represent an approved pattern
     *                                  from the user's perspective (only the cadence reclassification awaits
     *                                  re-confirmation); excluding them would silently drop series from the forecast band
     */
    public function allApprovedForUser(User $user): array
    {
        $rows = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('state', ['approved', 'cadence_changed'])
            ->orderByDesc('monthly_equivalent_minor')
            ->orderByDesc('id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $result[] = $this->toDto($row);
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, int> originating account_id per id, derived from the most-recent
     *                         recurring_series_occurrences row per series and the transactions.account_id it
     *                         points at (recurring_series itself carries no account column). Series with zero
     *                         occurrences fall back to the user's alphabetically-first owned account
     *                         (single-account users resolve unambiguously; multi-account users get a best-effort
     *                         mapping until the detector emits occurrences). Missing or cross-user ids are
     *                         silently absent from the result
     */
    public function accountIdsForSeriesIds(array $seriesIds, User $user): array
    {
        $unique = SeriesIds::normalise($seriesIds);
        if ($unique === []) {
            return [];
        }

        return $this->accounts->forSeriesIds($unique, $user);
    }

    private function toDto(stdClass $row): RecurringSeriesDto
    {
        // RecurringSeriesQuery reads the raw chain-link column with
        // no occurrence-walk fallback — that fallback lives only in
        // FixedPaymentsViewQuery where it is load-bearing.
        $chainLinkId = isset($row->latest_funding_chain_link_id)
            ? self::toInt($row->latest_funding_chain_link_id)
            : null;

        return RecurringSeriesDtoMapper::hydrate($row, $chainLinkId);
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
}
