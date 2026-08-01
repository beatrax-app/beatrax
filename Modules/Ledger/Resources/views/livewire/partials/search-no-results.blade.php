@use('Modules\Core\Public\Support\Lang')
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
    <p class="srch-no-results__heading">{{ Lang::get('ledger::list.no_results.heading') }}</p>

    @if (($activeFilterCount ?? 0) > 0)
        <p class="srch-no-results__body">{{ Lang::get('ledger::list.no_results.remove_prompt') }}</p>
        <div class="srch-no-results__chips">
            @if (($filterAfter ?? '') !== '' || ($filterBefore ?? '') !== '')
                <span class="srch-chip srch-chip--active">
                    {{ Lang::get('ledger::list.filter.date') }}
                    <button
                        type="button"
                        wire:click="$set('filterAfter', ''); $set('filterBefore', '')"
                        class="srch-chip-close"
                        aria-label="{{ Lang::get('ledger::list.filter.remove_date_aria') }}"
                    >&times;</button>
                </span>
            @endif

            @foreach ($filterAccounts ?? [] as $accountId)
                @php
                    $acctName = collect($availableAccounts ?? [])->firstWhere('id', (int) $accountId)['name'] ?? Lang::get('ledger::list.no_results.account_fallback', ['id' => $accountId]);
                @endphp
                <span class="srch-chip srch-chip--active">
                    {{ $acctName }}
                    <button
                        type="button"
                        wire:click="$set('filterAccounts', array_values(array_filter($filterAccounts, fn($id) => (int)$id !== {{ (int) $accountId }})))"
                        class="srch-chip-close"
                        aria-label="{{ Lang::get('ledger::list.filter.remove_named_aria', ['name' => $acctName]) }}"
                    >&times;</button>
                </span>
            @endforeach

            @foreach ($filterCategories ?? [] as $categoryId)
                @php
                    $catName = collect($availableCategories ?? [])->firstWhere('id', (int) $categoryId)['name'] ?? Lang::get('ledger::list.no_results.category_fallback', ['id' => $categoryId]);
                @endphp
                <span class="srch-chip srch-chip--active">
                    {{ $catName }}
                    <button
                        type="button"
                        wire:click="$set('filterCategories', array_values(array_filter($filterCategories, fn($id) => (int)$id !== {{ (int) $categoryId }})))"
                        class="srch-chip-close"
                        aria-label="{{ Lang::get('ledger::list.filter.remove_named_aria', ['name' => $catName]) }}"
                    >&times;</button>
                </span>
            @endforeach

            @if (($filterAmountMin ?? '') !== '' || ($filterAmountMax ?? '') !== '' || ($filterAmountDir ?? 'both') !== 'both')
                <span class="srch-chip srch-chip--active">
                    {{ Lang::get('ledger::list.filter.amount') }}
                    <button
                        type="button"
                        wire:click="$set('filterAmountMin', ''); $set('filterAmountMax', ''); $set('filterAmountDir', 'both')"
                        class="srch-chip-close"
                        aria-label="{{ Lang::get('ledger::list.filter.remove_amount_aria') }}"
                    >&times;</button>
                </span>
            @endif
        </div>
    @elseif (($searchQuery ?? '') !== '')
        <p class="srch-no-results__body">{{ Lang::get('ledger::list.no_results.no_match_query', ['query' => $searchQuery]) }}</p>
    @else
        <p class="srch-no-results__body">{{ Lang::get('ledger::list.no_results.no_match_filters') }}</p>
    @endif

    @if (! empty($didYouMean))
        <p class="srch-no-results__suggestion">
            {{ Lang::get('ledger::list.no_results.did_you_mean') }}
            <button
                type="button"
                wire:click="$set('searchQuery', {{ Js::from($didYouMean) }})"
                class="srch-did-you-mean"
            >&ldquo;{{ $didYouMean }}&rdquo;</button>?
        </p>
    @endif
</div>
