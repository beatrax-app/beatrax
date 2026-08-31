@use('Modules\Ledger\Public\Enums\AmountDirection')
@use('Modules\Ledger\Public\Services\BaseCurrency')
@use('Modules\Ledger\Public\ValueObjects\Money')
@use('Modules\Ledger\Public\ValueObjects\MoneyInput')
@use('Modules\Core\Public\Support\Lang')
{{--
    Filter chip popovers — desktop (≥768px) only.
    Each chip is an Alpine.js-driven popover (no Flux dependency) so the
    component renders correctly in test environments without published Flux stubs.

    UI-SPEC: Component Inventory #4 (filter popovers).
    Copywriting Contract: chip copy in inactive and active states.

    Consumed by search-toolbar.blade.php (inside the .srch-chips flex row).
--}}
@php
    // .srch-chip:hover paints var(--color-hover), a token this stylesheet never
    // defines, so it lands on the near-white fallback: unreadable under dark
    // mode's near-white text, and indistinguishable from the white popover in
    // light. `!` because that rule is unlayered and outranks layered utilities.
@endphp

{{-- ─── Date chip ─────────────────────────────────────────────────────── --}}
<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    <span class="srch-chip {{ ($filterAfter ?? '') !== '' || ($filterBefore ?? '') !== '' ? 'srch-chip--active' : '' }}">
        <button type="button" class="srch-chip-toggle" x-on:click="open = !open" :aria-expanded="open">
            @if (($filterAfter ?? '') !== '' || ($filterBefore ?? '') !== '')
                @php
                    $dateLabel = match (true) {
                        ($filterAfter ?? '') !== '' && ($filterBefore ?? '') !== '' => Lang::get('ledger::list.filter.custom_range'),
                        ($filterAfter ?? '') !== '' => Lang::get('ledger::list.filter.after', ['date' => $filterAfter ?? '']),
                        default => Lang::get('ledger::list.filter.before', ['date' => $filterBefore ?? '']),
                    };
                @endphp
                {{ $dateLabel }}
            @else
                {{ Lang::get('ledger::list.filter.date') }} &#9662;
            @endif
        </button>
        @if (($filterAfter ?? '') !== '' || ($filterBefore ?? '') !== '')
            <button
                type="button"
                wire:click.stop="$set('filterAfter', ''); $set('filterBefore', '')"
                class="srch-chip-close"
                aria-label="{{ Lang::get('ledger::list.filter.remove_date_aria') }}"
            >&times;</button>
        @endif
    </span>
    <div
        x-show="open" x-cloak
        x-on:click.outside="open = false"
        x-transition
        class="srch-popover"
        role="dialog"
        aria-label="{{ Lang::get('ledger::list.filter.date_dialog') }}"
    >
        <div class="srch-popover-inner">
            {{-- Preset buttons --}}
            @foreach ($dateRangePresets as $labelKey => [$start, $end])
                <button
                    type="button"
                    wire:click="$set('filterAfter', '{{ $start }}')"
                    x-on:click="$wire.$set('filterBefore', '{{ $end }}'); open = false"
                    class="srch-date-preset"
                >{{ Lang::get($labelKey) }}</button>
            @endforeach

            <div class="srch-date-range mt-2">
                {{-- for/id rather than aria-label on the input: the label was
                     rendered but attached to nothing, and an aria-label that
                     does not contain the visible text gives the field a name
                     no one can say out loud to a user looking at "From". --}}
                <label for="search-filter-after" class="srch-filter-label">{{ Lang::get('ledger::list.filter.from') }}</label>
                <x-core::date-input field-id="search-filter-after" wire:model.live="filterAfter" :aria-label="Lang::get('ledger::list.filter.from')" />
                <label for="search-filter-before" class="srch-filter-label mt-1">{{ Lang::get('ledger::list.filter.to') }}</label>
                <x-core::date-input field-id="search-filter-before" wire:model.live="filterBefore" :aria-label="Lang::get('ledger::list.filter.to')" />
            </div>
        </div>
    </div>
</div>

