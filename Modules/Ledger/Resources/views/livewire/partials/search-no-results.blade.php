@use('Modules\Ledger\Public\Enums\AmountDirection')
@use('Modules\Core\Public\Support\Lang')
{{--
    No-results state (UI-SPEC Component Inventory #10).

    Renders when a query + filters produce zero matches.
    - "Nothing matches" heading
    - Body copy: filter-removal prompt (with active filter chips) OR generic no-results
    - "Did you mean: X?" link when $didYouMean is non-null

    Variables in scope (from transactions-list.blade.php):
    - $searchQuery (string) — the current query
    - $isSearchMode (bool)
    - $filterAfter, $filterBefore, $filterAccounts, $filterCategories, $filterUncategorized,
      $filterAmountMin, $filterAmountMax, $filterAmountDir, $filterTypes

    Every filter $activeFilterCount counts draws a chip here. One that does not
    leaves the prompt above asking for a control the reader cannot see: ticking
    "No category" on a ledger with none emptied the list under "Remove a filter
    to see more" and an empty strip.
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
                        wire:click="removeAccountFilter('{{ (int) $accountId }}')"
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
                        wire:click="removeCategoryFilter('{{ (int) $categoryId }}')"
                        class="srch-chip-close"
                        aria-label="{{ Lang::get('ledger::list.filter.remove_named_aria', ['name' => $catName]) }}"
                    >&times;</button>
                </span>
            @endforeach

            @if ($filterUncategorized ?? false)
                <span class="srch-chip srch-chip--active">
                    {{ Lang::get('ledger::common.uncategorized') }}
                    <button
                        type="button"
                        wire:click="$set('filterUncategorized', false)"
                        class="srch-chip-close"
                        aria-label="{{ Lang::get('ledger::list.filter.remove_category_aria') }}"
                    >&times;</button>
                </span>
            @endif

            @if (($filterAmountMin ?? '') !== '' || ($filterAmountMax ?? '') !== '' || ($filterAmountDir ?? AmountDirection::Both->value) !== AmountDirection::Both->value)
                <span class="srch-chip srch-chip--active">
                    {{ Lang::get('ledger::list.filter.amount') }}
                    <button
                        type="button"
                        wire:click="$set('filterAmountMin', ''); $set('filterAmountMax', ''); $set('filterAmountDir', '{{ AmountDirection::Both->value }}')"
                        class="srch-chip-close"
                        aria-label="{{ Lang::get('ledger::list.filter.remove_amount_aria') }}"
                    >&times;</button>
                </span>
            @endif

            {{-- One chip for the whole set: a report drill-down sends the
                 metric's types together, and clearing one of them would leave
                 the list answering a question no figure was ever built from. --}}
            @if (($filterTypes ?? []) !== [])
                @php
                    $typeNames = implode(', ', array_map(
                        static fn (string $type): string => Lang::get('ledger::detail.type_label.'.$type),
                        $filterTypes,
                    ));
                @endphp
                <span class="srch-chip srch-chip--active">
                    {{ $typeNames }}
                    <button
                        type="button"
                        wire:click="$set('filterTypes', [])"
                        class="srch-chip-close"
                        aria-label="{{ Lang::get('ledger::list.filter.remove_named_aria', ['name' => $typeNames]) }}"
                    >&times;</button>
                </span>
            @endif
        </div>
    @elseif (($searchQuery ?? '') !== '')
        <p class="srch-no-results__body">{{ Lang::get('ledger::list.no_results.no_match_query', ['query' => $searchQuery]) }}</p>
    @else
        <p class="srch-no-results__body">{{ Lang::get('ledger::list.no_results.no_match_filters') }}</p>
    @endif

    @if ($didYouMean !== null)
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
