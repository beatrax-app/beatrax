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
use Modules\Ledger\Public\Http\Livewire\Concerns\HandlesClearedStatus;
use Modules\Ledger\Public\Services\TransactionListQuery;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Dto\SearchRowDto;
use Modules\Search\Public\Services\SearchQuery;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;
use Modules\Tax\Public\Services\TaxTagQuery;

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
 *
 * Search mode (Phase 8): when `isSearchActive()` returns true, `render()`
 * branches to `SearchQuery::search()` and returns `SearchResultPage` data.
 * Highlight/snippet data from SearchRowDto is NOT stored in $accumulatedRows
 * to avoid Livewire snapshot bloat (Pitfall-4). Summary strip data
 * (totalCount / totalOutMinor / totalInMinor) and `didYouMean` are passed
 * to the view only in search mode. Clearing search restores the prior
 * $fullHistory toggle state.
 */
final class TransactionsList extends Component
{
    use HandlesClearedStatus;
    use HandlesTaxTagging;

    /**
     * Maximum number of rows kept in the Livewire dehydrated snapshot.
     *
     * At ~200–400 bytes JSON-encoded per row, 500 rows ≈ 200 KB — well
     * below Livewire 4's 4 MB snapshot limit. When exceeded, the oldest
     * rows are trimmed from the front and the `$appendedCursorIds` guard
     * is reset to only the guard key for the new tail row, preventing
     * the trimmed pages from being appended again.
     *
     * Without this cap, a user scrolling through years of full-history
     * data accumulates 5 000+ rows in the snapshot, eventually hitting
     * the payload limit and corrupting the component state.
     */
    private const MAX_ACCUMULATED_ROWS = 500;

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

    // ─── Search-mode URL-bound properties (Phase 8) ──────────────────────────

    /** Full-text search query string. Bound to ?q= in the URL. */
    #[Url(as: 'q', except: '')]
    public string $searchQuery = '';

    /**
     * Account IDs to restrict results to. Bound to ?account[]= in the URL.
     *
     * @var list<int>
     */
    #[Url(as: 'account', except: [])]
    public array $filterAccounts = [];

    /**
     * Category IDs to restrict results to. Bound to ?category[]= in the URL.
     *
     * @var list<int>
     */
    #[Url(as: 'category', except: [])]
    public array $filterCategories = [];

    /** ISO date string for the lower bound of the date range filter. */
    #[Url(as: 'after', except: '')]
    public string $filterAfter = '';

    /** ISO date string for the upper bound of the date range filter. */
    #[Url(as: 'before', except: '')]
    public string $filterBefore = '';

    /** Minimum absolute amount as a decimal string (e.g. "10.00"). */
    #[Url(as: 'amount_min', except: '')]
    public string $filterAmountMin = '';

    /** Maximum absolute amount as a decimal string (e.g. "500.00"). */
    #[Url(as: 'amount_max', except: '')]
    public string $filterAmountMax = '';

    /** Amount direction: 'in', 'out', or 'both'. */
    #[Url(as: 'amount_dir', except: 'both')]
    public string $filterAmountDir = 'both';

    /**
     * Stores the pre-search $fullHistory state so clearSearch() can restore
     * it exactly. Null means a search session has not yet started.
     */
    public ?bool $preSearchFullHistory = null;

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
     * Note: highlightedCounterparty and snippet from SearchRowDto are NOT
     * stored here (Pitfall-4 — snapshot bloat). They are re-computed from
     * SearchQuery on each render in search mode.
     *
     * Phase 13.1 Plan 06 adds `splitLegs`: the batch-loaded list of split
     * legs for this row (empty for an unsplit row, >= 2 entries for a split
     * parent — split detection is leg-row presence, never category_id
     * nullity, per D-11). Populated after accumulation, mirroring the
     * taxTagged/taxCategoryShortName merge below.
     *
     * Phase 13.3 Plan 03 adds `status`: the batch-loaded cleared/uncleared/
     * reconciled value for this row (SC-1, D-11), merged in both render
     * branches alongside taxTagged/splitLegs — never per-row (Pitfall 1).
     *
     * @var list<array{id: int, bookedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged?: bool, taxCategoryShortName?: ?string, splitLegs?: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>, status?: string}>
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
     * Must be `public` so Livewire dehydrates it into the encrypted snapshot
     * and rehydrates it on every round-trip. A `protected` property is
     * re-initialised to `[]` on every request, making the duplicate guard
     * ineffective in real browser sessions.
     *
     * @var array<int, true>
     */
    public array $appendedCursorIds = [];

