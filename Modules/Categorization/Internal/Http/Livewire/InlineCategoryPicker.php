<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Services\TransactionStatusQuery;

// Going through the AssignsCategory contract is what keeps Ledger the only
// mutator of category_id.
final class InlineCategoryPicker extends Component
{
    use DispatchesToast;

    public int $transactionId = 0;

    public ?int $categoryId = null;

    public function mount(int $transactionId, ?int $categoryId): void
    {
        $this->transactionId = $transactionId;
        $this->categoryId = $categoryId;
    }

    public function updatedCategoryId(
        CurrentUser $currentUser,
        AssignsCategory $assign,
        TransactionStatusQuery $status,
        DatabaseManager $db,
    ): void {
        $user = $currentUser->user();

        // Warn-first, no write. The <select> is wire:model.live-bound, so
        // Livewire has already flipped $categoryId; reverting to the persisted
        // value stops the row showing a phantom unsaved selection.
        if ($status->isReconciled($user->id, $this->transactionId)) {
            $this->toast(Lang::get('categorization::detail.reconciled_toast'));

            $persisted = $db->connection()
                ->table('transactions')
                ->where('id', $this->transactionId)
                ->where('user_id', $user->id)
                ->value('category_id');
            $this->categoryId = is_numeric($persisted) ? (int) $persisted : null;

            return;
        }

        $value = $this->categoryId === 0 ? null : $this->categoryId;
        $assign($this->transactionId, $value, $user);
    }

    public function render(CurrentUser $currentUser, CategoryOptionsQuery $options, ViewFactory $views): View
    {
        return $views->make('categorization::livewire.inline-category-picker', [
            'categories' => $options->for($currentUser->user()),
        ]);
    }
}
