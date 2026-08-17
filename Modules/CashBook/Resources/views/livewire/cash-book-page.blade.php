@use('Modules\Core\Public\Support\Lang')
@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor): string => Money::ofMinor($minor, 'EUR')->format('nl_NL');
    $input = 'block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100';

    // Tax state map: array<int, array{taxTagged: bool, taxCategoryShortName: ?string}>
    $taxState ??= [];
@endphp

<div class="mx-auto max-w-3xl px-4 py-12">
    {{-- Tax tag picker — rendered once for the whole page (not per-row). --}}
    @include('tax::components.tax-tag-popover')
    <header class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('cashbook::cash-book.heading') }}</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('cashbook::cash-book.intro') }}
        </p>
    </header>

    <form wire:submit="add" class="rounded-xl border border-slate-200 bg-white p-6 space-y-4 dark:border-slate-800 dark:bg-slate-950">
        <div role="radiogroup" aria-label="{{ Lang::get('cashbook::cash-book.direction') }}" class="inline-flex rounded-md border border-slate-200 dark:border-slate-700 overflow-hidden">
            @foreach (['expense' => Lang::get('cashbook::cash-book.expense'), 'income' => Lang::get('cashbook::cash-book.income')] as $value => $label)
                <button
                    type="button"
                    role="radio"
                    aria-checked="{{ $direction === $value ? 'true' : 'false' }}"
                    wire:click="$set('direction', '{{ $value }}')"
                    @class([
                        'px-4 py-1.5 text-sm focus:outline-none',
                        'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' => $direction === $value,
                        'bg-white text-slate-900 hover:bg-slate-50 dark:bg-slate-950 dark:text-slate-100 dark:hover:bg-slate-900' => $direction !== $value,
                    ])
                >{{ $label }}</button>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="space-y-1">
                <label for="cb-amount" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('cashbook::cash-book.amount') }}</label>
                <input id="cb-amount" type="text" inputmode="decimal" wire:model="amount" placeholder="{{ Lang::get('core::components.amount_placeholder') }}" class="{{ $input }}" />
            </div>
            <div class="space-y-1">
                <label for="cb-date" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('cashbook::cash-book.date') }}</label>
                <x-core::date-input field-id="cb-date" wire:model="date" />
            </div>
            <div class="space-y-1">
                <label for="cb-counterparty" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('cashbook::cash-book.counterparty') }}</label>
                <input id="cb-counterparty" type="text" wire:model="counterparty" placeholder="{{ Lang::get('cashbook::cash-book.counterparty_placeholder') }}" class="{{ $input }}" />
            </div>
            <div class="space-y-1">
                <label for="cb-category" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('cashbook::cash-book.category') }} <span class="text-slate-400">{{ Lang::get('cashbook::cash-book.optional') }}</span></label>
                <select id="cb-category" wire:model="categoryId" class="{{ $input }}">
                    <option value="">{{ Lang::get('cashbook::cash-book.uncategorized') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="space-y-1">
            <label for="cb-description" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('cashbook::cash-book.note') }} <span class="text-slate-400">{{ Lang::get('cashbook::cash-book.optional') }}</span></label>
            <input id="cb-description" type="text" wire:model="description" class="{{ $input }}" />
        </div>

        @if ($error !== '')
            <p class="text-sm text-rose-600 dark:text-rose-500">{{ $error }}</p>
        @endif

        <button type="submit" class="w-full rounded-md bg-emerald-600 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400">
            {{ Lang::get('cashbook::cash-book.add_entry') }}
        </button>
    </form>

    <section class="mt-8">
        <h2 class="mb-3 text-xs uppercase tracking-wide text-[var(--color-text-faint)]">{{ Lang::get('cashbook::cash-book.manual_entries') }}</h2>
        @if (count($entries) === 0)
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('cashbook::cash-book.no_entries') }}</p>
        @else
            {{-- Phone (<768px): .card-list-item per entry (D-06 daily-driver) --}}
            <div class="phone-only">
                @foreach ($entries as $entry)
                    @php
                        $isPositive = (int) $entry->settled_amount_minor > 0;
                        $entryId = (int) $entry->id;
                        $entryTaxState = $taxState[$entryId] ?? ['taxTagged' => false, 'taxCategoryShortName' => null];
                        $entryTaxRow = ['id' => $entryId, 'taxTagged' => $entryTaxState['taxTagged'], 'taxCategoryShortName' => $entryTaxState['taxCategoryShortName']];
                    @endphp
                    <div wire:key="manual-phone-{{ $entry->id }}" class="card-list-item" style="display: flex; justify-content: space-between;">
                        <div style="min-width: 0; flex: 1 1 auto;">
                            <span class="primary">{{ $entry->counterparty_name }}</span>
                            <span class="secondary">
                                {{ \Illuminate\Support\Str::limit((string) $entry->posted_at, 10, '') }}
                                @if ($entry->category_name)· {{ $entry->category_name }}@endif
                            </span>
                        </div>
                        <div style="flex: 0 0 auto; text-align: right; display: flex; align-items: center; gap: var(--space-2);">
                            {{-- Tax badge: always-visible at phone width (D-21). --}}
                            <x-tax::tax-badge :transaction="$entryTaxRow" :showAlways="true" />
                            <span
                                class="amount{{ $isPositive ? ' positive' : '' }}"
                                style="{{ $isPositive ? 'color: var(--color-emerald)' : '' }}"
                            >{{ $fmt((int) $entry->settled_amount_minor) }}</span>
                            {{-- Delete action always-visible at phone width (D-12) --}}
                            <button
                                type="button"
                                wire:click="delete({{ (int) $entry->id }})"
                                aria-label="{{ Lang::get('cashbook::cash-book.delete_entry') }}"
                                style="background: transparent; border: 0; color: var(--color-text-muted); font-size: var(--text-xs); cursor: pointer; padding: var(--space-2); min-width: 44px; min-height: 44px;"
                            >✕</button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop (>=768px): existing list layout --}}
            <ul class="desktop-only divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                @foreach ($entries as $entry)
                    @php
                        $dEntryId = (int) $entry->id;
                        $dEntryTaxState = $taxState[$dEntryId] ?? ['taxTagged' => false, 'taxCategoryShortName' => null];
                        $dEntryTaxRow = ['id' => $dEntryId, 'taxTagged' => $dEntryTaxState['taxTagged'], 'taxCategoryShortName' => $dEntryTaxState['taxCategoryShortName']];
                    @endphp
                    <li wire:key="manual-{{ $entry->id }}" class="group flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-slate-900 dark:text-slate-100">{{ $entry->counterparty_name }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ \Illuminate\Support\Str::limit((string) $entry->posted_at, 10, '') }}
                                @if ($entry->category_name)· {{ $entry->category_name }}@endif
                            </p>
                        </div>
                        {{-- Tax badge: hover-reveal on desktop (D-19/D-20). --}}
                        <x-tax::tax-badge :transaction="$dEntryTaxRow" :showAlways="false" />
                        <span class="shrink-0 font-medium {{ (int) $entry->settled_amount_minor < 0 ? 'text-slate-900 dark:text-slate-100' : 'text-emerald-600 dark:text-emerald-400' }}" style="font-variant-numeric: tabular-nums;">
                            {{ $fmt((int) $entry->settled_amount_minor) }}
                        </span>
                        <button
                            type="button"
                            wire:click="delete({{ (int) $entry->id }})"
                            aria-label="{{ Lang::get('cashbook::cash-book.delete_entry') }}"
                            class="shrink-0 rounded-md px-2 py-1 text-xs text-slate-400 hover:bg-slate-100 hover:text-rose-600 dark:hover:bg-slate-800 dark:hover:text-rose-400"
                        >{{ Lang::get('cashbook::cash-book.delete') }}</button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
