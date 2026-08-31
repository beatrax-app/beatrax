<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Internal\Services\TransactionFilterOptions;
use Modules\Ledger\Internal\Services\TransactionRowDecorator;
use Modules\Ledger\Internal\Support\TransactionListViewData;
use Modules\Ledger\Public\Dto\TransactionRowDto;
use Modules\Ledger\Public\Enums\AmountDirection;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\CurrencyView;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Http\Livewire\Concerns\HandlesClearedStatus;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\TransactionListQuery;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Dto\SearchRowDto;
use Modules\Search\Public\Services\SearchQuery;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;

final class TransactionsList extends Component
{
    use HandlesClearedStatus;
    use HandlesTaxTagging;

    // ~200-400 bytes JSON-encoded per row, so 500 rows stays well below
    // Livewire 4's 4MB snapshot limit. Oldest rows are trimmed past it.
    private const int MAX_ACCUMULATED_ROWS = 500;

    public bool $fullHistory = false;

    public ?int $cursorId = null;

    public ?string $cursorPostedAt = null;

    // '' is the "no preference applied yet" sentinel; mount() resolves it
    // to the user's stored default before the first render.
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

    // A positive filter, not the absence of the one above: "no category" is a
    // bucket a report groups by, and a row opened from it used to emit nothing
    // at all and land the reader on the whole period.
    #[Url(as: 'uncategorized', except: false)]
    public bool $filterUncategorized = false;

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

    #[Url(as: 'amount_dir', except: AmountDirection::Both->value)]
    public string $filterAmountDir = AmountDirection::Both->value;

    // A direction alone cannot reconstruct a report figure: a fee and a
    // transfer out are both negative, and neither is counted as spend.
    /** @var list<mixed> Query-string supplied, so the element type is whatever arrived. */
    #[Url(as: 'type', except: [])]
    public array $filterTypes = [];

    public ?bool $preSearchFullHistory = null;

    // Locked, unlike the #[Url] filters above: the rows are projected from the
    // query and named by no binding, and the phone card list hands each one's
    // minor/currency pair to Money::ofMinor(). The docblock is a claim about
    // what the server puts here, not about what a payload can.
    /** @var list<array{id: int, postedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged?: bool, taxCategoryShortName?: ?string, splitLegs?: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>, status?: string}> */
    #[Locked]
    public array $accumulatedRows = [];

    public bool $hasMore = false;

    public ?int $nextCursorId = null;

    public ?string $nextCursorPostedAt = null;

    // Public so Livewire dehydrates it into the encrypted snapshot — a
    // protected property re-initialises to [] on every request, making the
    // duplicate-append guard ineffective. Locked because a payload naming the
    // current cursor here is what stops accumulate() replacing the rows.
    /** @var array<int, true> */
    #[Locked]
    public array $appendedCursorIds = [];

    public function mount(CurrentUser $currentUser): void
    {
        if ($this->currency === '') {
            $this->currency = $currentUser->user()->default_currency_view->value;
        }
    }

    public function isSearchActive(): bool
    {
        return $this->searchQuery !== ''
            || $this->filterAccounts !== []
            || $this->filterCategories !== []
            || $this->filterUncategorized
            || $this->filterCounterparties !== []
            || $this->filterAfter !== ''
            || $this->filterBefore !== ''
            || $this->filterAmountMin !== ''
            || $this->filterAmountMax !== ''
            || $this->filterAmountDir !== AmountDirection::Both->value
            || $this->filterTypes !== [];
    }

    public function clearSearch(): void
    {
        $this->searchQuery = '';
        $this->filterAccounts = [];
        $this->filterCategories = [];
        $this->filterUncategorized = false;
        $this->filterCounterparties = [];
        $this->filterAfter = '';
        $this->filterBefore = '';
        $this->filterAmountMin = '';
        $this->filterAmountMax = '';
        $this->filterAmountDir = AmountDirection::Both->value;
        $this->filterTypes = [];

        if ($this->preSearchFullHistory !== null) {
            $this->fullHistory = $this->preSearchFullHistory;
            $this->preSearchFullHistory = null;
        }

        $this->resetPagination();
    }

    // One chip holds both the ids and the "no category" bucket, so its clear
    // button has to empty both or the list stays narrowed with nothing shown.
    public function clearCategoryFilter(): void
    {
        $this->filterCategories = [];
        $this->filterUncategorized = false;
        $this->resetPagination();
    }

