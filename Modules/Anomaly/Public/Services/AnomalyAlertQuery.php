<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Modules\Anomaly\Internal\Mapping\AnomalyAlertDtoMapper;
use Modules\Anomaly\Public\Dto\AnomalyAlertDto;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use stdClass;

/**
 * Public read API over `anomaly_alerts`. Cloned from `DriftAlertQuery`,
 * re-keyed to the per-transaction anomaly shape. Every method scopes by
 * `user_id` and returns Spatie-Data DTOs so the anomaly section of the
 * /drift alerts home, the dashboard tile, and downstream listeners read a
 * single canonical shape.
 *
 * Cross-user reads return an empty list (or zero on count aggregates);
 * cross-user 404s are surfaced at the Public Action layer.
 *
 * Cursor pagination is keyed strictly on `id DESC`. The single-column
 * cursor stays monotone: `anomaly_alerts.id` is a SQLite autoincrementing
 * surrogate, so newer alerts always have larger ids. Ordering and
 * filtering by id alone keeps the cursor consistent when several alerts
 * share an exact `detected_at` second (a backfill or safety-net sweep can
 * batch many writes inside a single scheduler tick).
 *
 * Cross-module reads of `counterparties` (display name) are delegated to
 * `CounterpartyProfileQuery::identitiesForIds` so the Anomaly module
 * never issues a raw SELECT against another module's table.
 *
 * Unlike drift, anomalies are per-transaction and carry no
 * recurring-series coupling, so the series-grouped /
 * annualized-impact methods are dropped. `openDetectorBreakdownForUser`
 * counts open alerts per detector reason for the dashboard tile.
 */
final readonly class AnomalyAlertQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private CounterpartyProfileQuery $counterpartyQuery,
    ) {}

    /**
     * Open alerts. The state filter is widened to include rows in
     * `state='snoozed'` whose `snoozed_until` has elapsed: the snooze-
     * revival sweep flips those rows back to `state='open'` and writes an
     * audit transition, but between sweeps the count + the listing must
     * already reflect the user-visible "this is open again" reality. The
     * two paths produce the same set; the sweep is the durable write, the
     * query is the fresh read.
     *
     * Sort: `id DESC`. Cursor pagination filters strictly on `id`.
     *
     * @return list<AnomalyAlertDto>
     */
    public function openForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        $query = $this->db->connection()->table('anomaly_alerts')
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
     * Acknowledged alerts (state='acknowledged' — the History tab).
     *
     * @return list<AnomalyAlertDto>
     */
    public function historyForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['acknowledged'], $cursorId, $limit);
    }

    /**
     * Dismissed alerts (state='dismissed' — the Dismissed tab). Anomaly
     * uses the plain dismissed state, never the drift cancelled variant.
     *
     * @return list<AnomalyAlertDto>
     */
    public function dismissedForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['dismissed'], $cursorId, $limit);
    }

    /**
     * Count of open alerts for the user. Revival-aware (mirrors
     * `openForUser`). Used by the top-nav badge composer and the
     * dashboard "Unusual charges" tile.
     */
    public function openCountForUser(User $user): int
    {
        return $this->db->connection()->table('anomaly_alerts')
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->count();
    }

    /**
     * Counts of open alerts per tripped detector reason. A multi-reason
     * alert (D-16) contributes to every reason it carries, so the counts
     * across detectors can exceed `openCountForUser`. Used by the
     * dashboard tile's per-detector breakdown.
     *
     * The `reasons` column is a JSON list, so the breakdown is computed
     * in PHP over the revival-aware open set rather than via a SQL
     * GROUP BY (SQLite has no first-class JSON-array aggregation here).
     *
     * @return array<string, int>
     */
    public function openDetectorBreakdownForUser(User $user): array
    {
        $rows = $this->db->connection()->table('anomaly_alerts')
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->get(['reasons']);

        $breakdown = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $reasons = self::decodeReasons($row->reasons ?? null);
            foreach ($reasons as $reason) {
                $breakdown[$reason] = ($breakdown[$reason] ?? 0) + 1;
            }
        }

        return $breakdown;
    }

    /**
     * @param  list<string>  $states
     * @return list<AnomalyAlertDto>
     */
    private function scoped(User $user, array $states, ?int $cursorId, int $limit): array
    {
        $query = $this->db->connection()->table('anomaly_alerts')
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
     * @param  Collection<int, stdClass>  $rows
     * @return list<AnomalyAlertDto>
     */
    private function materialise(User $user, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        // Resolve transaction_id -> counterparty_id (a permitted ledger
        // READ — the anomaly table keys per-transaction, not per-merchant),
        // then transaction_id -> display name via the resolved counterparty.
        $counterpartyByTxn = $this->counterpartyIdsForTransactions($user, $rows);
        $displayNames = $this->loadDisplayNames($user, $counterpartyByTxn);

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $txnId = self::toInt($row->transaction_id ?? null);
            $counterpartyId = $counterpartyByTxn[$txnId] ?? 0;
            $result[] = AnomalyAlertDtoMapper::hydrate($row, $displayNames[$counterpartyId] ?? '');
        }

        return $result;
    }

    /**
     * Resolve `transaction_id => counterparty_id` for the alert rows.
     * This is a permitted READ of the ledger (the
     * `noTransactionWritesFromAnomaly` invariant forbids only writes);
     * the anomaly table keys per-transaction so the merchant id lives on
     * the transaction.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return array<int, int>
     */
    private function counterpartyIdsForTransactions(User $user, Collection $rows): array
    {
        $txnIds = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $txnId = self::toInt($row->transaction_id ?? null);
            if ($txnId > 0) {
                $txnIds[] = $txnId;
            }
        }

        if ($txnIds === []) {
            return [];
        }

        $txns = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('id', array_values(array_unique($txnIds)))
            ->get(['id', 'counterparty_id']);

        $map = [];
        foreach ($txns as $txn) {
            /** @var stdClass $txn */
            $id = self::toInt($txn->id ?? null);
            $cpId = self::toInt($txn->counterparty_id ?? null);
            if ($id > 0 && $cpId > 0) {
                $map[$id] = $cpId;
            }
        }

        return $map;
    }

    /**
     * Apply the open-tab state filter onto a query builder: rows in
     * `state='open'`, plus rows in `state='snoozed'` whose `snoozed_until`
     * has elapsed. Copied VERBATIM from `DriftAlertQuery::applyOpenStateFilter`.
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
     * Resolve merchant display names for the counterparty ids via the
     * Counterparties Public surface — no raw cross-module SELECT.
     *
     * @param  array<int, int>  $counterpartyByTxn  transaction_id => counterparty_id
     * @return array<int, string> counterparty_id => display name
     */
    private function loadDisplayNames(User $user, array $counterpartyByTxn): array
    {
        $ids = array_values(array_unique(array_filter(
            array_values($counterpartyByTxn),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        $identities = $this->counterpartyQuery->identitiesForIds($user, $ids);

        $names = [];
        foreach ($identities as $id => $identity) {
            $names[$id] = $identity['displayName'];
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function decodeReasons(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $r): string => is_string($r) ? $r : '', $decoded),
            static fn (string $r): bool => $r !== '',
        ));
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
