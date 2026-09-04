@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Support\Fmt')
@use('Modules\Ledger\Public\Enums\Direction')
@use('Modules\Ledger\Public\Services\BaseCurrency')
@php
    use Modules\Ledger\Public\ValueObjects\Money;
    use Modules\Ledger\Public\ValueObjects\MoneyInput;

    // A row carries the currency it settled in; the reader's base is only the
    // fallback for a row that lost one.
    $fmt = static fn (int $minor, mixed $currency = null): string => Money::ofMinor(
        $minor,
        is_string($currency) && $currency !== '' ? $currency : BaseCurrency::value(),
    )->format();

    // Tax state map: array<int, array{taxTagged: bool, taxCategoryShortName: ?string}>
    $taxState ??= [];
@endphp

<div class="mx-auto max-w-3xl px-4 py-6">
    {{-- Tax tag picker — rendered once for the whole page (not per-row). --}}
    @include('tax::components.tax-tag-popover')
    <header class="mb-8">
        <x-core::page-heading>{{ Lang::get('cashbook::cash-book.heading') }}</x-core::page-heading>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('cashbook::cash-book.intro') }}
        </p>
    </header>

    <form wire:submit="add" class="rounded-xl border border-slate-200 bg-white p-6 space-y-4 dark:border-slate-800 dark:bg-slate-950">
        <div role="radiogroup" aria-label="{{ Lang::get('cashbook::cash-book.direction') }}" class="inline-flex rounded-md border border-slate-200 dark:border-slate-700 overflow-hidden">
            @foreach ([Direction::Expense->value => Lang::get('cashbook::cash-book.expense'), Direction::Income->value => Lang::get('cashbook::cash-book.income')] as $value => $label)
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
            <x-core::form-field
                name="amount"
                field-id="cb-amount"
                :label="Lang::get('cashbook::cash-book.amount', ['symbol' => Money::symbolFor($entryCurrency)])"
                inputmode="{{ MoneyInput::decimalPlaces($entryCurrency) === 0 ? 'numeric' : 'decimal' }}"
                wire:model="amount"
                :placeholder="MoneyInput::formatAbsMinor(0, $entryCurrency)"
            />
            <div class="space-y-1">
                <label for="cb-date" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('cashbook::cash-book.date') }}</label>
                <x-core::date-input field-id="cb-date" wire:model="date" :aria-label="Lang::get('cashbook::cash-book.date')" />
            </div>
            <x-core::form-field
                name="counterparty"
                field-id="cb-counterparty"
                :label="Lang::get('cashbook::cash-book.counterparty')"
                wire:model="counterparty"
                :placeholder="Lang::get('cashbook::cash-book.counterparty_placeholder')"
            />
            {{-- "(optional)" rides in the label text rather than a tinted span:
                 the component escapes its label, and a text prop that has to be
                 handed markup is not a text prop. --}}
            <x-core::form-field
                name="categoryId"
                field-id="cb-category"
                type="select"
                :label="Lang::get('cashbook::cash-book.category').' '.Lang::get('cashbook::cash-book.optional')"
                wire:model="categoryId"
            >
                <option value="">{{ Lang::get('cashbook::cash-book.uncategorized') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </x-core::form-field>
        </div>

        <x-core::form-field
            name="description"
            field-id="cb-description"
            :label="Lang::get('cashbook::cash-book.note').' '.Lang::get('cashbook::cash-book.optional')"
            wire:model="description"
        />

        @if ($error !== '')
            <p class="text-sm text-rose-600 dark:text-rose-500">{{ $error }}</p>
        @endif

        <button type="submit" class="w-full rounded-md bg-emerald-700 py-2 text-sm font-medium text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:bg-emerald-700 dark:hover:bg-emerald-800">
            {{ Lang::get('cashbook::cash-book.add_entry') }}
        </button>
    </form>

    <section class="mt-8">
        <h2 class="mb-3 text-xs uppercase tracking-wide text-[var(--color-text-faint)]">{{ Lang::get('cashbook::cash-book.manual_entries') }}</h2>
        @if (count($entries) === 0)
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('cashbook::cash-book.no_entries') }}</p>
        @else
            {{-- Phone (<768px): .card-list-item per entry --}}
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
                            <span class="primary">{{ $entry->counterparty_name ?? '—' }}</span>
                            <span class="secondary">
                                {{ Fmt::shortDate((string) $entry->posted_at) }}
                                @if ($entry->category_name)· {{ $entry->category_name }}@endif
                            </span>
                        </div>
                        <div style="flex: 0 0 auto; text-align: right; display: flex; align-items: center; gap: var(--space-2);">
                            {{-- Tax badge: always-visible at phone width. --}}
                            <x-tax::tax-badge :transaction="$entryTaxRow" :showAlways="true" />
                            <span
                                class="amount{{ $isPositive ? ' positive' : '' }}"
                                style="{{ $isPositive ? 'color: var(--color-emerald)' : '' }}"
                            >{{ $fmt((int) $entry->settled_amount_minor, $entry->settled_currency) }}</span>
                            {{-- Delete action always-visible at phone width --}}
                            <x-core::emoji-action
                                tone="danger"
                                :label="Lang::get('cashbook::cash-book.delete_entry')"
                                :caption="Lang::get('cashbook::cash-book.delete_entry_caption')"
                                wire:click="confirmDelete('{{ (int) $entry->id }}')"
                            >🗑️</x-core::emoji-action>
                        </div>

                        @if ($deletingEntryId === (int) $entry->id)
                            <x-core::confirm-strip
                                class="mt-2"
                                :question="Lang::get('cashbook::cash-book.delete_confirm')"
                                :cancel-label="Lang::get('cashbook::cash-book.delete_keep')"
                                :confirm-label="Lang::get('cashbook::cash-book.delete')"
                                cancel="cancelDelete"
                                :confirm="'delete(\''.(int) $entry->id.'\')'"
                            />
                        @endif
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
                            <p class="truncate text-slate-900 dark:text-slate-100">{{ $entry->counterparty_name ?? '—' }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ Fmt::shortDate((string) $entry->posted_at) }}
                                @if ($entry->category_name)· {{ $entry->category_name }}@endif
                            </p>
                        </div>
                        {{-- Tax badge: hover-reveal on desktop. --}}
                        <x-tax::tax-badge :transaction="$dEntryTaxRow" :showAlways="false" />
                        <span class="shrink-0 font-medium {{ (int) $entry->settled_amount_minor < 0 ? 'text-slate-900 dark:text-slate-100' : 'text-emerald-700 dark:text-emerald-400' }}" style="font-variant-numeric: tabular-nums;">
                            {{ $fmt((int) $entry->settled_amount_minor, $entry->settled_currency) }}
                        </span>
                        @if ($deletingEntryId === $dEntryId)
                            <x-core::confirm-strip
                                class="shrink-0"
                                :question="Lang::get('cashbook::cash-book.delete_confirm')"
                                :cancel-label="Lang::get('cashbook::cash-book.delete_keep')"
                                :confirm-label="Lang::get('cashbook::cash-book.delete')"
                                cancel="cancelDelete"
                                :confirm="'delete(\''.(int) $entry->id.'\')'"
                            />
                        @else
                            <button
                                type="button"
                                wire:click="confirmDelete('{{ (int) $entry->id }}')"
                                aria-label="{{ Lang::get('cashbook::cash-book.delete_entry') }}"
                                class="shrink-0 rounded-md px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 hover:text-rose-600 dark:hover:bg-slate-800 dark:hover:text-rose-400 dark:text-slate-400"
                            >{{ Lang::get('cashbook::cash-book.delete') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="mt-4">{{ $entries->links() }}</div>
        @endif
    </section>
</div>