    public function toggleFullHistory(): void
    {
        $this->fullHistory = ! $this->fullHistory;
        $this->resetPagination();
    }

    // Search and every filter are wire:model.live, so refining one re-ran the
    // query with the PREVIOUS cursor still set: the table started mid-history,
    // the header counted rows it did not show, and on the phone the
    // appendedCursorIds guard already held that key so nothing was appended.
    public function updated(string $property): void
    {
        if ($property === 'searchQuery' || str_starts_with($property, 'filter')) {
            $this->resetPagination();
        }
    }

    private function resetPagination(): void
    {
        $this->cursorId = null;
        $this->cursorPostedAt = null;
        $this->accumulatedRows = [];
        $this->appendedCursorIds = [];
        $this->hasMore = false;
        $this->nextCursorId = null;
        $this->nextCursorPostedAt = null;
    }

    // The cursor comes from the server-side Livewire snapshot, never from a
    // browser parameter: the snapshot is HMAC-verified before hydration, so
    // a caller cannot forge a cursor to skip rows.
    public function loadMore(): void
    {
        $this->cursorId = $this->nextCursorId;
        $this->cursorPostedAt = $this->nextCursorPostedAt;
    }

    public function render(
        CurrentUser $currentUser,
        TransactionListQuery $listQuery,
        ViewFactory $views,
        SearchQuery $searchQuery,
        TransactionRowDecorator $rows,
        TransactionFilterOptions $filterOptions,
        BaseCurrency $baseCurrency,
    ): View {
        $this->normaliseFilters();

        return $this->isSearchActive()
            ? $this->renderSearch($currentUser, $views, $searchQuery, $rows, $filterOptions, $baseCurrency)
            : $this->renderList($currentUser, $listQuery, $views, $rows, $filterOptions, $baseCurrency);
    }

    // Livewire hands a #[Url] property to the view as well as to the query, so
    // cleaning it on the way to the query alone left the chip partial
    // subscripting [0] on a shape the address bar chose. Coerced on the
    // property instead, both readers see the same filter.
    private function normaliseFilters(): void
    {
        $this->filterAccounts = self::positiveIds($this->filterAccounts);
        $this->filterCategories = self::positiveIds($this->filterCategories);
        $this->filterCounterparties = self::positiveIds($this->filterCounterparties);
        $this->filterAfter = self::supportedDay($this->filterAfter);
        $this->filterBefore = self::supportedDay($this->filterBefore);
    }

    // ?before=2026 is not a wider filter, it is a string the DATE comparison
    // read lexically: 187 rows all dated 2026 came back as none, under a chip
    // printing "Before 2026" and a count claiming a filter was applied. The
    // picker and every preset emit a day, so anything else is a bad link.
    private static function supportedDay(string $raw): string
    {
        return SafeDate::dayOrNull($raw) === null ? '' : trim($raw);
    }

    // Captures $fullHistory on entry so clearSearch() can restore the view
    // the user was in, rather than whatever the search happened to span.
    private function renderSearch(
        CurrentUser $currentUser,
        ViewFactory $views,
        SearchQuery $searchQuery,
        TransactionRowDecorator $rows,
        TransactionFilterOptions $filterOptions,
        BaseCurrency $baseCurrency,
    ): View {
        $user = $currentUser->user();
        $readerCurrency = $baseCurrency->forUser($user);

        if ($this->preSearchFullHistory === null) {
            $this->preSearchFullHistory = $this->fullHistory;
        }

        $page = $searchQuery->search(
            $user,
            $this->searchQuery,
            $this->searchFilters(),
            $this->cursorId,
            $this->cursorPostedAt,
        );

        $this->accumulate(array_map(
            static fn (SearchRowDto $row): array => TransactionListViewData::searchRow($row),
            $page->rows,
        ));

        $this->hasMore = $page->hasMore;
        $this->nextCursorId = $page->nextCursorId;
        $this->nextCursorPostedAt = $page->nextCursorPostedAt;

        $rowIds = array_map(static fn (SearchRowDto $row): int => $row->id, $page->rows);
        $state = $this->decorateAccumulatedRows($rowIds, $currentUser, $rows, $readerCurrency);

        return $views->make('ledger::livewire.transactions-list', [
            ...$this->sharedViewData($state, $filterOptions, $user, $readerCurrency),
            ...TransactionListViewData::searchPage($page),
            'chainTxIds' => [],
            'hasOlderTransactions' => false,
            'isSearchMode' => true,
            'activeFilterCount' => $this->activeFilterCount(),
        ]);
    }

