<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Categorization\Public\Services\UncategorizedTriageQuery;
use Modules\Core\Public\Contracts\CurrentUser;

// Users stage assignments (selectForRow) and commit them all at once via
// `save`. The cursor is a (posted_at, id) pair so rows sharing a
// posted_at value never silently drop between pages. The header count is
// read directly from `transactions` rather than the on-page row count.
final class TriageInbox extends Component
{
    // The quick-assign row shows at most this many categories as one-tap
    // chips; the fixed cap keeps the row to a single line in the triage grid.
    private const int QUICK_ASSIGN_CATEGORY_LIMIT = 9;

    /** @var array<int, ?int> map of transactionId => pending categoryId */
    public array $pending = [];

    public ?int $cursorId = null;

    public ?string $cursorPostedAt = null;

    public function selectForRow(int $transactionId, ?int $categoryId): void
    {
        $this->pending[$transactionId] = $categoryId;
    }

    // Empty body — the re-render is the side effect; the listener
    // existing on the component is what triggers Livewire to walk
    // render() again, picking up the new community_settings value.
    #[On('shared-list-settings:saved')]
    public function refreshSettings(): void {}

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
        $topCategories = array_slice($categories, 0, self::QUICK_ASSIGN_CATEGORY_LIMIT);

        // Default is true so a user who never opens the Settings panel
        // still sees the per-row CTA.
        $settings = is_array($user->community_settings) ? $user->community_settings : [];
        $offerToContribute = array_key_exists('offerToContribute', $settings)
            ? (bool) $settings['offerToContribute']
            : true;

        return $views->make('categorization::livewire.triage-inbox', [
            'batch' => $batch,
            'categories' => $categories,
            'topCategories' => $topCategories,
            'totalPending' => $totalPending,
            'offerToContribute' => $offerToContribute,
        ]);
    }
}
