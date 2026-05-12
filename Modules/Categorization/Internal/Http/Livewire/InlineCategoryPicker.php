<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Dto\CategoryOption;
use Modules\Core\Public\Contracts\CurrentUser;
use stdClass;

/**
 * Drops into each row of the `/transactions` list (and any other surface
 * that needs in-place categorization). The Blade view renders a
 * `<select wire:model.live="categoryId">`; the Livewire `updatedCategoryId`
 * hook fires the AssignsCategory action through the public contract so
 * Ledger remains the only mutator of `transactions.category_id`.
 *
 * Constructor-free per the project's Livewire convention; services arrive
 * as parameters on the relevant action / render methods.
 */
final class InlineCategoryPicker extends Component
{
    public int $transactionId = 0;

    public ?int $categoryId = null;

    public function mount(int $transactionId, ?int $categoryId): void
    {
        $this->transactionId = $transactionId;
        $this->categoryId = $categoryId;
    }

    public function updatedCategoryId(CurrentUser $currentUser, AssignsCategory $assign): void
    {
        $value = $this->categoryId === 0 ? null : $this->categoryId;
        $assign($this->transactionId, $value, $currentUser->user());
    }

    public function render(DatabaseManager $db, ViewFactory $views): View
    {
        return $views->make('categorization::livewire.inline-category-picker', [
            'categories' => $this->loadCategoryOptions($db),
        ]);
    }

    /**
     * @return array<int, CategoryOption>
     */
    private function loadCategoryOptions(DatabaseManager $db): array
    {
        $rows = $db->connection()
            ->table('categories as c')
            ->leftJoin('categories as p', 'c.parent_id', '=', 'p.id')
            ->orderBy('c.display_order')
            ->select([
                'c.id',
                'c.name',
                'c.display_order',
                'p.name as parent_name',
            ])
            ->get();

        $options = [];
        foreach ($rows as $row) {
            $options[] = $this->mapOption($row);
        }

        return $options;
    }

    private function mapOption(stdClass $row): CategoryOption
    {
        $name = self::toString($row->name);
        $parent = $row->parent_name === null ? null : self::toString($row->parent_name);
        $path = $parent === null ? $name : $parent.' / '.$name;

        return new CategoryOption(
            id: self::toInt($row->id),
            path: $path,
            displayOrder: self::toInt($row->display_order),
        );
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
