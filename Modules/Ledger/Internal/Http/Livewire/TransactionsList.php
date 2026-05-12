<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\TransactionListQuery;

/**
 * The `/transactions` list page. Defaults to a 90-day recent window;
 * a "Show full history" toggle widens the query to every persisted row.
 *
 * Pagination is cursor-based via `TransactionListQuery`. The cursor is a
 * `(posted_at, id)` pair — pressing "Load more" hands both back into the
 * query so rows sharing a `posted_at` value never silently drop between
 * pages.
 *
 * The query service is injected as a parameter on `render()` (Livewire
 * Component subclasses can't accept constructor injection under the
 * project's strict-rules ruleset).
 */
final class TransactionsList extends Component
{
    public bool $fullHistory = false;

    public ?int $cursorId = null;

    public ?string $cursorPostedAt = null;

    public function toggleFullHistory(): void
    {
        $this->fullHistory = ! $this->fullHistory;
        $this->cursorId = null;
        $this->cursorPostedAt = null;
    }

    public function loadMore(int $nextCursorId, ?string $nextCursorPostedAt = null): void
    {
        $this->cursorId = $nextCursorId;
        $this->cursorPostedAt = $nextCursorPostedAt;
    }

    public function reset_(): void
    {
        $this->cursorId = null;
        $this->cursorPostedAt = null;
    }

    public function render(
        CurrentUser $currentUser,
        TransactionListQuery $listQuery,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();

        $page = $this->fullHistory
            ? $listQuery->fullHistory($user, cursorId: $this->cursorId, cursorPostedAt: $this->cursorPostedAt)
            : $listQuery->recent($user, daysBack: 90, cursorId: $this->cursorId, cursorPostedAt: $this->cursorPostedAt);

        return $views->make('ledger::livewire.transactions-list', [
            'page' => $page,
            'fullHistory' => $this->fullHistory,
        ]);
    }
}