{{-- ─── Account chip ───────────────────────────────────────────────────── --}}
@if (! empty($availableAccounts ?? []))
    <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
        <span class="srch-chip {{ ! empty($filterAccounts ?? []) ? 'srch-chip--active' : '' }}">
            <button type="button" class="srch-chip-toggle" x-on:click="open = !open" :aria-expanded="open">
                @if (! empty($filterAccounts ?? []))
                    @php
                        $acctCount = count($filterAccounts);
                        $acctCounted = Lang::choice('ledger::list.filter.acct', $acctCount, ['count' => $acctCount]);
                        $acctLabel = $acctCount === 1
                            ? collect($availableAccounts)->firstWhere('id', (int) $filterAccounts[0])['name'] ?? $acctCounted
                            : $acctCounted;
                    @endphp
                    {{ $acctLabel }}
                @else
                    {{ Lang::get('ledger::list.filter.account') }} &#9662;
                @endif
            </button>
            @if (! empty($filterAccounts ?? []))
                <button
                    type="button"
                    wire:click.stop="$set('filterAccounts', [])"
                    class="srch-chip-close"
                    aria-label="{{ Lang::get('ledger::list.filter.remove_account_aria') }}"
                >&times;</button>
            @endif
        </span>
        <div
            x-show="open" x-cloak
            x-on:click.outside="open = false"
            x-transition
            class="srch-popover"
            role="dialog"
            aria-label="{{ Lang::get('ledger::list.filter.account_dialog') }}"
        >
            <div class="srch-popover-inner">
                @foreach ($availableAccounts ?? [] as $account)
                    <label class="srch-check-row">
                        <input
                            type="checkbox"
                            wire:model.live="filterAccounts"
                            value="{{ $account['id'] }}"
                            class="srch-checkbox"
                        />
                        <span>{{ $account['name'] }}</span>
                        <span class="srch-chip-meta">{{ $account['currency'] }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>
@endif

{{-- ─── Amount chip ────────────────────────────────────────────────────── --}}
<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    @php
        // The reader's currency decides the SCALE as well as the symbol, on
        // the same footing SearchQuery::applyAmountFilters() reads the bound
        // at: parsed at a hundredth, a yen reader's "5000" was labelled
        // Y500,000 over a list filtered at Y5,000.
        $amountChipCurrency = BaseCurrency::value();

        $amountActive = ($filterAmountMin ?? '') !== '' || ($filterAmountMax ?? '') !== '' || ($filterAmountDir ?? AmountDirection::Both->value) !== AmountDirection::Both->value;
        $amountLabel = Lang::get('ledger::list.filter.amount').' &#9662;';
        if ($amountActive) {
            if (($filterAmountDir ?? AmountDirection::Both->value) === AmountDirection::In->value) {
                $amountLabel = Lang::get('ledger::list.filter.dir_in');
            } elseif (($filterAmountDir ?? AmountDirection::Both->value) === AmountDirection::Out->value) {
                $amountLabel = Lang::get('ledger::list.filter.dir_out');
            }
            if (($filterAmountMin ?? '') !== '') {
                $amountLabel .= ' &gt; '.Money::ofMinor(
                    MoneyInput::tryToMinor((string) ($filterAmountMin ?? ''), $amountChipCurrency) ?? 0,
                    $amountChipCurrency,
                )->format();
            }
            if (($filterAmountMax ?? '') !== '') {
                $amountLabel .= ' &lt; '.Money::ofMinor(
                    MoneyInput::tryToMinor((string) ($filterAmountMax ?? ''), $amountChipCurrency) ?? 0,
                    $amountChipCurrency,
                )->format();
            }
        }
    @endphp
    <span class="srch-chip {{ $amountActive ? 'srch-chip--active' : '' }}">
        <button type="button" class="srch-chip-toggle" x-on:click="open = !open" :aria-expanded="open">
            {!! $amountLabel !!}
        </button>
        @if ($amountActive)
            <button
                type="button"
                wire:click.stop="$set('filterAmountMin', ''); $set('filterAmountMax', ''); $set('filterAmountDir', '{{ AmountDirection::Both->value }}')"
                class="srch-chip-close"
                aria-label="{{ Lang::get('ledger::list.filter.remove_amount_aria') }}"
            >&times;</button>
        @endif
    </span>
    <div
        x-show="open" x-cloak
        x-on:click.outside="open = false"
        x-transition
        class="srch-popover"
        role="dialog"
        aria-label="{{ Lang::get('ledger::list.filter.amount_dialog') }}"
    >
        <div class="srch-popover-inner">
            {{-- Direction (in / out / both) --}}
            <div class="srch-dir-group mb-3">
                @foreach ([AmountDirection::Both->value => 'dir_both', AmountDirection::In->value => 'dir_in', AmountDirection::Out->value => 'dir_out'] as $val => $dirKey)
                    <label class="srch-radio-row">
                        <input
                            type="radio"
                            wire:model.live="filterAmountDir"
                            value="{{ $val }}"
                            class="srch-radio"
                        />
                        <span>{{ Lang::get('ledger::list.filter.'.$dirKey) }}</span>
                    </label>
                @endforeach
            </div>
            <div class="srch-amount-range">
                <input
                    type="number"
                    wire:model.live="filterAmountMin"
                    min="0"
                    step="{{ MoneyInput::decimalPlaces($amountChipCurrency) === 0 ? '1' : '0.01' }}"
                    placeholder="{{ Lang::get('ledger::list.filter.min') }}"
                    class="srch-amount-input"
                    aria-label="{{ Lang::get('ledger::list.filter.min_aria') }}"
                />
                <span class="srch-date-sep">–</span>
                <input
                    type="number"
                    wire:model.live="filterAmountMax"
                    min="0"
                    step="{{ MoneyInput::decimalPlaces($amountChipCurrency) === 0 ? '1' : '0.01' }}"
                    placeholder="{{ Lang::get('ledger::list.filter.max') }}"
                    class="srch-amount-input"
                    aria-label="{{ Lang::get('ledger::list.filter.max_aria') }}"
                />
            </div>
        </div>
    </div>
</div>

{{-- ─── Category chip ──────────────────────────────────────────────────── --}}
@if (! empty($availableCategories ?? []))
    <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
        @php
            // "No category" is one of the buckets this chip can hold, so it
            // counts towards the chip like any other: a report row opened on it
            // otherwise narrowed the list with nothing on screen saying so.
            $catNoneOn = (bool) ($filterUncategorized ?? false);
            $catCount = count($filterCategories ?? []) + ($catNoneOn ? 1 : 0);
            $catCounted = Lang::choice('ledger::list.filter.cat', $catCount, ['count' => $catCount]);
            $catLabel = match (true) {
                $catCount !== 1 => $catCounted,
                $catNoneOn => Lang::get('ledger::common.uncategorized'),
                default => collect($availableCategories)->firstWhere('id', (int) $filterCategories[0])['name'] ?? $catCounted,
            };
        @endphp
        <span class="srch-chip {{ $catCount > 0 ? 'srch-chip--active' : '' }}">
            <button type="button" class="srch-chip-toggle" x-on:click="open = !open" :aria-expanded="open">
                @if ($catCount > 0)
                    {{ $catLabel }}
                @else
                    {{ Lang::get('ledger::list.filter.category') }} &#9662;
                @endif
            </button>
            @if ($catCount > 0)
                <button
                    type="button"
                    wire:click.stop="clearCategoryFilter"
                    class="srch-chip-close"
                    aria-label="{{ Lang::get('ledger::list.filter.remove_category_aria') }}"
                >&times;</button>
            @endif
        </span>
        <div
            x-show="open" x-cloak
            x-on:click.outside="open = false"
            x-transition
            class="srch-popover"
            role="dialog"
            aria-label="{{ Lang::get('ledger::list.filter.category_dialog') }}"
        >
            <div class="srch-popover-inner">
                <label class="srch-check-row">
                    <input type="checkbox" wire:model.live="filterUncategorized" class="srch-checkbox" />
                    <span>{{ Lang::get('ledger::common.uncategorized') }}</span>
                </label>
                @foreach ($availableCategories ?? [] as $category)
                    <label class="srch-check-row">
                        <input
                            type="checkbox"
                            wire:model.live="filterCategories"
                            value="{{ $category['id'] }}"
                            class="srch-checkbox"
                        />
                        <span>{{ $category['name'] }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>
@endif
