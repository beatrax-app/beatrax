@use('Modules\Core\Public\Support\Lang')
{{--
    /budgets — the rebuilt zero-based envelope grid (Req 3/5/6/7/8/12).

    Assign-every-euro grid sourced from CarryoverQuery's genesis-to-target
    fold: per-envelope assigned (inline-editable) / spent / available, a
    sticky "Ready to assign" header (emerald ≥ 0 / rose < 0, never blocking),
    month navigation, a "Copy last month" auto-fill banner, and a per-row
    overspend-mode toggle. Calm-slate room per 13.2-UI-SPEC.md — same
    max-w-5xl / rounded-lg border chrome family as the rest of the app, no
    new palette. Money figures are tabular-nums throughout.
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor, string $currency = 'EUR'): string => Money::ofMinor($minor, $currency)
        ->format($currency === 'EUR' ? 'nl_NL' : 'en_US');
@endphp

<div class="mx-auto max-w-5xl px-4 py-12">
    {{-- Header row: title + subtitle, month nav on the right (D-20) --}}
    <header class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('budgets::messages.page.title') }}</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('budgets::messages.page.subtitle', ['period' => $period->label]) }}
            </p>
        </div>
        <div class="flex shrink-0 items-center gap-1">
            <button
                type="button"
                wire:click="prevPeriod"
                @disabled(! $canGoPrevious)
                aria-label="{{ Lang::get('budgets::messages.nav.prev_aria') }}"
                class="inline-flex h-10 w-10 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:opacity-40 disabled:cursor-not-allowed dark:hover:bg-slate-800 dark:hover:text-slate-100 dark:text-slate-400"
            >&lsaquo;</button>
            <span class="px-2 text-sm text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $period->label }}</span>
            <button
                type="button"
                wire:click="nextPeriod"
                @disabled(! $canGoNext)
                aria-label="{{ Lang::get('budgets::messages.nav.next_aria') }}"
                class="inline-flex h-10 w-10 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:opacity-40 disabled:cursor-not-allowed dark:hover:bg-slate-800 dark:hover:text-slate-100 dark:text-slate-400"
            >&rsaquo;</button>
        </div>
    </header>

    {{-- Sticky to-budget header (D-23) --}}
    @php
        $toBudgetColour = $toBudgetMinor >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400';
    @endphp
    <div class="sticky top-0 z-10 mb-6 rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.ready.label') }}</p>
        <p class="mt-1 text-3xl font-semibold {{ $toBudgetColour }}" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
            {{ $fmt($toBudgetMinor) }}
        </p>
        @if ($toBudgetMinor < 0)
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('budgets::messages.ready.overassigned') }}
            </p>
        @endif
    </div>

    {{-- Empty-state / copy-last-month banner (Req 6) --}}
    @if (! ($rows !== [] && collect($rows)->contains(static fn ($row): bool => $row->assignedMinor > 0 || $row->spentMinor > 0)))
        <div class="mb-6 rounded-lg border p-4 {{ $showCopyBanner ? 'border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/20' : 'border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700' }}">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('budgets::messages.empty.nothing_assigned_heading') }}</h2>
            <p class="mt-1 text-sm {{ $showCopyBanner ? 'text-amber-700 dark:text-amber-400' : 'text-slate-500 dark:text-slate-400' }}">
                @if ($showCopyBanner)
                    {{ Lang::get('budgets::messages.empty.copy_hint') }}
                @else
                    {{ Lang::get('budgets::messages.empty.first_hint') }}
                @endif
            </p>
            @if ($showCopyBanner)
                <button
                    type="button"
                    wire:click="copyLastMonth"
                    class="mt-3 inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                >{{ Lang::get('budgets::messages.empty.copy_button') }}</button>
            @endif
        </div>
    @endif

    {{-- Envelope grid --}}
    @if (count($rows) === 0)
        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('budgets::messages.no_categories.heading') }}</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.no_categories.body') }}</p>
        </div>
    @else
        {{-- Desktop grid (>=768px) --}}
        <table class="hidden w-full text-left text-sm md:table">
            <thead class="border-b border-slate-200 bg-slate-50 dark:bg-slate-900 dark:border-slate-700">
                <tr>
                    <th class="px-4 py-2 text-xs font-normal uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.table.category') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-normal uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.table.assigned') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-normal uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.table.spent') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-normal uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.table.available') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-normal uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.table.if_overspent') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-normal uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.table.notify_at') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-normal uppercase tracking-wide text-slate-500 dark:text-slate-400"><span class="sr-only">{{ Lang::get('budgets::messages.table.actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="group border-b border-slate-100 dark:border-slate-800" wire:key="envelope-row-{{ $row->categoryId }}">
                        <td class="px-4 py-2">
                            <span class="truncate text-slate-900 dark:text-slate-100">{{ $row->categoryName }}</span>
                            @if ($row->overspendMode === \Modules\Budgets\Public\Enums\OverspendMode::CarryNegative->value)
                                <span class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2 py-[2px] text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">{{ Lang::get('budgets::messages.badge.carries_negative') }}</span>
                            @endif
                            @if ($row->nonEurSpentMinor != 0)
                                <span
                                    class="ml-2 inline-flex h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400 align-middle dark:bg-amber-500"
                                    role="img"
                                    aria-label="{{ Lang::get('budgets::messages.badge.non_eur_aria') }}"
                                    title="{{ Lang::get('budgets::messages.badge.non_eur_title') }}"
                                ></span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <input
                                type="text"
                                inputmode="decimal"
                                wire:model="assignedInputs.{{ $row->categoryId }}"
                                wire:keydown.enter="setAssigned({{ $row->categoryId }})"
                                wire:blur="setAssigned({{ $row->categoryId }})"
                                aria-label="{{ Lang::get('budgets::messages.row.assigned_aria', ['category' => $row->categoryName]) }}"
                                placeholder="{{ Lang::get('core::components.amount_placeholder') }}"
                                class="w-24 rounded-md border border-slate-200 bg-white px-2 py-1 text-right text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                style="font-variant-numeric: tabular-nums;"
                            >
                        </td>
                        <td class="px-4 py-2 text-right text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                            {{ $fmt($row->spentMinor, $row->currency) }}
                        </td>
                        <td class="px-4 py-2 text-right font-medium {{ $row->availableMinor < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-slate-100' }}" style="font-variant-numeric: tabular-nums;">
                            {{ $fmt($row->availableMinor, $row->currency) }}
                        </td>
                        <td class="px-4 py-2">
                            <select
                                x-data="{ mode: @js($row->overspendMode) }"
                                x-on:change="if ($event.target.value !== mode) { mode = $event.target.value; $wire.setOverspendMode({{ $row->categoryId }}, mode) }"
                                aria-label="{{ Lang::get('budgets::messages.row.overspend_aria', ['category' => $row->categoryName]) }}"
                                class="rounded-md border border-slate-200 bg-white px-1.5 py-1 text-xs text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-300"
                            >
                                <option value="reduce_to_budget" @selected($row->overspendMode === \Modules\Budgets\Public\Enums\OverspendMode::ReduceToBudget->value)>{{ Lang::get('budgets::messages.overspend.reduce') }}</option>
                                <option value="carry_negative" @selected($row->overspendMode === \Modules\Budgets\Public\Enums\OverspendMode::CarryNegative->value)>{{ Lang::get('budgets::messages.overspend.carry') }}</option>
                            </select>
                        </td>
                        <td class="px-4 py-2 text-right align-top">
                            <div class="inline-flex items-center gap-1">
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    wire:model="thresholdInputs.{{ $row->categoryId }}"
                                    wire:keydown.enter="setNotifyThreshold({{ $row->categoryId }})"
                                    wire:blur="setNotifyThreshold({{ $row->categoryId }})"
                                    aria-label="{{ Lang::get('budgets::messages.row.notify_aria', ['category' => $row->categoryName]) }}"
                                    placeholder="{{ $defaultNotifyThreshold }}"
                                    class="w-14 rounded-md border border-slate-200 bg-white px-2 py-1 text-right text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    style="font-variant-numeric: tabular-nums;"
                                >
                                <span class="text-sm text-slate-500 dark:text-slate-400">%</span>
                            </div>
                            @if (! empty($thresholdErrors[$row->categoryId]))
                                <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $thresholdErrors[$row->categoryId] }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button
                                type="button"
                                wire:click="openMove({{ $row->categoryId }})"
                                x-on:click="$flux.modal('envelope-move').show()"
                                class="hidden text-sm text-slate-400 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 group-hover:inline dark:hover:text-slate-100"
                            >{{ Lang::get('budgets::messages.row.move_money') }}</button>
                        </td>
                    </tr>
                    @if (count($recentMoves[$row->categoryId] ?? []) > 0)
                        <tr class="border-b border-slate-100 dark:border-slate-800" x-data="{ open: false }" wire:key="envelope-history-row-{{ $row->categoryId }}">
                            <td colspan="7" class="px-4 pb-3">
                                <button
                                    type="button"
                                    x-on:click="open = !open"
                                    :aria-expanded="open.toString()"
                                    aria-controls="envelope-history-{{ $row->categoryId }}"
                                    class="text-xs text-slate-400 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-300"
                                >
                                    <span x-show="!open">{{ Lang::get('budgets::messages.history.show') }}</span>
                                    <span x-show="open" x-cloak>{{ Lang::get('budgets::messages.history.hide') }}</span>
                                </button>
                                <div id="envelope-history-{{ $row->categoryId }}" x-show="open" x-collapse class="mt-2 border-t border-slate-100 dark:border-slate-800">
                                    <ul>
                                        @foreach ($recentMoves[$row->categoryId] as $move)
                                            <li class="flex items-center justify-between gap-4 py-2 text-sm">
                                                <div class="min-w-0">
                                                    <span class="text-xs text-slate-400 dark:text-slate-500 tabular-nums">{{ substr($move->createdAt, 0, 10) }}</span>
                                                    <span class="ml-2 text-sm text-slate-500 dark:text-slate-400">{{ $move->direction === 'in' ? Lang::get('budgets::messages.history.moved_from', ['category' => $move->counterpartCategoryName]) : Lang::get('budgets::messages.history.moved_to', ['category' => $move->counterpartCategoryName]) }}</span>
                                                </div>
                                                <div class="flex shrink-0 items-center gap-3">
                                                    <span class="text-sm tabular-nums {{ $move->direction === 'in' ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400' }}">
                                                        {{ $move->direction === 'in' ? '+' : '' }}{{ $fmt(abs($move->amountMinor), $row->currency) }}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        wire:click="undoMove({{ $move->id }})"
                                                        class="text-xs text-slate-400 hover:text-rose-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-rose-400"
                                                    >{{ Lang::get('budgets::messages.history.undo') }}</button>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>

        {{-- Phone stacked list (<768px) --}}
        <div class="rounded-lg border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700 overflow-hidden md:hidden">
            @foreach ($rows as $row)
                <div class="card-list-item" wire:key="envelope-phone-{{ $row->categoryId }}">
                    <div class="flex-1 min-w-0">
                        <p class="primary truncate">
                            {{ $row->categoryName }}
                            @if ($row->overspendMode === \Modules\Budgets\Public\Enums\OverspendMode::CarryNegative->value)
                                <span class="ml-1 inline-flex items-center rounded-full bg-amber-100 px-2 py-[2px] text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">{{ Lang::get('budgets::messages.badge.carries_negative') }}</span>
                            @endif
                            @if ($row->nonEurSpentMinor != 0)
                                <span
                                    class="ml-1 inline-flex h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400 align-middle dark:bg-amber-500"
                                    role="img"
                                    aria-label="{{ Lang::get('budgets::messages.badge.non_eur_aria') }}"
                                    title="{{ Lang::get('budgets::messages.badge.non_eur_title') }}"
                                ></span>
                            @endif
                        </p>
                        <p class="secondary" style="font-variant-numeric: tabular-nums;">
                            {{ Lang::get('budgets::messages.phone.spent', ['amount' => $fmt($row->spentMinor, $row->currency)]) }}
                            · <span class="{{ $row->availableMinor < 0 ? 'text-rose-600 dark:text-rose-400' : '' }}">{{ Lang::get('budgets::messages.phone.available', ['amount' => $fmt($row->availableMinor, $row->currency)]) }}</span>
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <input
                            type="text"
                            inputmode="decimal"
                            wire:model="assignedInputs.{{ $row->categoryId }}"
                            wire:keydown.enter="setAssigned({{ $row->categoryId }})"
                            wire:blur="setAssigned({{ $row->categoryId }})"
                            aria-label="{{ Lang::get('budgets::messages.row.assigned_aria', ['category' => $row->categoryName]) }}"
                            placeholder="{{ Lang::get('core::components.amount_placeholder') }}"
                            class="amount w-20 rounded-md border border-slate-200 bg-white px-2 py-1 text-right text-sm text-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                            style="font-variant-numeric: tabular-nums;"
                        >
                        <label class="flex items-center gap-1 text-xs text-slate-400 dark:text-slate-500">
                            <span>{{ Lang::get('budgets::messages.phone.notify_at') }}</span>
                            <input
                                type="text"
                                inputmode="numeric"
                                wire:model="thresholdInputs.{{ $row->categoryId }}"
                                wire:keydown.enter="setNotifyThreshold({{ $row->categoryId }})"
                                wire:blur="setNotifyThreshold({{ $row->categoryId }})"
                                aria-label="{{ Lang::get('budgets::messages.row.notify_aria', ['category' => $row->categoryName]) }}"
                                placeholder="{{ $defaultNotifyThreshold }}"
                                class="w-12 rounded-md border border-slate-200 bg-white px-1.5 py-0.5 text-right text-xs text-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                style="font-variant-numeric: tabular-nums;"
                            >
                            <span>%</span>
                        </label>
                        @if (! empty($thresholdErrors[$row->categoryId]))
                            <p class="text-xs text-rose-600 dark:text-rose-400">{{ $thresholdErrors[$row->categoryId] }}</p>
                        @endif
                    </div>
                    <button
                        type="button"
                        wire:click="openMove({{ $row->categoryId }})"
                        x-on:click="$dispatch('open-sheet', { name: 'envelope-move' })"
                        class="text-xs text-slate-400 hover:text-slate-900 focus:outline-none min-w-[44px] min-h-[44px] flex items-center justify-center dark:hover:text-slate-100"
                     title="{{ Lang::get('budgets::messages.row.move') }}"><span aria-hidden="true" class="sm:hidden">⇄</span><span class="sr-only sm:not-sr-only">{{ Lang::get('budgets::messages.row.move') }}</span></button>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ------------------------------------------------------------------- --}}
    {{-- Move-money modal (Req 5, D-19) — structural clone of Pots' pot-move --}}
    {{-- ------------------------------------------------------------------- --}}
    <flux:modal name="envelope-move" dismissible>
        <div class="pt-[44px]" style="max-width: 480px;">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                {{ Lang::get('budgets::messages.modal.move_from', ['name' => $moveFromCategory?->categoryName ?? Lang::get('budgets::messages.modal.move_from_fallback')]) }}
            </h2>
            <form wire:submit="moveMoney" class="mt-6 space-y-4">
                <div>
                    <label for="envelope-move-to" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.modal.move_to') }}</label>
                    <select
                        id="envelope-move-to"
                        wire:model="moveToCategoryId"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    >
                        <option value="">{{ count($moveDestinations) === 0 ? Lang::get('budgets::messages.modal.no_other') : Lang::get('budgets::messages.modal.select') }}</option>
                        @foreach ($moveDestinations as $dest)
                            <option value="{{ $dest->categoryId }}">{{ $dest->categoryName }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="envelope-move-amount" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.modal.amount') }}</label>
                    <input
                        type="text"
                        id="envelope-move-amount"
                        wire:model="moveAmount"
                        inputmode="decimal"
                        placeholder="{{ Lang::get('core::components.amount_placeholder') }}"
                        @if ($moveError !== '') aria-invalid="true" aria-describedby="envelope-move-amount-error" @endif
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                        style="font-variant-numeric: tabular-nums;"
                    >
                    @if ($moveFromCategory !== null)
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500" style="font-variant-numeric: tabular-nums;">
                            {{ Lang::get('budgets::messages.modal.available_in', ['name' => $moveFromCategory->categoryName, 'amount' => $fmt($moveFromCategory->availableMinor, $moveFromCategory->currency)]) }}
                        </p>
                    @endif
                    @if ($moveError !== '')
                        <p id="envelope-move-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $moveError }}</p>
                    @endif
                </div>
                <div>
                    <label for="envelope-move-memo" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.modal.note') }}</label>
                    <input
                        type="text"
                        id="envelope-move-memo"
                        wire:model="moveMemo"
                        placeholder="{{ Lang::get('budgets::messages.modal.note_placeholder') }}"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    >
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="$dispatch('modal-close', { name: 'envelope-move' })" class="rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400">{{ Lang::get('budgets::messages.modal.cancel') }}</button>
                    <button type="submit" @disabled(count($moveDestinations) === 0) class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white disabled:opacity-50 disabled:cursor-not-allowed">{{ Lang::get('budgets::messages.modal.move_funds') }}</button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- Phone bottom sheet: Move envelope money --}}
    <x-core::bottom-sheet name="envelope-move" :title="Lang::get('budgets::messages.modal.move_funds')">
        <form wire:submit="moveMoney" class="space-y-4">
            <div>
                <label for="envelope-move-to-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.modal.move_to') }}</label>
                <select
                    id="envelope-move-to-sheet"
                    wire:model="moveToCategoryId"
                    style="font-size: 16px;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                >
                    <option value="">{{ count($moveDestinations) === 0 ? Lang::get('budgets::messages.modal.no_other') : Lang::get('budgets::messages.modal.select') }}</option>
                    @foreach ($moveDestinations as $dest)
                        <option value="{{ $dest->categoryId }}">{{ $dest->categoryName }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="envelope-move-amount-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.modal.amount') }}</label>
                <input
                    type="text"
                    id="envelope-move-amount-sheet"
                    wire:model="moveAmount"
                    inputmode="decimal"
                    placeholder="{{ Lang::get('core::components.amount_placeholder') }}"
                    style="font-size: 16px; font-variant-numeric: tabular-nums;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                >
                @if ($moveError !== '')
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $moveError }}</p>
                @endif
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" @disabled(count($moveDestinations) === 0) class="flex-1 rounded-md bg-slate-900 px-4 py-3 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">{{ Lang::get('budgets::messages.modal.move_funds') }}</button>
                <button type="button" wire:click="$dispatch('modal-close', { name: 'envelope-move' })" class="rounded-md border border-slate-200 px-4 py-3 text-sm font-medium text-slate-500 focus:outline-none dark:border-slate-700">{{ Lang::get('budgets::messages.modal.cancel') }}</button>
            </div>
        </form>
    </x-core::bottom-sheet>
</div>
