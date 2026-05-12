<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * Drops into each row of the `/transactions` list (and any other surface
 * that needs in-place categorization). The Blade view renders a
 * `<select wire:model.live="categoryId">`; the Livewire `updatedCategoryId`
 * hook fires the AssignsCategory action through the public contract so
 * Ledger remains the only mutator of `transactions.category_id`.
 *
 * Constructor-free Livewire component; services arrive as parameters on
 * the relevant action / render methods.
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

    public function render(CurrentUser $currentUser, CategoryOptionsQuery $options, ViewFactory $views): View
    {
        return $views->make('categorization::livewire.inline-category-picker', [
            'categories' => $options->for($currentUser->user()),
        ]);
    }
}
