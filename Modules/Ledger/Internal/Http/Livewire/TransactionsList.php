<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
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
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;
use Modules\Tax\Public\Services\TaxTagQuery;

// The query service is injected as a parameter on render() — Livewire
// Component subclasses can't accept constructor injection under the
// project's strict-rules ruleset.
/**
 * @link ../../../../../.docs/features/ledger/architecture.md
 */
final class TransactionsList extends Component
{
    use HandlesClearedStatus;
    use HandlesTaxTagging;

    // ~200-400 bytes JSON-encoded per row, so 500 rows stays well below
    // Livewire 4's 4MB snapshot limit. See the linked architecture page.
    private const MAX_ACCUMULATED_ROWS = 500;

    public bool $fullHistory = false;

    public ?int $cursorId = null;

    public ?string $cursorPostedAt = null;

    // Empty string is the "no preference applied yet" sentinel;
    // mount() resolves it to the user's stored default before the
    // first render.
    #[Url(as: 'currency', except: '')]
    public string $currency = '';

    #[Url(as: 'q', except: '')]
    public string $searchQuery = '';

    /** @var list<int> */
    #[Url(as: 'account', except: [])]
    public array $filterAccounts = [];

    /** @var list<int> */
    #[Url(as: 'category', except: [])]
    public array $filterCategories = [];

    /** @var list<int> */
    #[Url(as: 'counterparty', except: [])]
    public array $filterCounterparties = [];

    #[Url(as: 'after', except: '')]
    public string $filterAfter = '';

    #[Url(as: 'before', except: '')]
    public string $filterBefore = '';

    #[Url(as: 'amount_min', except: '')]
    public string $filterAmountMin = '';

    #[Url(as: 'amount_max', except: '')]
    public string $filterAmountMax = '';

    #[Url(as: 'amount_dir', except: 'both')]
    public string $filterAmountDir = 'both';

    public ?bool $preSearchFullHistory = null;

    // See the linked architecture page for the phone-infinite-scroll
    // and search-mode accumulation rules this array supports.
    /** @var list<array{id: int, bookedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged?: bool, taxCategoryShortName?: ?string, splitLegs?: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>, status?: string}> */
    public array $accumulatedRows = [];

    public bool $hasMore = false;

    public ?int $nextCursorId = null;

    public ?string $nextCursorPostedAt = null;

    // The sentinel value 0 represents "first page" (no cursor set).
    // Must be public so Livewire dehydrates it into the encrypted
    // snapshot — a protected property re-initialises to [] on every
    // request, making the duplicate-append guard ineffective.
    /** @var array<int, true> */
    public array $appendedCursorIds = [];

    public function mount(CurrentUser $currentUser): void
    {
        if ($this->currency === '') {
            $pref = $currentUser->user()->default_currency_view;
            $this->currency = $pref === 'eur_only' ? 'eur' : 'original';
        }
    }

    // When true, render() branches to SearchQuery::search() and all
    // history is searched regardless of the $fullHistory toggle.
    public function isSearchActive(): bool
    {
        return $this->searchQuery !== ''
            || $this->filterAccounts !== []
            || $this->filterCategories !== []
            || $this->filterCounterparties !== []
            || $this->filterAfter !== ''
            || $this->filterBefore !== ''
            || $this->filterAmountMin !== ''
            || $this->filterAmountMax !== ''
            || $this->filterAmountDir !== 'both';
    }

