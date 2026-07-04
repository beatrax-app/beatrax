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
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Budgets</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                Assign every euro — {{ $period->label }}.
            </p>
        </div>
        <div class="flex shrink-0 items-center gap-1">
            <button
                type="button"
                wire:click="prevPeriod"
                @disabled(! $canGoPrevious)
                aria-label="Previous period"
                class="inline-flex h-10 w-10 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:opacity-40 disabled:cursor-not-allowed dark:hover:bg-slate-800 dark:hover:text-slate-100 dark:text-slate-400"
            >&lsaquo;</button>
            <span class="px-2 text-sm text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $period->label }}</span>
            <button
                type="button"
                wire:click="nextPeriod"
                @disabled(! $canGoNext)
                aria-label="Next period"
                class="inline-flex h-10 w-10 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:opacity-40 disabled:cursor-not-allowed dark:hover:bg-slate-800 dark:hover:text-slate-100 dark:text-slate-400"
            >&rsaquo;</button>
        </div>
    </header>

    {{-- Sticky to-budget header (D-23) --}}
    @php
        $toBudgetColour = $toBudgetMinor >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400';
    @endphp
    <div class="sticky top-0 z-10 mb-6 rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Ready to assign</p>
        <p class="mt-1 text-3xl font-semibold {{ $toBudgetColour }}" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
            {{ $fmt($toBudgetMinor) }}
        </p>
        @if ($toBudgetMinor < 0)
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                You've assigned more than you have — reduce an envelope or wait for more income.
            </p>
        @endif
    </div>

    {{-- Empty-state / copy-last-month banner (Req 6) --}}
    @if (! ($rows !== [] && collect($rows)->contains(static fn ($row): bool => $row->assignedMinor > 0 || $row->spentMinor > 0)))
        <div class="mb-6 rounded-lg border p-4 {{ $showCopyBanner ? 'border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/20' : 'border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700' }}">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Nothing assigned yet</h2>
            <p class="mt-1 text-sm {{ $showCopyBanner ? 'text-amber-700 dark:text-amber-400' : 'text-slate-500 dark:text-slate-400' }}">
                @if ($showCopyBanner)
                    Copy last month's plan, or click into a cell below to start assigning.
                @else
                    Click into a cell below to start assigning your first month.
                @endif
            </p>
            @if ($showCopyBanner)
                <button
                    type="button"
                    wire:click="copyLastMonth"
                    class="mt-3 inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                >Copy last month</button>
            @endif
        </div>
    @endif

    {{-- Envelope grid --}}
    @if (count($rows) === 0)
        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">No expense categories yet</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Add an expense category to start assigning money to it.</p>
        </div>
    @else
        {{-- Desktop grid (>=768px) --}}
        <table class="hidden w-full text-left text-sm md:table">
            <thead class="border-b border-slate-200 bg-slate-50 dark:bg-slate-900 dark:border-slate-700">
                <tr>
                    <th class="px-4 py-2 text-xs font-normal uppercase tracking-wide text-slate-500 dark:text-slate-400">Category</th>
                    <th class="px-4 py-2 text-right text-xs font-normal uppercase tracking-wide text-slate-500 dark:text-slate-400">Assigned</th>
                    <th class="px-4 py-2 text-right text-xs font-normal uppercase tracking-wide text-slate-500 dark:text-slate-400">Spent</th>
                    <th class="px-4 py-2 text-right text-xs font-normal uppercase tracking-wide text-slate-500 dark:text-slate-400">Available</th>
                    <th class="px-4 py-2 text-left text-xs font-normal uppercase tracking-wide text-slate-500 dark:text-slate-400">If overspent</th>
                    <th class="px-4 py-2 text-right text-xs font-normal uppercase tracking-wide text-slate-500 dark:text-slate-400"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="group border-b border-slate-100 dark:border-slate-800" wire:key="envelope-row-{{ $row->categoryId }}">
                        <td class="px-4 py-2">
                            <span class="truncate text-slate-900 dark:text-slate-100">{{ $row->categoryName }}</span>
                            @if ($row->overspendMode === 'carry_negative')
                                <span class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2 py-[2px] text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">Carries negative</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <input
                                type="text"
                                inputmode="decimal"
                                wire:model="assignedInputs.{{ $row->categoryId }}"
                                wire:keydown.enter="setAssigned({{ $row->categoryId }})"
                                wire:blur="setAssigned({{ $row->categoryId }})"
                                aria-label="Assigned for {{ $row->categoryName }}"
                                placeholder="0.00"
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
                                x-on:change="$wire.setOverspendMode({{ $row->categoryId }}, $event.target.value)"
                                aria-label="If {{ $row->categoryName }} is overspent"
                                class="rounded-md border border-slate-200 bg-white px-1.5 py-1 text-xs text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-300"
                            >
                                <option value="reduce_to_budget" @selected($row->overspendMode === 'reduce_to_budget')>Reduce next month's ready-to-assign</option>
                                <option value="carry_negative" @selected($row->overspendMode === 'carry_negative')>Carry the negative in this envelope</option>
                            </select>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button
                                type="button"
                                wire:click="openMove({{ $row->categoryId }})"
                                x-on:click="$flux.modal('envelope-move').show()"
                                class="hidden text-sm text-slate-400 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 group-hover:inline dark:hover:text-slate-100"
                            >Move money</button>
                        </td>
                    </tr>
                    @if (count($recentMoves[$row->categoryId] ?? []) > 0)
                        <tr class="border-b border-slate-100 dark:border-slate-800" x-data="{ open: false }">
                            <td colspan="6" class="px-4 pb-3">
                                <button
                                    type="button"
                                    x-on:click="open = !open"
                                    :aria-expanded="open.toString()"
                                    aria-controls="envelope-history-{{ $row->categoryId }}"
                                    class="text-xs text-slate-400 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-300"
                                >
                                    <span x-show="!open">Show history ↓</span>
                                    <span x-show="open" x-cloak>Hide history ↑</span>
                                </button>
                                <div id="envelope-history-{{ $row->categoryId }}" x-show="open" x-collapse class="mt-2 border-t border-slate-100 dark:border-slate-800">
                                    <ul>
                                        @foreach ($recentMoves[$row->categoryId] as $move)
                                            <li class="flex items-center justify-between gap-4 py-2 text-sm">
                                                <div class="min-w-0">
                                                    <span class="text-xs text-slate-400 dark:text-slate-500 tabular-nums">{{ substr($move->createdAt, 0, 10) }}</span>
                                                    <span class="ml-2 text-sm text-slate-500 dark:text-slate-400">{{ $move->direction === 'in' ? 'Moved from '.$move->counterpartCategoryName : 'Moved to '.$move->counterpartCategoryName }}</span>
                                                </div>
                                                <div class="flex shrink-0 items-center gap-3">
                                                    <span class="text-sm tabular-nums {{ $move->direction === 'in' ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400' }}">
                                                        {{ $move->direction === 'in' ? '+' : '' }}{{ $fmt(abs($move->amountMinor), $row->currency) }}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        wire:click="undoMove({{ $move->id }})"
                                                        class="text-xs text-slate-400 hover:text-rose-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-rose-400"
                                                    >Undo</button>
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
                            @if ($row->overspendMode === 'carry_negative')
                                <span class="ml-1 inline-flex items-center rounded-full bg-amber-100 px-2 py-[2px] text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">Carries negative</span>
                            @endif
                        </p>
                        <p class="secondary" style="font-variant-numeric: tabular-nums;">
                            Spent {{ $fmt($row->spentMinor, $row->currency) }}
                            · <span class="{{ $row->availableMinor < 0 ? 'text-rose-600 dark:text-rose-400' : '' }}">Available {{ $fmt($row->availableMinor, $row->currency) }}</span>
                        </p>
                    </div>
                    <input
                        type="text"
                        inputmode="decimal"
                        wire:model="assignedInputs.{{ $row->categoryId }}"
                        wire:keydown.enter="setAssigned({{ $row->categoryId }})"
                        wire:blur="setAssigned({{ $row->categoryId }})"
                        aria-label="Assigned for {{ $row->categoryName }}"
                        placeholder="0.00"
                        class="amount w-20 rounded-md border border-slate-200 bg-white px-2 py-1 text-right text-sm text-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                        style="font-variant-numeric: tabular-nums;"
                    >
                    <button
                        type="button"
                        wire:click="openMove({{ $row->categoryId }})"
                        x-on:click="$dispatch('open-sheet', { name: 'envelope-move' })"
                        class="text-xs text-slate-400 hover:text-slate-900 focus:outline-none min-w-[44px] min-h-[44px] flex items-center justify-center dark:hover:text-slate-100"
                    >Move</button>
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
                Move from {{ $moveFromCategory?->categoryName ?? 'envelope' }}
            </h2>
            <form wire:submit="moveMoney" class="mt-6 space-y-4">
                <div>
                    <label for="envelope-move-to" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Move to</label>
                    <select
                        id="envelope-move-to"
                        wire:model="moveToCategoryId"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    >
                        <option value="">{{ count($moveDestinations) === 0 ? 'No other envelopes' : 'Select an envelope' }}</option>
                        @foreach ($moveDestinations as $dest)
                            <option value="{{ $dest->categoryId }}">{{ $dest->categoryName }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="envelope-move-amount" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Amount</label>
                    <input
                        type="text"
                        id="envelope-move-amount"
                        wire:model="moveAmount"
                        inputmode="decimal"
                        placeholder="0.00"
                        @if ($moveError !== '') aria-invalid="true" aria-describedby="envelope-move-amount-error" @endif
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                        style="font-variant-numeric: tabular-nums;"
                    >
                    @if ($moveFromCategory !== null)
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500" style="font-variant-numeric: tabular-nums;">
                            Available in {{ $moveFromCategory->categoryName }}: {{ $fmt($moveFromCategory->availableMinor, $moveFromCategory->currency) }}
                        </p>
                    @endif
                    @if ($moveError !== '')
                        <p id="envelope-move-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $moveError }}</p>
                    @endif
                </div>
                <div>
                    <label for="envelope-move-memo" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Note (optional)</label>
                    <input
                        type="text"
                        id="envelope-move-memo"
                        wire:model="moveMemo"
                        placeholder="e.g. Covering dining overspend"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    >
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="$dispatch('modal-close', { name: 'envelope-move' })" class="rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400">Cancel</button>
                    <button type="submit" @disabled(count($moveDestinations) === 0) class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white disabled:opacity-50 disabled:cursor-not-allowed">Move funds</button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- Phone bottom sheet: Move envelope money --}}
    <x-core::bottom-sheet name="envelope-move" title="Move funds">
        <form wire:submit="moveMoney" class="space-y-4">
            <div>
                <label for="envelope-move-to-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Move to</label>
                <select
                    id="envelope-move-to-sheet"
                    wire:model="moveToCategoryId"
                    style="font-size: 16px;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                >
                    <option value="">{{ count($moveDestinations) === 0 ? 'No other envelopes' : 'Select an envelope' }}</option>
                    @foreach ($moveDestinations as $dest)
                        <option value="{{ $dest->categoryId }}">{{ $dest->categoryName }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="envelope-move-amount-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Amount</label>
                <input
                    type="text"
                    id="envelope-move-amount-sheet"
                    wire:model="moveAmount"
                    inputmode="decimal"
                    placeholder="0.00"
                    style="font-size: 16px; font-variant-numeric: tabular-nums;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                >
                @if ($moveError !== '')
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $moveError }}</p>
                @endif
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" @disabled(count($moveDestinations) === 0) class="flex-1 rounded-md bg-slate-900 px-4 py-3 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">Move funds</button>
                <button type="button" wire:click="$dispatch('modal-close', { name: 'envelope-move' })" class="rounded-md border border-slate-200 px-4 py-3 text-sm font-medium text-slate-500 focus:outline-none dark:border-slate-700">Cancel</button>
            </div>
        </form>
    </x-core::bottom-sheet>
</div>
