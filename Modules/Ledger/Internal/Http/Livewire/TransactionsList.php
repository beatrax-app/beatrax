<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
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
 * The `$currency` property is URL-bound (refresh-stable, browser back /
 * forward respects it) and falls back to the user's `default_currency_view`
 * preference when no `?currency=` query parameter is present. Two values
 * are accepted: `'eur'` projects the settled-EUR pair (one line per row);
 * `'original'` projects the native pair with a settled-EUR secondary line
 * on foreign-currency rows. The `except: ''` modifier on the Url attribute
 * keeps the URL clean when the property is left on its empty default.
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

    /**
     * Currency view mode. The empty string is the "no preference applied
     * yet" sentinel; `mount()` resolves it to the user's stored default
     * before the first render. The `#[Url(except: '')]` modifier keeps
     * the URL clean when the property is on that sentinel.
     */
    #[Url(as: 'currency', except: '')]
    public string $currency = '';

    public function mount(CurrentUser $currentUser): void
    {
        if ($this->currency === '') {
            $pref = $currentUser->user()->default_currency_view;
            $this->currency = $pref === 'eur_only' ? 'eur' : 'original';
        }
    }

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

    public function render(
        CurrentUser $currentUser,
        TransactionListQuery $listQuery,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();

        // Map the wire-property to the query's currency argument.
        //   'eur'      → 'EUR'  (settled-EUR projection, one line per row)
        //   'original' → null   (native projection + secondary line on FX)
        //   '' (defensive) → null
        // Any other value silently maps to null too, which renders the
        // native projection — `?currency=garbage` therefore never reaches
        // the SQL filter and cannot produce an empty page from an
        // unrecognised value.
        $queryCurrency = $this->currency === 'eur' ? 'EUR' : null;

        $page = $this->fullHistory
            ? $listQuery->fullHistory($user, cursorId: $this->cursorId, cursorPostedAt: $this->cursorPostedAt, currency: $queryCurrency)
            : $listQuery->recent($user, daysBack: 90, cursorId: $this->cursorId, cursorPostedAt: $this->cursorPostedAt, currency: $queryCurrency);

        return $views->make('ledger::livewire.transactions-list', [
            'page' => $page,
            'fullHistory' => $this->fullHistory,
            'currency' => $this->currency,
        ]);
    }
}