    public function mount(CurrentUser $currentUser): void
    {
        if ($this->currency === '') {
            $pref = $currentUser->user()->default_currency_view;
            $this->currency = $pref === 'eur_only' ? 'eur' : 'original';
        }
    }

    /**
     * Returns true when any search-mode property is non-default.
     * When true, `render()` branches to SearchQuery::search() and all history
     * is searched regardless of the $fullHistory toggle.
     */
    public function isSearchActive(): bool
    {
        return $this->searchQuery !== ''
            || $this->filterAccounts !== []
            || $this->filterCategories !== []
            || $this->filterAfter !== ''
            || $this->filterBefore !== ''
            || $this->filterAmountMin !== ''
            || $this->filterAmountMax !== ''
            || $this->filterAmountDir !== 'both';
    }

    /**
     * Reset all search-mode properties and cursor state.
     *
     * Mirrors toggleFullHistory() for cursor/accumulation state, and restores
     * $fullHistory to its value before the search session started (so the user
     * returns to the same view mode they were in before typing).
     */
    public function clearSearch(): void
    {
        $this->searchQuery = '';
        $this->filterAccounts = [];
        $this->filterCategories = [];
        $this->filterAfter = '';
        $this->filterBefore = '';
        $this->filterAmountMin = '';
        $this->filterAmountMax = '';
        $this->filterAmountDir = 'both';

        // Restore the pre-search fullHistory toggle state
        if ($this->preSearchFullHistory !== null) {
            $this->fullHistory = $this->preSearchFullHistory;
            $this->preSearchFullHistory = null;
        }

        // Reset cursor/accumulation state (same as toggleFullHistory)
        $this->cursorId = null;
        $this->cursorPostedAt = null;
        $this->accumulatedRows = [];
        $this->appendedCursorIds = [];
        $this->hasMore = false;
        $this->nextCursorId = null;
        $this->nextCursorPostedAt = null;
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

    /**
     * Advance to the next cursor page.
     *
     * Reads `$this->nextCursorId` and `$this->nextCursorPostedAt` from the
     * server-side Livewire snapshot rather than accepting them as browser-
     * supplied parameters. This prevents a caller from forging a cursor value
     * to skip rows or submit an unvalidated page offset. The snapshot values
     * are set by the previous `render()` and are encrypted / HMAC-verified
     * by Livewire before hydration — they cannot be tampered with by the
     * browser.
     */
    public function loadMore(): void
    {
        $this->cursorId = $this->nextCursorId;
        $this->cursorPostedAt = $this->nextCursorPostedAt;
    }

    public function render(
        CurrentUser $currentUser,
        TransactionListQuery $listQuery,
        ViewFactory $views,
        DatabaseManager $db,
        TaxTagQuery $taxTagQuery,
        SearchQuery $searchQuery,
    ): View {
        $user = $currentUser->user();

        // ─── Search mode branch ──────────────────────────────────────────────
        if ($this->isSearchActive()) {
            // Store pre-search fullHistory state on the first render in search mode
            if ($this->preSearchFullHistory === null) {
                $this->preSearchFullHistory = $this->fullHistory;
            }

            // Build SearchFilters from URL-bound props
            $filters = new SearchFilters(
                accounts: array_values(array_filter(
                    $this->filterAccounts,
                    static fn (int $id): bool => $id > 0,
                )),
                categories: array_values(array_filter(
                    $this->filterCategories,
                    static fn (int $id): bool => $id > 0,
                )),
                after: $this->filterAfter !== '' ? $this->filterAfter : null,
                before: $this->filterBefore !== '' ? $this->filterBefore : null,
                amountMin: $this->filterAmountMin !== '' ? $this->filterAmountMin : null,
                amountMax: $this->filterAmountMax !== '' ? $this->filterAmountMax : null,
                amountDirection: $this->filterAmountDir,
            );

            $searchPage = $searchQuery->search(
                $user,
                $this->searchQuery,
                $filters,
                $this->cursorId,
                $this->cursorPostedAt,
            );

            // Accumulate search result rows using the same guard pattern
            // NOTE: highlightedCounterparty + snippet are NOT stored in
            // $accumulatedRows (Pitfall-4 — they would bloat the snapshot and
            // go stale across renders). They are re-fetched from SearchQuery
            // on each render.
            $guardKey = $this->cursorId ?? 0;

            if ($guardKey === 0) {
                $this->accumulatedRows = array_map(
                    static fn (SearchRowDto $row): array => self::searchRowToArray($row),
                    $searchPage->rows,
                );
                $this->appendedCursorIds = [0 => true];
            } elseif (! isset($this->appendedCursorIds[$guardKey])) {
                foreach ($searchPage->rows as $row) {
                    $this->accumulatedRows[] = self::searchRowToArray($row);
                }
                $this->appendedCursorIds[$guardKey] = true;
            }

            if (count($this->accumulatedRows) > self::MAX_ACCUMULATED_ROWS) {
                $this->accumulatedRows = array_slice($this->accumulatedRows, -self::MAX_ACCUMULATED_ROWS);
                $this->appendedCursorIds = [$guardKey => true];
            }

            $this->hasMore = $searchPage->hasMore;
            $this->nextCursorId = $searchPage->nextCursorId;
            $this->nextCursorPostedAt = $searchPage->nextCursorPostedAt;

            // Batch-load tax tag state for search rows (D-20)
            $rowIds = array_map(static fn (SearchRowDto $row): int => $row->id, $searchPage->rows);
            $accIds = array_map(static fn (array $r): int => $r['id'], $this->accumulatedRows);
            $stateIds = array_values(array_unique([...$rowIds, ...$accIds]));
            $taxState = $this->taxTagStateFor($stateIds, $taxTagQuery, $currentUser);
            $splitLegs = $this->legsFor($stateIds, $db, $taxTagQuery, $user->id);
            $clearedState = $this->clearedStatusFor($stateIds, $db, $currentUser);

            foreach ($this->accumulatedRows as &$accRow) {
                $accRowId = $accRow['id'];
                $accRow['taxTagged'] = $taxState[$accRowId]['taxTagged'] ?? false;
                $accRow['taxCategoryShortName'] = $taxState[$accRowId]['taxCategoryShortName'] ?? null;
                $accRow['splitLegs'] = $splitLegs[$accRowId] ?? [];
                $accRow['status'] = $clearedState[$accRowId] ?? 'cleared';
            }
            unset($accRow);

            // Build highlight map for current page (re-computed per render — Pitfall-4)
            // Maps transaction id → SearchRowDto for highlight/snippet access in Blade.
            $searchRows = [];
            foreach ($searchPage->rows as $row) {
                $searchRows[$row->id] = $row;
            }

            return $views->make('ledger::livewire.transactions-list', [
                'page' => $searchPage,
                'accumulatedRows' => $this->accumulatedRows,
                'fullHistory' => $this->fullHistory,
                'currency' => $this->currency,
                'chainTxIds' => [],
                'taxState' => $taxState,
                'splitLegs' => $splitLegs,
                'clearedState' => $clearedState,
                'isSearchMode' => true,
                'searchQuery' => $this->searchQuery,
                'searchTotalCount' => $searchPage->totalCount,
                'searchTotalOut' => $searchPage->totalOutMinor,
                'searchTotalIn' => $searchPage->totalInMinor,
                'didYouMean' => $searchPage->didYouMean,
                'searchRows' => $searchRows,
                'activeFilterCount' => $this->activeFilterCount(),
                'availableAccounts' => $this->availableAccounts($db, $user->id),
                'availableCategories' => $this->availableCategories($db, $user->id),
            ]);
        }

        // ─── Default (non-search) branch — byte-for-byte identical to before ─
        $queryCurrency = $this->currency === 'eur' ? 'EUR' : null;

        $page = $this->fullHistory
            ? $listQuery->fullHistory($user, cursorId: $this->cursorId, cursorPostedAt: $this->cursorPostedAt, currency: $queryCurrency)
            : $listQuery->recent($user, daysBack: 90, cursorId: $this->cursorId, cursorPostedAt: $this->cursorPostedAt, currency: $queryCurrency);

        $guardKey = $this->cursorId ?? 0;

        if ($guardKey === 0) {
            $this->accumulatedRows = array_values(array_map(
                static fn (TransactionRowDto $row): array => self::rowToArray($row),
                $page->rows,
            ));
            $this->appendedCursorIds = [0 => true];
        } elseif (! isset($this->appendedCursorIds[$guardKey])) {
            foreach ($page->rows as $row) {
                $this->accumulatedRows[] = self::rowToArray($row);
            }
            $this->appendedCursorIds[$guardKey] = true;
        }

        if (count($this->accumulatedRows) > self::MAX_ACCUMULATED_ROWS) {
            $this->accumulatedRows = array_slice($this->accumulatedRows, -self::MAX_ACCUMULATED_ROWS);
            $this->appendedCursorIds = [$guardKey => true];
        }

        $this->hasMore = $page->hasMore;
        $this->nextCursorId = $page->nextCursorId;
        $this->nextCursorPostedAt = $page->nextCursorPostedAt;

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

        $accIds = array_map(static fn (array $r): int => $r['id'], $this->accumulatedRows);
        $stateIds = array_values(array_unique([...$rowIds, ...$accIds]));
        $taxState = $this->taxTagStateFor($stateIds, $taxTagQuery, $currentUser);
        $splitLegs = $this->legsFor($stateIds, $db, $taxTagQuery, $user->id);
        $clearedState = $this->clearedStatusFor($stateIds, $db, $currentUser);
        foreach ($this->accumulatedRows as &$accRow) {
            $accRowId = $accRow['id'];
            $accRow['taxTagged'] = $taxState[$accRowId]['taxTagged'] ?? false;
            $accRow['taxCategoryShortName'] = $taxState[$accRowId]['taxCategoryShortName'] ?? null;
            $accRow['splitLegs'] = $splitLegs[$accRowId] ?? [];
            $accRow['status'] = $clearedState[$accRowId] ?? 'cleared';
        }
        unset($accRow);

        return $views->make('ledger::livewire.transactions-list', [
            'page' => $page,
            'accumulatedRows' => $this->accumulatedRows,
            'fullHistory' => $this->fullHistory,
            'currency' => $this->currency,
            'chainTxIds' => $chainTxIds,
            'taxState' => $taxState,
            'splitLegs' => $splitLegs,
            'clearedState' => $clearedState,
            'isSearchMode' => false,
            'searchQuery' => $this->searchQuery,
            'searchTotalCount' => 0,
            'searchTotalOut' => 0,
            'searchTotalIn' => 0,
            'didYouMean' => null,
            'searchRows' => [],
            'activeFilterCount' => 0,
            'availableAccounts' => $this->availableAccounts($db, $user->id),
            'availableCategories' => $this->availableCategories($db, $user->id),
        ]);
    }

    /**
     * Count the number of active filter dimensions (for the phone "Filters N" badge).
     */
    public function activeFilterCount(): int
    {
        $count = 0;
        if ($this->filterAccounts !== []) {
            $count++;
        }
        if ($this->filterCategories !== []) {
            $count++;
        }
        if ($this->filterAfter !== '' || $this->filterBefore !== '') {
            $count++;
        }
        if ($this->filterAmountMin !== '' || $this->filterAmountMax !== '' || $this->filterAmountDir !== 'both') {
            $count++;
        }

        return $count;
    }

    /**
     * Load the list of user accounts for the Account filter popover.
     *
     * @return list<array{id: int, name: string, currency: string}>
     */
    private function availableAccounts(DatabaseManager $db, int $userId): array
    {
        $rows = $db->connection()
            ->table('accounts')
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get(['id', 'name', 'default_currency'])
            ->all();

        return array_values(array_map(static function (object $row): array {
            return [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => is_string($row->name) ? $row->name : '',
                'currency' => is_string($row->default_currency) ? $row->default_currency : 'EUR',
            ];
        }, $rows));
    }

    /**
     * Load the list of user categories for the Category filter popover.
     *
     * @return list<array{id: int, name: string}>
     */
    private function availableCategories(DatabaseManager $db, int $userId): array
    {
        // Categories are visible when global (seeded default tree, user_id IS
        // NULL) OR owned by the user — the canonical scoping used across the
        // categorization read paths. Filtering on user_id alone hid the chip
        // entirely on installs that only use the global tree.
        $rows = $db->connection()
            ->table('categories')
            ->where(static function (Builder $query) use ($userId): void {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();

        return array_values(array_map(static function (object $row): array {
            return [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => is_string($row->name) ? $row->name : '',
            ];
        }, $rows));
    }

    /**
     * Converts a TransactionRowDto to a scalar array suitable for Livewire
     * dehydration / serialisation. Money objects are stored as their minor
     * integer + currency code pair; the blade view reconstructs them at
     * render time via `Money::ofMinor()`.
     *
     * taxTagged + taxCategoryShortName default to false/null here; they are
     * overwritten after batch-load in render() so the values are always fresh.
     *
     * @return array{id: int, bookedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged: bool, taxCategoryShortName: ?string, splitLegs: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>}
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
            'taxTagged' => false,
            'taxCategoryShortName' => null,
            'splitLegs' => [],
        ];
    }

    /**
     * Converts a SearchRowDto to a scalar array for phone accumulation.
     * highlightedCounterparty and snippet are intentionally excluded to
     * avoid Livewire snapshot bloat (Pitfall-4).
     *
     * @return array{id: int, bookedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged: bool, taxCategoryShortName: ?string, splitLegs: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>}
     */
    private static function searchRowToArray(SearchRowDto $row): array
    {
        return [
            'id' => $row->id,
            'bookedAt' => $row->bookedAt,
            'counterpartyName' => $row->counterpartyName,
            'counterpartySlug' => $row->counterpartySlug,
            'categoryId' => $row->categoryId,
            'amountMinor' => $row->amountMinor,
            'amountCurrency' => $row->amountCurrency,
            'secondaryMinor' => $row->secondaryMinor,
            'secondaryCurrency' => $row->secondaryCurrency,
            'taxTagged' => false,
            'taxCategoryShortName' => null,
            'splitLegs' => [],
        ];
    }

    /**
     * Batch-load split legs (Phase 13.1) for the given transaction ids — a
     * single query for the whole page batch (Pitfall 1 / N+1 guard), keyed
     * by transaction_id. Downstream split detection is by LEG-ROW PRESENCE
     * ONLY (count >= 2 legs), never `category_id` nullity (Pitfall 1 / D-11)
     * — a split parent may carry a vestigial non-null `category_id`.
     *
     * Every id in $transactionIds is already user-scoped by the list query
     * that produced it (`transactions.user_id = $userId`), so joining on
     * `transaction_id` alone cannot leak another user's legs (T-13.1-14) —
     * a `transaction_splits` row can only exist for a `transaction_id`, and
     * every id passed here already belongs to the requesting user.
     *
     * @param  array<int>  $transactionIds
     * @return array<int, list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>>
     */
    private function legsFor(array $transactionIds, DatabaseManager $db, TaxTagQuery $taxTagQuery, int $userId): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $rows = $db->connection()
            ->table('transaction_splits')
            ->leftJoin('categories', 'transaction_splits.category_id', '=', 'categories.id')
            ->whereIn('transaction_splits.transaction_id', $transactionIds)
            ->orderBy('transaction_splits.transaction_id')
            ->orderBy('transaction_splits.sort_order')
            ->get([
                'transaction_splits.id',
                'transaction_splits.transaction_id',
                'transaction_splits.settled_amount_minor',
                'transaction_splits.settled_currency',
                'transaction_splits.note',
                'categories.name as category_name',
            ]);

        // Leg-scoped tax state — one batched query, keyed by "{txId}:{legId}"
        // (D-06a). Not merged into $taxState (whole-transaction only).
        $legTaxState = $taxTagQuery->forTransactionIdsWithLegs($userId, $transactionIds);

        $map = [];
        foreach ($rows as $row) {
            $txId = is_numeric($row->transaction_id) ? (int) $row->transaction_id : 0;
            $legId = is_numeric($row->id) ? (int) $row->id : 0;
            $legTag = $legTaxState[$txId.':'.$legId] ?? null;

            $map[$txId] ??= [];
            $map[$txId][] = [
                'id' => $legId,
                'categoryName' => is_string($row->category_name) ? $row->category_name : '—',
                'amountMinor' => is_numeric($row->settled_amount_minor) ? (int) $row->settled_amount_minor : 0,
                'amountCurrency' => is_string($row->settled_currency) ? $row->settled_currency : 'EUR',
                'note' => is_string($row->note) ? $row->note : null,
                'taxTagged' => $legTag !== null,
                'taxCategoryShortName' => $legTag->deductionCategoryShortName ?? null,
            ];
        }

        return $map;
    }
}
