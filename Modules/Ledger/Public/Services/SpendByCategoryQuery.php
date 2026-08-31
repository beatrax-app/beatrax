<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\MoneyFlow;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#spendbycategoryquery--the-split-aware-spend-read-model
 */
final readonly class SpendByCategoryQuery
{
    private const string TRANSACTIONS_ALIAS = 'transactions as t';

    use CoercesScalars;

    public function __construct(private DatabaseManager $db) {}

    // Never a single reporting currency: handing this one and filtering on it
    // dropped every row settled elsewhere, and the trend card read EUR 1,602.45
    // under an OUT tile reading EUR 1,608.74 one card away. Callers get every
    // bucket and convert, which is ConvertedSpendByCategory's job.
    /**
     * @return array<string, int> "categoryId|currency" => spend minor (positive)
     */
    public function forUserAndPeriodByCurrency(int $userId, Period $period, bool $includeUncategorized = false): array
    {
        $connection = $this->db->connection();
        $map = [];

        // Unsplit + broken-split parents (see the linked architecture
        // page for the three-case predicate this correlated subquery covers).
        $unsplitQuery = $connection->table(self::TRANSACTIONS_ALIAS)
            ->whereRaw('COALESCE((SELECT SUM(ts.settled_amount_minor) FROM transaction_splits AS ts WHERE ts.transaction_id = t.id), 0) <> t.settled_amount_minor')
            ->where('t.user_id', $userId)
            ->whereIn('t.type', MoneyFlow::Spend->types())
            ->where('t.posted_at', '>=', $period->start->toDateString())
            ->where('t.posted_at', '<', $period->endExclusive->toDateString());

        if (! $includeUncategorized) {
            $unsplitQuery->whereNotNull('t.category_id');
        }

        $unsplit = $unsplitQuery
            ->groupBy('t.category_id', 't.settled_currency')
            ->get(['t.category_id', 't.settled_currency', $connection->raw('SUM(-t.settled_amount_minor) AS spend_minor')]);

        foreach ($unsplit as $row) {
            $key = self::toInt($row->category_id).'|'.self::toString($row->settled_currency);
            $map[$key] = ($map[$key] ?? 0) + self::toInt($row->spend_minor);
        }

        // Split legs, joined through the parent for user_id/posted_at
        // (legs carry neither). Legs always carry a required
        // category_id, so includeUncategorized has no bearing here.
        $legs = $connection->table('transaction_splits as ts')
            ->join(self::TRANSACTIONS_ALIAS, 't.id', '=', 'ts.transaction_id')
            ->where('t.user_id', $userId)
            ->whereIn('t.type', MoneyFlow::Spend->types())
            ->where('t.posted_at', '>=', $period->start->toDateString())
            ->where('t.posted_at', '<', $period->endExclusive->toDateString())
            // Only attribute legs when the split is internally
            // consistent; broken splits fall back to the parent above.
            ->whereRaw('(SELECT SUM(ts2.settled_amount_minor) FROM transaction_splits AS ts2 WHERE ts2.transaction_id = ts.transaction_id) = t.settled_amount_minor')
            ->groupBy('ts.category_id', 'ts.settled_currency')
            ->get(['ts.category_id', 'ts.settled_currency', $connection->raw('SUM(-ts.settled_amount_minor) AS spend_minor')]);

        foreach ($legs as $row) {
            $key = self::toInt($row->category_id).'|'.self::toString($row->settled_currency);
            $map[$key] = ($map[$key] ?? 0) + self::toInt($row->spend_minor);
        }

        return $map;
    }

    // The same two reads over a whole span, grouped by day as well, so a caller
    // that folds period by period pays two queries instead of two per period.
    // The carryover fold walks from genesis, so that was unbounded in the
    // length of the reader's history.
    /**
     * @return array<string, array<string, int>> posted_at => "categoryId|currency" => minor
     */
    public function forUserAndSpanByCurrencyPerDay(int $userId, Period $span): array
    {
        $connection = $this->db->connection();
        $byDay = [];

        $unsplit = $connection->table(self::TRANSACTIONS_ALIAS)
            ->whereRaw('COALESCE((SELECT SUM(ts.settled_amount_minor) FROM transaction_splits AS ts WHERE ts.transaction_id = t.id), 0) <> t.settled_amount_minor')
            ->where('t.user_id', $userId)
            ->whereIn('t.type', MoneyFlow::Spend->types())
            ->where('t.posted_at', '>=', $span->start->toDateString())
            ->where('t.posted_at', '<', $span->endExclusive->toDateString())
            ->whereNotNull('t.category_id')
            ->groupBy('t.posted_at', 't.category_id', 't.settled_currency')
            ->get(['t.posted_at', 't.category_id', 't.settled_currency', $connection->raw('SUM(-t.settled_amount_minor) AS spend_minor')]);

        foreach ($unsplit as $row) {
            $day = self::toString($row->posted_at);
            $key = self::toInt($row->category_id).'|'.self::toString($row->settled_currency);
            $byDay[$day][$key] = ($byDay[$day][$key] ?? 0) + self::toInt($row->spend_minor);
        }

        $legs = $connection->table('transaction_splits as ts')
            ->join(self::TRANSACTIONS_ALIAS, 't.id', '=', 'ts.transaction_id')
            ->where('t.user_id', $userId)
            ->whereIn('t.type', MoneyFlow::Spend->types())
            ->where('t.posted_at', '>=', $span->start->toDateString())
            ->where('t.posted_at', '<', $span->endExclusive->toDateString())
            ->whereRaw('(SELECT SUM(ts2.settled_amount_minor) FROM transaction_splits AS ts2 WHERE ts2.transaction_id = ts.transaction_id) = t.settled_amount_minor')
            ->groupBy('t.posted_at', 'ts.category_id', 'ts.settled_currency')
            ->get(['t.posted_at', 'ts.category_id', 'ts.settled_currency', $connection->raw('SUM(-ts.settled_amount_minor) AS spend_minor')]);

        foreach ($legs as $row) {
            $day = self::toString($row->posted_at);
            $key = self::toInt($row->category_id).'|'.self::toString($row->settled_currency);
            $byDay[$day][$key] = ($byDay[$day][$key] ?? 0) + self::toInt($row->spend_minor);
        }

        return $byDay;
    }
}
