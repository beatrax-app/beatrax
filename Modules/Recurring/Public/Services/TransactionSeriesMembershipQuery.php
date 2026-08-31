<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Recurring\Internal\Support\SeriesIds;
use Modules\Recurring\Internal\Support\SeriesTables;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// Which series a transaction belongs to is a different question from what a
// series looks like, and its callers are elsewhere: a duplicate-charge
// detector, a calendar placer and a forecast projector all ask it of a batch of
// transaction ids and none of them wants a series read model back.
/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
final readonly class TransactionSeriesMembershipQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private CounterpartyKey $counterpartyKey,
    ) {}

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
        $unique = SeriesIds::normalise($transactionIds);
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
        $rows = $this->db->connection()->table(SeriesTables::OCCURRENCES)
            ->join(SeriesTables::SERIES, 's.id', '=', 'o.recurring_series_id')
            ->where('o.user_id', $user->id)
            ->whereIn('o.transaction_id', $transactionIds)
            ->whereIn('s.state', RecurringSeriesState::projectableValues())
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
        // (user_id, direction, cluster_counterparty_key, latest_currency) carries
        // a plain INDEX, not a UNIQUE, so the triple can match more than one
        // series. Lowest id wins, which is the row the detectors' own
        // ascending-id index hands back when they resolve the same cluster.
        $rows = $this->db->connection()->table(SeriesTables::TRANSACTIONS)
            ->join(SeriesTables::SERIES, function (JoinClause $join): void {
                $join->on('s.user_id', '=', 't.user_id')
                    ->on('s.cluster_counterparty_key', '=', 't.counterparty_normalized')
                    ->on('s.latest_currency', '=', 't.currency');
            })
            ->where('t.user_id', $user->id)
            ->whereIn('t.id', $transactionIds)
            ->where('t.counterparty_normalized', '!=', CounterpartyKey::NONE)
            ->whereIn('s.state', RecurringSeriesState::projectableValues())
            ->orderBy('s.id')
            ->get(['t.id as transaction_id', 't.type as type', 's.id as series_id', 's.direction as direction']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            if (TransactionType::directionOf($row->type) !== Direction::tryFrom(self::toString($row->direction))) {
                continue;
            }
            $transactionId = self::toInt($row->transaction_id);
            if (! isset($map[$transactionId])) {
                $map[$transactionId] = self::toInt($row->series_id);
            }
        }

        $unresolved = array_values(array_filter($transactionIds, static fn (int $id): bool => ! isset($map[$id])));

        return $unresolved === [] ? $map : $map + $this->ibanClusteredSeriesIds($unresolved, $user);
    }

    // The income detector clusters on the payer's IBAN when there is one, and
    // writes THAT key into cluster_counterparty_key — a different blind-index
    // domain from counterparty_normalized, so the join above can never match an
    // income series and a future-dated salary was counted twice.
    /**
     * @param  list<int>  $transactionIds
     * @return array<int, int>
     */
    private function ibanClusteredSeriesIds(array $transactionIds, User $user): array
    {
        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('id', $transactionIds)
            ->where('type', TransactionType::Income->value)
            ->whereNotNull('counterparty_iban')
            ->get(['id', 'currency', 'counterparty_iban']);
        if ($rows->isEmpty()) {
            return [];
        }

        $session = ($this->session)();
        $idsByKey = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $iban = $this->codec->decryptValue(
                'transactions',
                'counterparty_iban',
                self::toString($row->counterparty_iban),
                $user->id,
                $session,
            )['value'];
            if ($iban === '') {
                continue;
            }
            $key = $this->counterpartyKey->forIban($iban, $user->id);
            if ($key === CounterpartyKey::NONE) {
                continue;
            }
            $idsByKey[$key."\0".self::toString($row->currency)][] = self::toInt($row->id);
        }

        if ($idsByKey === []) {
            return [];
        }

        return self::fanOut($idsByKey, $this->incomeSeriesByKey($user, array_keys($idsByKey)));
    }

    /**
     * @param  list<string>  $keyedCurrencies  cluster key and currency joined by NUL
     * @return array<string, int>
     */
    private function incomeSeriesByKey(User $user, array $keyedCurrencies): array
    {
        $keys = [];
        foreach ($keyedCurrencies as $keyed) {
            $keys[] = self::splitKey($keyed)[0];
        }

        $rows = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->where('direction', Direction::Income->value)
            ->whereIn('cluster_counterparty_key', array_values(array_unique($keys)))
            ->whereIn('state', RecurringSeriesState::projectableValues())
            ->orderBy('id')
            ->get(['id', 'cluster_counterparty_key', 'latest_currency']);

        $byKey = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $keyed = self::toString($row->cluster_counterparty_key)."\0".self::toString($row->latest_currency);
            if (! isset($byKey[$keyed])) {
                $byKey[$keyed] = self::toInt($row->id);
            }
        }

        return $byKey;
    }

    /**
     * @param  array<string, list<int>>  $idsByKey
     * @param  array<string, int>  $seriesByKey
     * @return array<int, int>
     */
    private static function fanOut(array $idsByKey, array $seriesByKey): array
    {
        $map = [];
        foreach ($idsByKey as $keyed => $transactionIds) {
            if (! isset($seriesByKey[$keyed])) {
                continue;
            }
            foreach ($transactionIds as $transactionId) {
                $map[$transactionId] = $seriesByKey[$keyed];
            }
        }

        return $map;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitKey(string $keyed): array
    {
        $parts = explode("\0", $keyed, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
