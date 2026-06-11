<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Dto\TransactionRowDto;
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
 *
 * Phone-width infinite scroll: `$accumulatedRows` stores a flat scalar
 * array of rows accumulated across all `loadMore()` calls. Because
 * `Money` objects are not Livewire-dehydratable, each row is stored as
 * an array with scalar/primitive values. The phone card-list blade loops
 * this accumulated set; the desktop table keeps iterating `$page->rows`
 * directly. A `$appendedCursorIds` guard set prevents the same page from
 * being double-appended on component re-renders that do not advance the
 * cursor.
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

    /**
     * Flat array of accumulated phone-row data across all `loadMore()` calls.
     * Each element is a scalar array so Livewire can dehydrate the state:
     *
     *   [
     *     'id'               => int,
     *     'bookedAt'         => string   (d-m-Y),
     *     'counterpartyName' => ?string,
     *     'counterpartySlug' => ?string,
     *     'categoryId'       => ?int,
     *     'amountMinor'      => int,
     *     'amountCurrency'   => string,
     *     'secondaryMinor'   => ?int,
     *     'secondaryCurrency'=> ?string,
     *   ]
     *
     * The blade reconstructs `Money` objects from the `*Minor` / `*Currency`
     * pairs at render time so the phone card formatting matches the desktop
     * table (same `$fmt` closure).
     *
     * @var array<int, array{id: int, bookedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string}>
     */
    public array $accumulatedRows = [];

    /**
     * The `hasMore` flag and next-cursor pair from the most-recently rendered
     * page. These are exposed as public properties so the Livewire test
     * harness (and the blade sentinel) can read the next page's cursor
     * without having to parse the rendered HTML.
     */
    public bool $hasMore = false;

    public ?int $nextCursorId = null;

    public ?string $nextCursorPostedAt = null;

    /**
     * Tracks which cursor ids have already been appended to `$accumulatedRows`.
     * The sentinel value `0` represents the "first page" (no cursor set).
     * This prevents a Livewire re-render that does NOT advance the cursor
     * from appending the same page twice.
     *
     * @var array<int, true>
     */
    protected array $appendedCursorIds = [];

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
        $this->accumulatedRows = [];
        $this->appendedCursorIds = [];
        $this->hasMore = false;
        $this->nextCursorId = null;
        $this->nextCursorPostedAt = null;
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
        DatabaseManager $db,
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

        // Accumulate phone-row data.
        // The guard key is the cursor that produced this page:
        //   - cursorId === null → first page (guard key 0)
        //   - cursorId !== null → subsequent page (guard key = cursorId)
        // If the current cursor has already been appended (re-render without
        // loadMore advance), skip the append so rows are never duplicated.
        // If the cursor is null (first page / reset), start fresh.
        $guardKey = $this->cursorId ?? 0;

        if ($guardKey === 0) {
            // First page or reset: replace accumulated rows with this page only.
            $this->accumulatedRows = array_map(
                static fn (TransactionRowDto $row): array => self::rowToArray($row),
                $page->rows,
            );
            $this->appendedCursorIds = [0 => true];
        } elseif (! isset($this->appendedCursorIds[$guardKey])) {
            // New cursor page: append without duplicating.
            foreach ($page->rows as $row) {
                $this->accumulatedRows[] = self::rowToArray($row);
            }
            $this->appendedCursorIds[$guardKey] = true;
        }

        // Expose the pagination state so the blade sentinel and test harness
        // can read the next-page cursor without inspecting the view data bag.
        $this->hasMore = $page->hasMore;
        $this->nextCursorId = $page->nextCursorId;
        $this->nextCursorPostedAt = $page->nextCursorPostedAt;

        // Per-row chain-presence lookup — derives an array<int, true>
        // keyed by transaction id covering EVERY row on the current
        // page. Used by the blade to render a tiny chain indicator
        // next to counterparties that are part of a confirmed or
        // candidate chain_link, so the user knows which rows are
        // worth drilling into. One UNION query per page render scoped
        // to the visible row ids; cost stays O(page size) rather than
        // O(ledger size). The lookup hits chain_links on EITHER side
        // (from_transaction_id OR to_transaction_id) because the chain
        // drawer is reachable from both legs of a pair.
        $rowIds = array_map(static fn ($row): int => $row->id, $page->rows);
        $chainTxIds = [];
        if ($rowIds !== []) {
            $matches = $db->connection()->table('chain_links')
                ->where('user_id', $user->id)
                ->whereIn('state', ['confirmed', 'candidate'])
                ->where(function (Builder $q) use ($rowIds): void {
                    $q->whereIn('from_transaction_id', $rowIds)
                        ->orWhereIn('to_transaction_id', $rowIds);
                })
                ->select(['from_transaction_id', 'to_transaction_id'])
                ->get();
            foreach ($matches as $m) {
                $fromId = is_numeric($m->from_transaction_id) ? (int) $m->from_transaction_id : 0;
                $toId = is_numeric($m->to_transaction_id ?? null) ? (int) $m->to_transaction_id : 0;
                if ($fromId !== 0) {
                    $chainTxIds[$fromId] = true;
                }
                if ($toId !== 0) {
                    $chainTxIds[$toId] = true;
                }
            }
        }

        return $views->make('ledger::livewire.transactions-list', [
            'page' => $page,
            'accumulatedRows' => $this->accumulatedRows,
            'fullHistory' => $this->fullHistory,
            'currency' => $this->currency,
            'chainTxIds' => $chainTxIds,
        ]);
    }

    /**
     * Converts a TransactionRowDto to a scalar array suitable for Livewire
     * dehydration / serialisation. Money objects are stored as their minor
     * integer + currency code pair; the blade view reconstructs them at
     * render time via `Money::ofMinor()`.
     *
     * @return array{id: int, bookedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string}
     */
    private static function rowToArray(TransactionRowDto $row): array
    {
        return [
            'id' => $row->id,
            'bookedAt' => $row->bookedAt,
            'counterpartyName' => $row->counterpartyName,
            'counterpartySlug' => $row->counterpartySlug,
            'categoryId' => $row->categoryId,
            'amountMinor' => $row->amount->toMinor(),
            'amountCurrency' => $row->amount->currency(),
            'secondaryMinor' => $row->secondaryAmount?->toMinor(),
            'secondaryCurrency' => $row->secondaryAmount?->currency(),
        ];
    }
}
