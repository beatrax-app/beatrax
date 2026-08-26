<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\TransactionType;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#spendbycategoryquery--the-split-aware-spend-read-model
 */
final class SpendByCategoryQuery
{
    // Spend is `type = expense`, never the amount's sign: a transfer to the
    // reader's own card is negative and is not money spent. Same rule as
    // ThisPeriodAtAGlanceQuery's outflow and Reports' spend metric.
    private const TRANSACTIONS_ALIAS = 'transactions as t';

    use CoercesScalars;

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return array<int, int> category_id => spend minor (positive), for one currency
     */
    public function forUserAndPeriod(int $userId, Period $period, string $currency, bool $includeUncategorized = false): array
    {
        $connection = $this->db->connection();
        $map = [];

        // Unsplit + broken-split parents (see the linked architecture
        // page for the three-case predicate this correlated subquery covers).
        $unsplitQuery = $connection->table(self::TRANSACTIONS_ALIAS)
            ->whereRaw('COALESCE((SELECT SUM(ts.settled_amount_minor) FROM transaction_splits AS ts WHERE ts.transaction_id = t.id), 0) <> t.settled_amount_minor')
            ->where('t.user_id', $userId)
            ->where('t.type', TransactionType::Expense->value)
            ->where('t.settled_currency', $currency)
            ->where('t.settled_amount_minor', '<', 0)
            ->where('t.posted_at', '>=', $period->start->toDateString())
            ->where('t.posted_at', '<', $period->endExclusive->toDateString());

        if (! $includeUncategorized) {
            $unsplitQuery->whereNotNull('t.category_id');
        }

        $unsplit = $unsplitQuery
            ->groupBy('t.category_id')
            ->get(['t.category_id', $connection->raw('SUM(-t.settled_amount_minor) AS spend_minor')]);

        foreach ($unsplit as $row) {
            $categoryId = self::toInt($row->category_id);
            $map[$categoryId] = ($map[$categoryId] ?? 0) + self::toInt($row->spend_minor);
        }

        // Split legs, joined through the parent for user_id/posted_at
        // (legs carry neither). Legs always carry a required
        // category_id, so includeUncategorized has no bearing here.
        $legs = $connection->table('transaction_splits as ts')
            ->join(self::TRANSACTIONS_ALIAS, 't.id', '=', 'ts.transaction_id')
            ->where('t.user_id', $userId)
            ->where('t.type', TransactionType::Expense->value)
            ->where('ts.settled_currency', $currency)
            ->where('ts.settled_amount_minor', '<', 0)
            ->where('t.posted_at', '>=', $period->start->toDateString())
            ->where('t.posted_at', '<', $period->endExclusive->toDateString())
            // Only attribute legs when the split is internally
            // consistent; broken splits fall back to the parent above.
            ->whereRaw('(SELECT SUM(ts2.settled_amount_minor) FROM transaction_splits AS ts2 WHERE ts2.transaction_id = ts.transaction_id) = t.settled_amount_minor')
            ->groupBy('ts.category_id')
            ->get(['ts.category_id', $connection->raw('SUM(-ts.settled_amount_minor) AS spend_minor')]);

        foreach ($legs as $row) {
            $categoryId = self::toInt($row->category_id);
            $map[$categoryId] = ($map[$categoryId] ?? 0) + self::toInt($row->spend_minor);
        }

        return $map;
    }

    /**
     * @return array<string, int> "categoryId|currency" => spend minor (positive)
     */
    public function forUserAndPeriodByCurrency(int $userId, Period $period): array
    {
        $connection = $this->db->connection();
        $map = [];

        $unsplit = $connection->table(self::TRANSACTIONS_ALIAS)
            ->whereRaw('COALESCE((SELECT SUM(ts.settled_amount_minor) FROM transaction_splits AS ts WHERE ts.transaction_id = t.id), 0) <> t.settled_amount_minor')
            ->where('t.user_id', $userId)
            ->where('t.type', TransactionType::Expense->value)
            ->where('t.settled_amount_minor', '<', 0)
            ->where('t.posted_at', '>=', $period->start->toDateString())
            ->where('t.posted_at', '<', $period->endExclusive->toDateString())
            ->whereNotNull('t.category_id')
            ->groupBy('t.category_id', 't.settled_currency')
            ->get(['t.category_id', 't.settled_currency', $connection->raw('SUM(-t.settled_amount_minor) AS spend_minor')]);

        foreach ($unsplit as $row) {
            $key = self::toInt($row->category_id).'|'.self::toString($row->settled_currency);
            $map[$key] = ($map[$key] ?? 0) + self::toInt($row->spend_minor);
        }

        $legs = $connection->table('transaction_splits as ts')
            ->join(self::TRANSACTIONS_ALIAS, 't.id', '=', 'ts.transaction_id')
            ->where('t.user_id', $userId)
            ->where('t.type', TransactionType::Expense->value)
            ->where('ts.settled_amount_minor', '<', 0)
            ->where('t.posted_at', '>=', $period->start->toDateString())
            ->where('t.posted_at', '<', $period->endExclusive->toDateString())
            ->whereRaw('(SELECT SUM(ts2.settled_amount_minor) FROM transaction_splits AS ts2 WHERE ts2.transaction_id = ts.transaction_id) = t.settled_amount_minor')
            ->groupBy('ts.category_id', 'ts.settled_currency')
            ->get(['ts.category_id', 'ts.settled_currency', $connection->raw('SUM(-ts.settled_amount_minor) AS spend_minor')]);

        foreach ($legs as $row) {
            $key = self::toInt($row->category_id).'|'.self::toString($row->settled_currency);
            $map[$key] = ($map[$key] ?? 0) + self::toInt($row->spend_minor);
        }

        return $map;
    }
}
