<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\TransactionListQuery;

/**
 * The `/transactions` list page. Defaults to the UI-04 recent window
 * (last 90 days); a "Show full history" toggle widens the query to
 * every persisted row. Pagination is cursor-based via
 * `TransactionListQuery` — pressing "Load more" hands the page's
 * `nextCursorId` back into the query.
 *
 * Per Plan 05's Livewire convention, the query service is injected as
 * a parameter on `render()` (no boot()-based property injection).
 */
final class TransactionsList extends Component
{
    public bool $fullHistory = false;

    public ?int $cursorId = null;

    public function toggleFullHistory(): void
    {
        $this->fullHistory = ! $this->fullHistory;
        $this->cursorId = null;
    }

    public function loadMore(int $nextCursor): void
    {
        $this->cursorId = $nextCursor;
    }

    public function reset_(): void
    {
        $this->cursorId = null;
    }

    public function render(
        CurrentUser $currentUser,
        TransactionListQuery $listQuery,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();

        $page = $this->fullHistory
            ? $listQuery->fullHistory($user, cursorId: $this->cursorId)
            : $listQuery->recent($user, daysBack: 90, cursorId: $this->cursorId);

        return $views->make('ledger::livewire.transactions-list', [
            'page' => $page,
            'fullHistory' => $this->fullHistory,
        ]);
    }
}
