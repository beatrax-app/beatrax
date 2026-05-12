<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
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
 * Pagination is cursor-based via `UncategorizedTriageQuery`. The cursor is
 * a `(posted_at, id)` pair — pressing "Load more" hands both back into the
 * query so rows sharing a `posted_at` value never silently drop between
 * pages. The header copy reports the true backlog size (counted directly
 * from the `transactions` table) rather than the on-page row count, so a
 * user with 200 uncategorized transactions sees "200 pending" instead of
 * "50 pending".
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

    public ?int $cursorId = null;

    public ?string $cursorPostedAt = null;

    public function selectForRow(int $transactionId, ?int $categoryId): void
    {
        $this->pending[$transactionId] = $categoryId;
    }

    public function clearPending(): void
    {
        $this->pending = [];
    }

    public function loadMore(int $nextCursorId, ?string $nextCursorPostedAt = null): void
    {
        $this->cursorId = $nextCursorId;
        $this->cursorPostedAt = $nextCursorPostedAt;
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
        DatabaseManager $db,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $batch = $triage->for(
            $user,
            cursorId: $this->cursorId,
            cursorPostedAt: $this->cursorPostedAt,
        );

        $totalPending = $db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->count();

        $categories = $options->for($user);
        $topNine = array_slice($categories, 0, 9);

        return $views->make('categorization::livewire.triage-inbox', [
            'batch' => $batch,
            'categories' => $categories,
            'topNine' => $topNine,
            'totalPending' => $totalPending,
        ]);
    }
}
