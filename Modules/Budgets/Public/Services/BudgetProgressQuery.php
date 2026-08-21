<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Budgets\Public\Dto\BudgetProgressRow;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Enums\CategoryKind;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;

final class BudgetProgressQuery
{
    use CoercesScalars;

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
            // A mis-keyed budget row must not leak a foreign category's name.
            ->where(static function (QueryBuilder $query) use ($user): void {
                $query->whereNull('c.user_id')->orWhere('c.user_id', $user->id);
            })
            ->orderBy('c.name')
            ->get(['b.category_id', 'c.name', 'b.budget_minor', 'b.currency']);

        if ($budgets->isEmpty()) {
            return [];
        }

        // The shared legs ∪ unsplit-parents read model, so a split
        // transaction's legs — not its parent row — count toward spend.
        $spendByKey = $this->spendByCategory->forUserAndPeriodByCurrency($user->id, $period);

        $rows = [];
        foreach ($budgets as $budget) {
            $categoryId = self::toInt($budget->category_id);
            $currency = self::toString($budget->currency);
            $budgetMinor = self::toInt($budget->budget_minor);
            // Keyed on the budget's own currency, so spend settled in any
            // other currency is not counted — a known limitation.
            $spentMinor = max(0, $spendByKey[$categoryId.'|'.$currency] ?? 0);

            $fraction = $budgetMinor > 0 ? $spentMinor / $budgetMinor : 0.0;
            $status = match (true) {
                $fraction > 1.0 => 'over',
                $fraction >= self::NEAR_THRESHOLD => 'near',
                default => 'under',
            };

            $rows[] = new BudgetProgressRow(
                categoryId: $categoryId,
                name: self::toString($budget->name),
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
     * @return array<int, string> category id => name
     */
    public function expenseCategories(User $user): array
    {
        $rows = $this->db->connection()->table('categories')
            ->where('kind', CategoryKind::Expense->value)
            ->where(static function (QueryBuilder $query) use ($user): void {
                $query->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        $options = [];
        foreach ($rows as $row) {
            $options[self::toInt($row->id)] = self::toString($row->name);
        }

        return $options;
    }

    // The Livewire category id is client-supplied, so every write path has to
    // call this — rendering the picker's allow-list is not itself a boundary.
    public function canBudget(User $user, int $categoryId): bool
    {
        return array_key_exists($categoryId, $this->expenseCategories($user));
    }
}