    private function renderList(
        CurrentUser $currentUser,
        TransactionListQuery $listQuery,
        ViewFactory $views,
        TransactionRowDecorator $rows,
        TransactionFilterOptions $filterOptions,
        BaseCurrency $baseCurrency,
    ): View {
        $user = $currentUser->user();
        $readerCurrency = $baseCurrency->forUser($user);
        // BaseOnly resolves to the READER's base currency, never to the euro
        // its token spells.
        $queryCurrency = $this->currency === CurrencyView::BaseOnly->value ? $readerCurrency : null;

        $page = $this->fullHistory
            ? $listQuery->fullHistory($user, cursorId: $this->cursorId, cursorPostedAt: $this->cursorPostedAt, currency: $queryCurrency)
            : $listQuery->recent($user, daysBack: 90, cursorId: $this->cursorId, cursorPostedAt: $this->cursorPostedAt, currency: $queryCurrency);

        $this->accumulate(array_values(array_map(
            static fn (TransactionRowDto $row): array => TransactionListViewData::row($row),
            $page->rows,
        )));

        $this->hasMore = $page->hasMore;
        $this->nextCursorId = $page->nextCursorId;
        $this->nextCursorPostedAt = $page->nextCursorPostedAt;

        $rowIds = array_values(array_map(static fn (TransactionRowDto $row): int => $row->id, $page->rows));
        $state = $this->decorateAccumulatedRows($rowIds, $currentUser, $rows, $readerCurrency);

        // An empty recent window and an empty ledger look identical on screen,
        // and the way out is a button in the header the reader has no reason to
        // connect to it. Asked only when the window came back empty, so the
        // common path pays nothing.
        $hasOlderTransactions = $page->rows === []
            && ! $this->fullHistory
            && $listQuery->hasAnyTransaction($user);

        return $views->make('ledger::livewire.transactions-list', [
            ...$this->sharedViewData($state, $filterOptions, $user, $readerCurrency),
            'page' => $page,
            'chainTxIds' => $rows->chainTxIdsFor($rowIds, $user->id),
            'hasOlderTransactions' => $hasOlderTransactions,
            'isSearchMode' => false,
            'searchTotalCount' => 0,
            'searchTotalOut' => 0,
            'searchTotalIn' => 0,
            'searchUnconverted' => '',
            'didYouMean' => null,
            'searchRows' => [],
            'activeFilterCount' => 0,
        ]);
    }

