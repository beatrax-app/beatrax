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
use Modules\Ledger\Public\Support\CategoryDisplayName;

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
            // A budget row can key a category the user does not own; joining that
            // row unfiltered would put a foreign category's name on the page.
            ->where(static function (QueryBuilder $query) use ($user): void {
                $query->whereNull('c.user_id')->orWhere('c.user_id', $user->id);
            })
            ->get(['b.category_id', ...CategoryDisplayName::columns('c'), 'b.budget_minor', 'b.currency']);

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
                name: CategoryDisplayName::fromRow($budget, 'category') ?? '',
                budgetMinor: $budgetMinor,
                spentMinor: $spentMinor,
                currency: $currency,
                fractionUsed: $fraction,
                status: $status,
            );
        }

        // Alphabetical by what the reader sees, not by what is stored — the
        // stored English orders a Dutch budget screen by the wrong word.
        usort($rows, static fn (BudgetProgressRow $a, BudgetProgressRow $b): int => strcasecmp($a->name, $b->name)
            ?: $a->categoryId <=> $b->categoryId);

        return $rows;
    }

    /**
     * @return array<int, string> category id => name
     */
    public function expenseCategories(User $user): array
    {
        $options = [];
        foreach ($this->expenseCategoryNaming($user) as $categoryId => $naming) {
            $options[$categoryId] = $naming['name'];
        }

        return $options;
    }

    // Name AND the provenance behind it. A nudge fires from an hourly job with
    // no reader in sight, so the category has to travel as what it is rather
    // than as a name already resolved into the worker's language.
    /**
     * @return array<int, array{name: string, slug: string, isDefault: bool}>
     */
    public function expenseCategoryNaming(User $user): array
    {
        $rows = $this->db->connection()->table('categories')
            ->where('kind', CategoryKind::Expense->value)
            ->where(static function (QueryBuilder $query) use ($user): void {
                $query->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->get(['id', 'name', 'slug', 'name_is_default']);

        $naming = [];
        foreach ($rows as $row) {
            $naming[self::toInt($row->id)] = [
                'name' => CategoryDisplayName::fromRow($row) ?? '',
                'slug' => self::toString($row->slug),
                'isDefault' => CategoryDisplayName::isDefaultRow($row),
            ];
        }

        // Alphabetical by the resolved name, which is the order every caller
        // of this and of expenseCategories() renders in.
        uksort($naming, static fn (int $a, int $b): int => strnatcasecmp($naming[$a]['name'], $naming[$b]['name']) ?: $a <=> $b);

        return $naming;
    }

    // The Livewire category id is client-supplied, so every write path has to
    // call this — rendering the picker's allow-list is not itself a boundary.
    public function canBudget(User $user, int $categoryId): bool
    {
        return array_key_exists($categoryId, $this->expenseCategories($user));
    }
}