    // Restores $fullHistory to its value before the search session
    // started, so the user returns to the same view mode they were in
    // before typing.
    public function clearSearch(): void
    {
        $this->searchQuery = '';
        $this->filterAccounts = [];
        $this->filterCategories = [];
        $this->filterCounterparties = [];
        $this->filterAfter = '';
        $this->filterBefore = '';
        $this->filterAmountMin = '';
        $this->filterAmountMax = '';
        $this->filterAmountDir = 'both';

        if ($this->preSearchFullHistory !== null) {
            $this->fullHistory = $this->preSearchFullHistory;
            $this->preSearchFullHistory = null;
        }

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

    // Reads the cursor from the server-side Livewire snapshot rather
    // than accepting it as a browser-supplied parameter — the
    // snapshot is encrypted/HMAC-verified before hydration, so a
    // caller cannot forge a cursor to skip rows.
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
        SensitiveColumnCodec $codec,
        Session $session,
    ): View {
        $user = $currentUser->user();

        if ($this->isSearchActive()) {
            if ($this->preSearchFullHistory === null) {
                $this->preSearchFullHistory = $this->fullHistory;
            }

            $filters = new SearchFilters(
                accounts: array_values(array_filter(
                    $this->filterAccounts,
                    static fn (int $id): bool => $id > 0,
                )),
                categories: array_values(array_filter(
                    $this->filterCategories,
                    static fn (int $id): bool => $id > 0,
                )),
                counterparties: array_values(array_filter(
                    $this->filterCounterparties,
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

            // Accumulate search result rows using the same guard pattern;
            // highlightedCounterparty + snippet are re-fetched from
            // SearchQuery on each render rather than stored here.
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

            $rowIds = array_map(static fn (SearchRowDto $row): int => $row->id, $searchPage->rows);
            $accIds = array_map(static fn (array $r): int => $r['id'], $this->accumulatedRows);
            $stateIds = array_values(array_unique([...$rowIds, ...$accIds]));
            $taxState = $this->taxTagStateFor($stateIds, $taxTagQuery, $currentUser);
            $splitLegs = $this->legsFor($stateIds, $db, $taxTagQuery, $user->id, $codec, $session);
            $clearedState = $this->clearedStatusFor($stateIds, $db, $currentUser);

            foreach ($this->accumulatedRows as &$accRow) {
                $accRowId = $accRow['id'];
                $accRow['taxTagged'] = $taxState[$accRowId]['taxTagged'] ?? false;
                $accRow['taxCategoryShortName'] = $taxState[$accRowId]['taxCategoryShortName'] ?? null;
                $accRow['splitLegs'] = $splitLegs[$accRowId] ?? [];
                $accRow['status'] = $clearedState[$accRowId] ?? 'cleared';
            }
            unset($accRow);

            // Transaction id -> SearchRowDto, for highlight/snippet
            // access in the Blade; recomputed on every render.
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
        $splitLegs = $this->legsFor($stateIds, $db, $taxTagQuery, $user->id, $codec, $session);
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

    public function activeFilterCount(): int
    {
        $count = 0;
        if ($this->filterAccounts !== []) {
            $count++;
        }
        if ($this->filterCategories !== []) {
            $count++;
        }
        if ($this->filterCounterparties !== []) {
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

    /** @return list<array{id: int, name: string, currency: string}> */
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

    /** @return list<array{id: int, name: string}> */
    private function availableCategories(DatabaseManager $db, int $userId): array
    {
        // Categories are visible when global (seeded default tree,
        // user_id IS NULL) OR owned by the user — filtering on
        // user_id alone hid the chip on installs using only the
        // global tree.
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

    // Money objects are stored as their minor integer + currency code
    // pair; the blade reconstructs them via Money::ofMinor(). taxTagged
    // + taxCategoryShortName default here and are overwritten after
    // batch-load in render().
    /** @return array{id: int, bookedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged: bool, taxCategoryShortName: ?string, splitLegs: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>} */
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

    // highlightedCounterparty and snippet are intentionally excluded to
    // avoid Livewire snapshot bloat.
    /** @return array{id: int, bookedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged: bool, taxCategoryShortName: ?string, splitLegs: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>} */
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

    // See the linked architecture page for why joining on
    // transaction_id alone cannot leak another user's legs.
    /**
     * @link ../../../../../.docs/features/ledger/architecture.md
     *
     * @param  array<int>  $transactionIds
     * @return array<int, list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>>
     */
    private function legsFor(array $transactionIds, DatabaseManager $db, TaxTagQuery $taxTagQuery, int $userId, SensitiveColumnCodec $codec, Session $session): array
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

        // Leg-scoped tax state — one batched query, keyed by
        // "{txId}:{legId}". Not merged into $taxState (whole-transaction only).
        $legTaxState = $taxTagQuery->forTransactionIdsWithLegs($userId, $transactionIds);

        $map = [];
        foreach ($rows as $row) {
            $txId = is_numeric($row->transaction_id) ? (int) $row->transaction_id : 0;
            $legId = is_numeric($row->id) ? (int) $row->id : 0;
            $legTag = $legTaxState[$txId.':'.$legId] ?? null;

            // Read-side decrypt — pass-through no-op when encryption
            // is not enabled for this user.
            $legNote = is_string($row->note)
                ? $codec->decryptValue('transaction_splits', 'note', $row->note, $userId, $session)['value']
                : null;

            $map[$txId] ??= [];
            $map[$txId][] = [
                'id' => $legId,
                'categoryName' => is_string($row->category_name) ? $row->category_name : '—',
                'amountMinor' => is_numeric($row->settled_amount_minor) ? (int) $row->settled_amount_minor : 0,
                'amountCurrency' => is_string($row->settled_currency) ? $row->settled_currency : 'EUR',
                'note' => $legNote,
                'taxTagged' => $legTag !== null,
                'taxCategoryShortName' => $legTag->deductionCategoryShortName ?? null,
            ];
        }

        return $map;
    }
}
