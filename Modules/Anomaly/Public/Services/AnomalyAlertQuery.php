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
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
final readonly class AnomalyAlertQuery
{
    // 25 rows shown plus 1 look-ahead row the caller uses to decide
    // whether a "next page" cursor exists; the look-ahead row is never
    // rendered.
    public const PAGE_SIZE_WITH_LOOKAHEAD = 26;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private CounterpartyProfileQuery $counterpartyQuery,
    ) {}

    // The state filter is widened to include rows in `state='snoozed'`
    // whose `snoozed_until` has elapsed: the sweep is the durable write,
    // this query is the fresh read reflecting "open again" between sweeps.
    /**
     * @return list<AnomalyAlertDto>
     */
    public function openForUser(User $user, ?int $cursorId = null, int $limit = self::PAGE_SIZE_WITH_LOOKAHEAD): array
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
     * @return list<AnomalyAlertDto>
     */
    public function historyForUser(User $user, ?int $cursorId = null, int $limit = self::PAGE_SIZE_WITH_LOOKAHEAD): array
    {
        return $this->scoped($user, ['acknowledged'], $cursorId, $limit);
    }

    /**
     * @return list<AnomalyAlertDto>
     */
    public function dismissedForUser(User $user, ?int $cursorId = null, int $limit = self::PAGE_SIZE_WITH_LOOKAHEAD): array
    {
        return $this->scoped($user, ['dismissed'], $cursorId, $limit);
    }

    public function openCountForUser(User $user): int
    {
        return $this->db->connection()->table('anomaly_alerts')
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->count();
    }

    // A multi-reason alert contributes to every reason it carries, so
    // counts across detectors can exceed openCountForUser. Computed in
    // PHP since `reasons` is a JSON list (SQLite has no first-class
    // JSON-array aggregation here).
    /**
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

    // A permitted READ of the ledger (noTransactionWritesFromAnomaly
    // forbids only writes); the anomaly table keys per-transaction so the
    // merchant id lives on the transaction.
    /**
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

    // Rows in `state='open'`, plus rows in `state='snoozed'` whose
    // `snoozed_until` has elapsed.
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
