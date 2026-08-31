<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\LocaleCollator;
use Modules\Ledger\Public\Enums\CategoryKind;
use Modules\Ledger\Public\Services\CategoryAncestry;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Ledger\Public\Support\CategoryPathName;
use stdClass;

final readonly class BudgetProgressQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private CategoryAncestry $ancestry,
    ) {}

    // The path, not the leaf: two groups can each hold a "Groceries", and a
    // picker offering both of them the same label picks for the reader.
    /**
     * @return array<int, string> category id => qualified name
     */
    public function expenseCategories(User $user): array
    {
        $options = [];
        foreach ($this->expenseCategoryNaming($user) as $categoryId => $naming) {
            $options[$categoryId] = $naming['path'];
        }

        return $options;
    }

    // Name AND the provenance behind it. A nudge fires from an hourly job with
    // no reader in sight, so the category has to travel as what it is rather
    // than as a name already resolved into the worker's language — which is why
    // `name` stays the leaf even though every screen renders `path`.
    /**
     * @return array<int, array{name: string, path: string, slug: string, isDefault: bool}>
     */
    public function expenseCategoryNaming(User $user): array
    {
        $rows = $this->db->connection()->table('categories')
            ->where(static function (QueryBuilder $query) use ($user): void {
                $query->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->get(['id', 'kind', ...CategoryDisplayName::bareColumns()]);

        $ids = array_values(array_map(static fn (stdClass $row): int => self::toInt($row->id), $rows->all()));
        $ancestors = $this->ancestry->load($ids, $user->id);

        // The whole visible tree walks in, not just the expense half: the
        // ordinal separating two identical paths counts from the lowest id of
        // ALL of them, so an income category sharing an expense one's path
        // would otherwise number this grid differently from every picker.
        $paths = [];
        foreach ($rows as $row) {
            $paths[self::toInt($row->id)] = $this->ancestry->fullPath(self::toInt($row->id), $ancestors);
        }
        $paths = CategoryPathName::distinct($paths);

        $naming = [];
        foreach ($rows as $row) {
            if (self::toString($row->kind) !== CategoryKind::Expense->value) {
                continue;
            }

            $id = self::toInt($row->id);
            $naming[$id] = [
                'name' => CategoryDisplayName::fromRow($row) ?? '',
                'path' => $paths[$id],
                'slug' => self::toString($row->slug),
                'isDefault' => CategoryDisplayName::isDefaultRow($row),
            ];
        }

        // Alphabetical by the path, not the leaf, which is the order every
        // caller of this and of expenseCategories() renders in. On the leaf,
        // "Other" sorted between "Music" and "Personal care" with nothing on
        // the row saying it was the insurance one.
        uksort($naming, static function (int $a, int $b) use ($naming): int {
            $byPath = LocaleCollator::compare($naming[$a]['path'], $naming[$b]['path']);

            return $byPath !== 0 ? $byPath : $a <=> $b;
        });

        return $naming;
    }

    // The Livewire category id is client-supplied, so every write path has to
    // call this — rendering the picker's allow-list is not itself a boundary.
    // One scoped exists(), not a membership test against the whole read model:
    // "copy last month" asks this per row and paid for a full load each time.
    public function canBudget(User $user, int $categoryId): bool
    {
        return $this->db->connection()->table('categories')
            ->where('id', $categoryId)
            ->where('kind', CategoryKind::Expense->value)
            ->where(static function (QueryBuilder $query) use ($user): void {
                $query->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->exists();
    }
}
