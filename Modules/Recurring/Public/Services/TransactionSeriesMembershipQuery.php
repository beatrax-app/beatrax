<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Recurring\Internal\Support\SeriesIds;
use Modules\Recurring\Internal\Support\SeriesTables;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
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

    public function __construct(private DatabaseManager $db) {}

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
        // UNIQUE(user_id, direction, cluster_counterparty_key, latest_currency)
        // makes the joined triple plus a direction at most one series, so no
        // tie-break is needed once the direction filter below has run.
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
}
