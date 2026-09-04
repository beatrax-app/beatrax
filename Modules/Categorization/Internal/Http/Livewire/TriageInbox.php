<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Categorization\Public\Services\UncategorizedTriageQuery;
use Modules\Community\Public\Services\CommunitySettings;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Ledger\Public\Support\SplitLegs;

// The cursor is a (posted_at, id) pair so rows sharing a posted_at value
// never silently drop between pages.
final class TriageInbox extends Component
{
    private const int QUICK_ASSIGN_CHIPS_PER_ROW = 9;

    // Written only by selectForRow(), which types both halves, and read back
    // as the two arguments AssignsCategory declares. Nothing binds it, so the
    // pair is refused at the boundary rather than narrowed on the way out.
    /** @var array<int, ?int> map of transactionId => pending categoryId */
    #[Locked]
    public array $pending = [];

    public ?int $cursorId = null;

    public ?string $cursorPostedAt = null;

    public function selectForRow(int|string $transactionId, ?int $categoryId): void
    {
        $this->pending[DerivedRowId::fromWire($transactionId)] = $categoryId;
    }

    // Empty body: the listener existing is what makes Livewire render again
    // and pick up the new community_settings value.
    #[On('shared-list-settings:saved')]
    public function refreshSettings(): void {}

    public function clearPending(): void
    {
        $this->pending = [];
    }

    public function loadMore(int|string $nextCursorId, ?string $nextCursorPostedAt = null): void
    {
        $this->cursorId = DerivedRowId::fromWire($nextCursorId);
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
        CommunitySettings $community,
    ): View {
        $user = $currentUser->user();
        $batch = $triage->for(
            $user,
            cursorId: $this->cursorId,
            cursorPostedAt: $this->cursorPostedAt,
        );

        $totalPending = SplitLegs::excludeParents(
            $db->connection()->table('transactions')
                ->where('user_id', $user->id)
                ->whereNull('category_id')
        )->count();

        $categories = $options->for($user);
        $topCategories = array_slice($categories, 0, self::QUICK_ASSIGN_CHIPS_PER_ROW);

        return $views->make('categorization::livewire.triage-inbox', [
            'batch' => $batch,
            'categories' => $categories,
            'topCategories' => $topCategories,
            'totalPending' => $totalPending,
            'offerToContribute' => $community->offersToContribute($user->id),
        ]);
    }
}
