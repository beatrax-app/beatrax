<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Budgets\Public\Dto\BudgetProgressRow;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;

/**
 * Read model for the Budgets page. Joins each (user, category) budget against
 * the spend it accrued in the current period and derives the progress bucket.
 *
 * Spend is `SUM(-settled_amount_minor)` over outflow rows (settled_amount_minor
 * < 0) whose `posted_at` falls in the user's current period and whose
 * `settled_currency` matches the budget currency — the same sign + period
 * convention the dashboard's top-categories panel uses. The aggregate runs as
 * one grouped query, then is matched to budgets in PHP, so the page issues two
 * indexed queries regardless of how many categories are budgeted.
 */
final class BudgetProgressQuery
{
    /** Fraction of a budget at/above which the bar turns amber. */
    private const NEAR_THRESHOLD = 0.8;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PeriodQuery $periods,
        private readonly SpendByCategoryQuery $spendByCategory,
    ) {}

    /**
     * @return list<BudgetProgressRow>
     */
    public function forCurrentPeriod(User $user): array
    {
        $period = $this->periods->current();
        $connection = $this->db->connection();

        $budgets = $connection->table('category_budgets as b')
            ->join('categories as c', 'c.id', '=', 'b.category_id')
            ->where('b.user_id', $user->id)
            // Defence in depth: only surface budgets whose category is the
            // user's own or a global one, so a mis-keyed row can never leak a
            // foreign category's name across the user boundary.
            ->where(static function (QueryBuilder $query) use ($user): void {
                $query->whereNull('c.user_id')->orWhere('c.user_id', $user->id);
            })
            ->orderBy('c.name')
            ->get(['b.category_id', 'c.name', 'b.budget_minor', 'b.currency']);

        if ($budgets->isEmpty()) {
            return [];
        }

        // Delegates to the shared legs ∪ unsplit-parents read model so a
        // split transaction's legs — not its parent row — count toward
        // budget spend (Req 4 / D-02). Already keyed "categoryId|currency",
        // matching what the loop below expects.
        $spendByKey = $this->spendByCategory->forUserAndPeriodByCurrency($user->id, $period);

        $rows = [];
        foreach ($budgets as $budget) {
            $categoryId = self::toInt($budget->category_id);
            $currency = self::toStr($budget->currency);
            $budgetMinor = self::toInt($budget->budget_minor);
            // Budgets are EUR and track EUR-settled spend (the app's
            // consolidated value). Spend whose settled_currency differs from
            // the budget currency is not counted — a known v1 limitation for
            // the rare non-EUR-settled row.
            $spentMinor = max(0, $spendByKey[$categoryId.'|'.$currency] ?? 0);

            $fraction = $budgetMinor > 0 ? $spentMinor / $budgetMinor : 0.0;
            $status = match (true) {
                $fraction > 1.0 => 'over',
                $fraction >= self::NEAR_THRESHOLD => 'near',
                default => 'under',
            };

            $rows[] = new BudgetProgressRow(
                categoryId: $categoryId,
                name: self::toStr($budget->name),
                budgetMinor: $budgetMinor,
                spentMinor: $spentMinor,
                currency: $currency,
                fractionUsed: $fraction,
                status: $status,
            );
        }

        return $rows;
    }

    /**
     * Expense categories available to budget (the user's own + global tree),
     * as id => name, for the "add a budget" picker.
     *
     * @return array<int, string>
     */
    public function expenseCategories(User $user): array
    {
        $rows = $this->db->connection()->table('categories')
            ->where('kind', 'expense')
            ->where(static function (QueryBuilder $query) use ($user): void {
                $query->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        $options = [];
        foreach ($rows as $row) {
            $options[self::toInt($row->id)] = self::toStr($row->name);
        }

        return $options;
    }

    /**
     * Whether $categoryId is a category this user is allowed to budget — i.e.
     * one of their own or a global EXPENSE category. The page must call this
     * before writing a budget: the Livewire category id is client-supplied, so
     * the picker's allow-list is not an authorization boundary on its own.
     */
    public function canBudget(User $user, int $categoryId): bool
    {
        return array_key_exists($categoryId, $this->expenseCategories($user));
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
