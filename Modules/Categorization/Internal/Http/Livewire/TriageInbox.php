<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Categorization\Public\Services\UncategorizedTriageQuery;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * `/uncategorized` triage inbox. Renders a cursor-paginated batch of
 * uncategorized transactions and a keyboard-driven category picker. Users
 * can stage assignments (`selectForRow`) and commit them all at once via
 * the `Save categories` button (`save`); `clearPending` resets the staged
 * state (Escape key).
 *
 * Every collaborator arrives as a parameter on `render()` / action methods
 * — no `boot()` injection (the strict-rules ruleset bans property-based
 * constructor injection on Livewire components).
 *
 * The keymap `1`–`9` is wired in the Blade view via Alpine.js; the
 * action layer here just owns the staging map.
 */
final class TriageInbox extends Component
{
    /** @var array<int, ?int> map of transactionId => pending categoryId */
    public array $pending = [];

    public function selectForRow(int $transactionId, ?int $categoryId): void
    {
        $this->pending[$transactionId] = $categoryId;
    }

    public function clearPending(): void
    {
        $this->pending = [];
    }

    public function save(CurrentUser $currentUser, AssignsCategory $assign): void
    {
        $user = $currentUser->user();
        foreach ($this->pending as $transactionId => $categoryId) {
            $assign($transactionId, $categoryId, $user);
        }
        $this->pending = [];
    }

    public function render(
        CurrentUser $currentUser,
        UncategorizedTriageQuery $triage,
        CategoryOptionsQuery $options,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $batch = $triage->for($user);

        $categories = $options->for($user);
        $topNine = array_slice($categories, 0, 9);

        return $views->make('categorization::livewire.triage-inbox', [
            'batch' => $batch,
            'categories' => $categories,
            'topNine' => $topNine,
        ]);
    }
}
