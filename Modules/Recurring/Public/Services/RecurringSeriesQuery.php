<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Internal\Mapping\RecurringSeriesDtoMapper;
use Modules\Recurring\Public\Dto\RecurringOccurrenceDto;
use Modules\Recurring\Public\Dto\RecurringSeriesAmountTrendDto;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use stdClass;

/**
 * Public read API over `recurring_series`. Every method scopes by
 * `user_id` and returns Spatie-Data DTOs so the review page, the
 * fixed-payments view, the dashboard tile, and downstream module
 * listeners read a single canonical shape.
 *
 * Cross-user reads return an empty list or `null`; cross-user 404s
 * are surfaced at the Public Action layer (mirrors the Chains
 * precedent — query services stay silent so caller policy stays
 * caller-side).
 *
 * Cursor pagination on `id` matches the chains-side review queue.
 * `approvedForUser` orders by `monthly_equivalent_minor DESC` then
 * `id DESC` so the dashboard tile + fixed-payments view consume a
 * stable, "largest first" projection; its cursor is a composite of
 * the cursor row's `(monthly_equivalent_minor, id)` so subsequent
 * pages follow the primary sort instead of an id-only window.
 */
final readonly class RecurringSeriesQuery
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * Series whose state is exactly `pending` — never `cadence_changed`.
     * The review page surfaces cadence-flipped rows on a dedicated tab
     * via `cadenceChangedForUser`, so widening this projection would
     * double-count those rows in both the Pending tab and the Cadence-
     * changed tab.
     *
     * @return list<RecurringSeriesDto>
     */
    public function pendingForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['pending'], $cursorId, $limit, 'id');
    }

    /**
     * Count of strictly-pending rows, mirroring `pendingForUser`. The
     * top-nav badge composer reads this; the cadence-changed tab has
     * its own queue and is intentionally NOT folded in.
     */
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
     * Batched variant of `forSeries` — fetch every series row in
     * `$seriesIds` belonging to `$user` in a single SELECT and return
     * a `series_id => RecurringSeriesDto` map. Missing or cross-user
     * ids are silently absent from the result.
     *
     * Callers that need a per-series projection (cancellation impact,
     * display-name hydration, threshold lookup) for a list of ids on
     * a single page render reach for this method instead of looping
     * over `forSeries`, which would issue N queries.
     *
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, RecurringSeriesDto>
     */
    public function forSeriesIds(array $seriesIds, User $user): array
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
            ->get();

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $map[self::toInt($row->id)] = $this->toDto($row);
        }

        return $map;
    }

    /**
     * Every observation row that contributed to the cluster, ordered by
     * `observed_at DESC`. Cross-user lookups return an empty list.
     *
     * @return list<RecurringOccurrenceDto>
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
     * Build the amount-over-time payload for the drill-in chart.
     *
     * Returns up to `$maxPoints` data points sorted oldest-to-newest so
     * the ApexCharts datetime axis renders left-to-right. Each point
     * carries the native-currency amount plus the settled-EUR amount;
     * `eur_amount_minor` is `null` when the observation is already in
     * EUR (no shadow series needed).
     *
     * Cross-user lookups return an empty payload.
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
            // Schema guarantees latest_currency is non-null + 3 chars.
            // An empty value here means the row is corrupt or the
            // detector wrote a malformed insert. Return an empty
            // payload rather than fabricating an EUR label that
            // would mislead the chart axis.
            return new RecurringSeriesAmountTrendDto(
                seriesId: $seriesId,
                currency: '',
                points: [],
                maxPoints: $maxPoints,
            );
        }

        $effectiveLimit = max(1, $maxPoints);
        $rows = $this->db->connection()->table('recurring_series_occurrences as rso')
            ->leftJoin('transactions as t', 't.id', '=', 'rso.transaction_id')
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
                // Composite cursor: the next page is every row whose
                // monthly_equivalent_minor is strictly smaller than the
                // cursor row's, plus the rows whose equivalent ties the
                // cursor but whose id sorts lower. Anything else would
                // skip rows or repeat them when the cursor row's
                // equivalent has neighbours that share its value.
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
