{{--
    No-results state (D-21, UI-SPEC Component Inventory #10).

    Renders when a query + filters produce zero matches.
    - "Nothing matches" heading
    - Body copy: filter-removal prompt (with active filter chips) OR generic no-results
    - "Did you mean: X?" link when $didYouMean is non-null

    Variables in scope (from transactions-list.blade.php):
    - $searchQuery (string) — the current query
    - $isSearchMode (bool)
    - $filterAfter, $filterBefore, $filterAccounts, $filterCategories, $filterAmountMin, $filterAmountMax, $filterAmountDir
    - $didYouMean (?string)
    - $activeFilterCount (int)
--}}

<div class="srch-no-results">
    <p class="srch-no-results__heading">Nothing matches</p>

    @if (($activeFilterCount ?? 0) > 0)
        <p class="srch-no-results__body">Try removing a filter that may be narrowing results:</p>
        <div class="srch-no-results__chips">
            @if (($filterAfter ?? '') !== '' || ($filterBefore ?? '') !== '')
                <span class="srch-chip srch-chip--active">
                    Date
                    <button
                        type="button"
                        wire:click="$set('filterAfter', ''); $set('filterBefore', '')"
                        class="srch-chip-close"
                        aria-label="Remove date filter"
                    >&times;</button>
                </span>
            @endif

            @foreach ($filterAccounts ?? [] as $accountId)
                @php
                    $acctName = collect($availableAccounts ?? [])->firstWhere('id', (int) $accountId)['name'] ?? "Account {$accountId}";
                @endphp
                <span class="srch-chip srch-chip--active">
                    {{ $acctName }}
                    <button
                        type="button"
                        wire:click="$set('filterAccounts', array_values(array_filter($filterAccounts, fn($id) => (int)$id !== {{ (int) $accountId }})))"
                        class="srch-chip-close"
                        aria-label="Remove {{ $acctName }} filter"
                    >&times;</button>
                </span>
            @endforeach

            @foreach ($filterCategories ?? [] as $categoryId)
                @php
                    $catName = collect($availableCategories ?? [])->firstWhere('id', (int) $categoryId)['name'] ?? "Category {$categoryId}";
                @endphp
                <span class="srch-chip srch-chip--active">
                    {{ $catName }}
                    <button
                        type="button"
                        wire:click="$set('filterCategories', array_values(array_filter($filterCategories, fn($id) => (int)$id !== {{ (int) $categoryId }})))"
                        class="srch-chip-close"
                        aria-label="Remove {{ $catName }} filter"
                    >&times;</button>
                </span>
            @endforeach

            @if (($filterAmountMin ?? '') !== '' || ($filterAmountMax ?? '') !== '' || ($filterAmountDir ?? 'both') !== 'both')
                <span class="srch-chip srch-chip--active">
                    Amount
                    <button
                        type="button"
                        wire:click="$set('filterAmountMin', ''); $set('filterAmountMax', ''); $set('filterAmountDir', 'both')"
                        class="srch-chip-close"
                        aria-label="Remove amount filter"
                    >&times;</button>
                </span>
            @endif
        </div>
    @elseif (($searchQuery ?? '') !== '')
        <p class="srch-no-results__body">No transactions match &ldquo;{{ $searchQuery }}&rdquo; across all history.</p>
    @else
        <p class="srch-no-results__body">No transactions match the applied filters.</p>
    @endif

    @if (! empty($didYouMean))
        <p class="srch-no-results__suggestion">
            Did you mean:
            <button
                type="button"
                wire:click="$set('searchQuery', {{ Js::from($didYouMean) }})"
                class="srch-did-you-mean"
            >&ldquo;{{ $didYouMean }}&rdquo;</button>?
        </p>
    @endif
</div>
