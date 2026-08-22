<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Modules\Anomaly\Internal\Mapping\AnomalyAlertDtoMapper;
use Modules\Anomaly\Public\Dto\AnomalyAlertDto;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
use stdClass;

final readonly class AnomalyAlertQuery
{
    use CoercesScalars;

    // Despite the name, nothing is held back: the page renders all 26 and reads
    // a full 26 as "there may be more", seeding the next cursor off the last row.
    public const PAGE_SIZE_WITH_LOOKAHEAD = 26;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private CounterpartyProfileQuery $counterpartyQuery,
        private BaseCurrency $baseCurrency,
    ) {}

    // Elapsed snoozes count as open here: the sweep is the durable write, this
    // is the fresh read that covers the gap between sweeps.
    /**
     * @return list<AnomalyAlertDto>
     */
    public function openForUser(
        User $user,
        ?string $cursorDetectedAt = null,
        ?int $cursorId = null,
        int $limit = self::PAGE_SIZE_WITH_LOOKAHEAD,
    ): array {
        $query = $this->db->connection()->table('anomaly_alerts')
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->limit($limit);

        $this->applyCursor($query, $cursorDetectedAt, $cursorId);

        return $this->materialise($user, $query->get());
    }

    /**
     * @return list<AnomalyAlertDto>
     */
    public function historyForUser(
        User $user,
        ?string $cursorDetectedAt = null,
        ?int $cursorId = null,
        int $limit = self::PAGE_SIZE_WITH_LOOKAHEAD,
    ): array {
        return $this->scoped($user, [AnomalyAlertState::Acknowledged->value], $cursorDetectedAt, $cursorId, $limit);
    }

    /**
     * @return list<AnomalyAlertDto>
     */
    public function dismissedForUser(
        User $user,
        ?string $cursorDetectedAt = null,
        ?int $cursorId = null,
        int $limit = self::PAGE_SIZE_WITH_LOOKAHEAD,
    ): array {
        return $this->scoped($user, [AnomalyAlertState::Dismissed->value], $cursorDetectedAt, $cursorId, $limit);
    }

    public function openCountForUser(User $user): int
    {
        return $this->db->connection()->table('anomaly_alerts')
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
            ->count();
    }

    // A multi-reason alert contributes to every reason it carries, so these
    // counts can sum to more than openCountForUser.
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
    private function scoped(User $user, array $states, ?string $cursorDetectedAt, ?int $cursorId, int $limit): array
    {
        $query = $this->db->connection()->table('anomaly_alerts')
            ->where('user_id', $user->id)
            ->whereIn('state', $states)
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->limit($limit);

        $this->applyCursor($query, $cursorDetectedAt, $cursorId);

        return $this->materialise($user, $query->get());
    }

    // The id is derived from the alert's own columns, so it sorts in hash order,
    // not insertion order — paging on `id < cursor` alone would skip and repeat
    // rows at random. detected_at leads; id only breaks ties within a timestamp.
    private function applyCursor(Builder $query, ?string $cursorDetectedAt, ?int $cursorId): void
    {
        if ($cursorDetectedAt === null || $cursorId === null) {
            return;
        }

        $query->where(function (Builder $page) use ($cursorDetectedAt, $cursorId): void {
            $page->where('detected_at', '<', $cursorDetectedAt)
                ->orWhere(function (Builder $tie) use ($cursorDetectedAt, $cursorId): void {
                    $tie->where('detected_at', $cursorDetectedAt)->where('id', '<', $cursorId);
                });
        });
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

        $counterpartyByTxn = $this->counterpartyIdsForTransactions($user, $rows);
        $displayNames = $this->loadDisplayNames($user, $counterpartyByTxn);

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $txnId = self::toInt($row->transaction_id ?? null);
            $counterpartyId = $counterpartyByTxn[$txnId] ?? 0;
            $result[] = AnomalyAlertDtoMapper::hydrate($row, $displayNames[$counterpartyId] ?? '', $this->baseCurrency->code());
        }

        return $result;
    }

    // A permitted cross-module READ of the ledger (the boundary test pins only
    // writes); anomaly_alerts keys per-transaction, so the merchant id is over
    // on the transaction.
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

    private function applyOpenStateFilter(Builder $query): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $query->where('state', AnomalyAlertState::Open->value)
            ->orWhere(function (Builder $q) use ($now): void {
                $q->where('state', AnomalyAlertState::Snoozed->value)
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
}
