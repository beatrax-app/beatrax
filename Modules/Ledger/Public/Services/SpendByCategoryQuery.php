<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Ledger\Public\Dto\Period;

/**
 * Shared "spend by category" read model over `legs ∪ unsplit-parents`
 * (Phase 13.1, Req 4 / D-02). This is the single place that decides how a
 * split transaction's spend is attributed: a split parent contributes ZERO
 * directly to any category total — only its `transaction_splits` legs do —
 * while an unsplit transaction still rolls up via its own `category_id`
 * exactly as before this phase.
 *
 * "Is this transaction split?" is answered ONLY by leg-row presence
 * (`whereNotExists`/join against `transaction_splits`), NEVER by
 * `category_id` nullity — a split parent may carry a vestigial non-null
 * `category_id` (D-11), and keying off nullity would double-count it.
 *
 * Two methods cover the two shapes the existing call sites need
 * (Pitfall 3): a single-currency map for `TopCategoriesByPeriodQuery` /
 * `CategorySpendTrendQuery`, and an all-currencies-keyed map reproducing
 * `BudgetProgressQuery`'s existing `(category_id, currency)` grouping.
 *
 * Both methods compute in the codebase's established "group in SQL, merge
 * in PHP" style (two grouped query-builder calls folded into one PHP array)
 * rather than a raw SQL UNION.
 */
final class SpendByCategoryQuery
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return array<int, int> category_id => spend minor (positive), for one currency
     */
    public function forUserAndPeriod(int $userId, Period $period, string $currency, bool $includeUncategorized = false): array
    {
        $connection = $this->db->connection();
        $map = [];

        // 1. Unsplit parents: excluded from the roll-up the moment a leg
        // row exists for them (whereNotExists — never category_id nullity).
        $unsplitQuery = $connection->table('transactions as t')
            ->whereNotExists(function (Builder $sub): void {
                $sub->select($sub->raw(1))
                    ->from('transaction_splits as ts')
                    ->whereColumn('ts.transaction_id', 't.id');
            })
            ->where('t.user_id', $userId)
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

        // 2. Split legs: same user/period predicate as the parent, joined
        // through the parent for user_id/posted_at (legs carry neither).
        // Legs always carry a required category_id, so includeUncategorized
        // has no bearing here.
        $legs = $connection->table('transaction_splits as ts')
            ->join('transactions as t', 't.id', '=', 'ts.transaction_id')
            ->where('t.user_id', $userId)
            ->where('ts.settled_currency', $currency)
            ->where('ts.settled_amount_minor', '<', 0)
            ->where('t.posted_at', '>=', $period->start->toDateString())
            ->where('t.posted_at', '<', $period->endExclusive->toDateString())
            ->groupBy('ts.category_id')
            ->get(['ts.category_id', $connection->raw('SUM(-ts.settled_amount_minor) AS spend_minor')]);

        foreach ($legs as $row) {
            $categoryId = self::toInt($row->category_id);
            $map[$categoryId] = ($map[$categoryId] ?? 0) + self::toInt($row->spend_minor);
        }

        return $map;
    }

    /**
     * Reproduces BudgetProgressQuery's existing `(category_id, currency)`
     * grouping across ALL currencies in one map, keyed
     * `"{categoryId}|{currency}"`. Uncategorized spend is always excluded
     * here — BudgetProgressQuery already filters `whereNotNull('category_id')`
     * because uncategorized spend cannot be matched to a budget.
     *
     * @return array<string, int> "categoryId|currency" => spend minor (positive)
     */
    public function forUserAndPeriodByCurrency(int $userId, Period $period): array
    {
        $connection = $this->db->connection();
        $map = [];

        $unsplit = $connection->table('transactions as t')
            ->whereNotExists(function (Builder $sub): void {
                $sub->select($sub->raw(1))
                    ->from('transaction_splits as ts')
                    ->whereColumn('ts.transaction_id', 't.id');
            })
            ->where('t.user_id', $userId)
            ->where('t.settled_amount_minor', '<', 0)
            ->where('t.posted_at', '>=', $period->start->toDateString())
            ->where('t.posted_at', '<', $period->endExclusive->toDateString())
            ->whereNotNull('t.category_id')
            ->groupBy('t.category_id', 't.settled_currency')
            ->get(['t.category_id', 't.settled_currency', $connection->raw('SUM(-t.settled_amount_minor) AS spend_minor')]);

        foreach ($unsplit as $row) {
            $key = self::toInt($row->category_id).'|'.self::toStr($row->settled_currency);
            $map[$key] = ($map[$key] ?? 0) + self::toInt($row->spend_minor);
        }

        $legs = $connection->table('transaction_splits as ts')
            ->join('transactions as t', 't.id', '=', 'ts.transaction_id')
            ->where('t.user_id', $userId)
            ->where('ts.settled_amount_minor', '<', 0)
            ->where('t.posted_at', '>=', $period->start->toDateString())
            ->where('t.posted_at', '<', $period->endExclusive->toDateString())
            ->groupBy('ts.category_id', 'ts.settled_currency')
            ->get(['ts.category_id', 'ts.settled_currency', $connection->raw('SUM(-ts.settled_amount_minor) AS spend_minor')]);

        foreach ($legs as $row) {
            $key = self::toInt($row->category_id).'|'.self::toStr($row->settled_currency);
            $map[$key] = ($map[$key] ?? 0) + self::toInt($row->spend_minor);
        }

        return $map;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