    // A URL parameter, so an unknown value is dropped rather than passed into
    // a whereIn that would then narrow to nothing on a typo.
    /**
     * @return list<string>
     */
    private function knownTypes(): array
    {
        $known = array_map(static fn (TransactionType $type): string => $type->value, TransactionType::cases());

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $this->filterTypes),
            static fn (string $value): bool => in_array($value, $known, true),
        ));
    }

    private function searchFilters(): SearchFilters
    {
        return new SearchFilters(
            accounts: $this->filterAccounts,
            categories: $this->filterCategories,
            counterparties: $this->filterCounterparties,
            after: $this->filterAfter !== '' ? $this->filterAfter : null,
            before: $this->filterBefore !== '' ? $this->filterBefore : null,
            amountMin: $this->filterAmountMin !== '' ? $this->filterAmountMin : null,
            amountMax: $this->filterAmountMax !== '' ? $this->filterAmountMax : null,
            amountDirection: $this->filterAmountDir,
            types: $this->knownTypes(),
            uncategorized: $this->filterUncategorized,
        );
    }

    // array<array-key, mixed> deliberately: ?account[]= is reader-supplied, so
    // the declared list<int> describes what the rail sends, not what arrives.
    // A non-numeric member is dropped rather than cast, since (int) 'abc' is
    // the same 0 an unselected option sends, which would narrow to nothing.
    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    private static function positiveIds(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            if (! is_numeric($id)) {
                continue;
            }

            $numeric = (int) $id;
            if ($numeric > 0) {
                $clean[] = $numeric;
            }
        }

        return $clean;
    }

    // appendedCursorIds stops a re-render at the same cursor appending the
    // same rows twice, which Livewire does whenever any property changes.
    /**
     * @param  list<array{id: int, postedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged?: bool, taxCategoryShortName?: ?string, splitLegs?: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>, status?: string}>  $rows
     */
    private function accumulate(array $rows): void
    {
        $guardKey = $this->cursorId ?? 0;

        if ($guardKey === 0) {
            $this->accumulatedRows = $rows;
            $this->appendedCursorIds = [0 => true];
        } elseif (! isset($this->appendedCursorIds[$guardKey])) {
            foreach ($rows as $row) {
                $this->accumulatedRows[] = $row;
            }
            $this->appendedCursorIds[$guardKey] = true;
        }

        if (count($this->accumulatedRows) > self::MAX_ACCUMULATED_ROWS) {
            $this->accumulatedRows = array_slice($this->accumulatedRows, -self::MAX_ACCUMULATED_ROWS);
            $this->appendedCursorIds = [$guardKey => true];
        }
    }

    // Tax, split and cleared state are read for the whole accumulated set,
    // not just this page: a row loaded three pages ago is still on screen
    // and still has to show the truth after it is tagged or split.
    /**
     * @param  list<int>  $rowIds
     * @return array{taxState: array<int, array<string, mixed>>, splitLegs: array<int, mixed>, clearedState: array<int, string>}
     */
    private function decorateAccumulatedRows(
        array $rowIds,
        CurrentUser $currentUser,
        TransactionRowDecorator $rows,
        string $readerCurrency,
    ): array {
        $accIds = array_map(static fn (array $r): int => $r['id'], $this->accumulatedRows);
        $stateIds = array_values(array_unique([...$rowIds, ...$accIds]));

        $taxState = $this->taxTagStateFor($stateIds, $rows->taxTags(), $currentUser);
        $splitLegs = $rows->legsFor($stateIds, $currentUser->user()->id, $readerCurrency);
        $clearedState = $this->clearedStatusFor($stateIds, $rows->database(), $currentUser);

        foreach ($this->accumulatedRows as &$accRow) {
            $accRowId = $accRow['id'];
            $accRow['taxTagged'] = $taxState[$accRowId]['taxTagged'] ?? false;
            $accRow['taxCategoryShortName'] = $taxState[$accRowId]['taxCategoryShortName'] ?? null;
            $accRow['splitLegs'] = $splitLegs[$accRowId] ?? [];
            $accRow['status'] = $clearedState[$accRowId] ?? ClearedStatus::Cleared->value;
        }
        unset($accRow);

        return ['taxState' => $taxState, 'splitLegs' => $splitLegs, 'clearedState' => $clearedState];
    }

    /**
     * @param  array{taxState: array<int, array<string, mixed>>, splitLegs: array<int, mixed>, clearedState: array<int, string>}  $state
     * @return array<string, mixed>
     */
    private function sharedViewData(array $state, TransactionFilterOptions $filterOptions, User $user, string $readerCurrency): array
    {
        return [
            'baseCurrency' => $readerCurrency,
            'accumulatedRows' => $this->accumulatedRows,
            'fullHistory' => $this->fullHistory,
            'currency' => $this->currency,
            'taxState' => $state['taxState'],
            'splitLegs' => $state['splitLegs'],
            'clearedState' => $state['clearedState'],
            'searchQuery' => $this->searchQuery,
            'availableAccounts' => $filterOptions->accounts($user->id),
            'availableCategories' => $filterOptions->categories($user->id),
            'dateRangePresets' => $filterOptions->dateRanges($user),
        ];
    }

    public function activeFilterCount(): int
    {
        $count = 0;
        if ($this->filterAccounts !== []) {
            $count++;
        }
        if ($this->filterCategories !== [] || $this->filterUncategorized) {
            $count++;
        }
        if ($this->filterCounterparties !== []) {
            $count++;
        }
        if ($this->filterAfter !== '' || $this->filterBefore !== '') {
            $count++;
        }
        if ($this->filterAmountMin !== '' || $this->filterAmountMax !== '' || $this->filterAmountDir !== AmountDirection::Both->value) {
            $count++;
        }

        return $count;
    }
}
