@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Services\UserDataPathService')
@use('Modules\Ledger\Public\Services\BaseCurrency')
{{--
    /budgets — the rebuilt zero-based envelope grid.

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
    use Modules\Ledger\Public\ValueObjects\MoneyInput;

    $fmt = static fn (int $minor, ?string $currency = null): string => Money::ofMinor($minor, $currency ?? BaseCurrency::value())
        ->format();
@endphp

<div class="mx-auto max-w-5xl px-4 py-6">
    {{-- Header row: title + subtitle, month nav on the right.
         flex-col until sm, because the stepper is shrink-0 (its glyphs must
         keep their tap targets) in a nowrap row — so a month name longer than
         English's pushes the next-month button off the screen entirely.
         Measured on an iPhone 12 mini: "Αύγουστος 2026" put its right edge at
         475px on a 375pt screen, "augusztus 2026" at 449. --}}
    <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <x-core::page-heading>{{ Lang::get('budgets::messages.page.title') }}</x-core::page-heading>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('budgets::messages.page.subtitle', ['period' => $period->label]) }}
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-1">
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

    {{-- Sticky to-budget header --}}
    @php
        $toBudgetColour = $toBudgetMinor >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400';
    @endphp
    <div class="sticky top-0 z-10 mb-6 rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
        {{-- The label carries no element of its own: the tip's panel is a
             <div popover>, and a div inside a <p> closes the paragraph in the
             parser before the figure below is reached. The block holds the
             type for the label and the mark both, which is what puts the mark
             on the label's cap height rather than on the body text's. The
             space between them is non-breaking so that a label with no room
             left takes its last word down with the mark. --}}
        <div class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('budgets::messages.ready.label') }}&nbsp;<x-core::help-tip
            topic="budgets-ready"
            :label="Lang::get('budgets::messages.ready.label')"
            :body="Lang::get('budgets::help.ready_to_assign')"
        /></div>
        <p class="mt-1 text-3xl font-semibold {{ $toBudgetColour }}" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
            {{ $fmt($toBudgetMinor) }}
        </p>
        @if ($toBudgetMinor < 0)
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('budgets::messages.ready.overassigned') }}
            </p>
        @endif
    </div>

    {{-- Empty-state / copy-last-month banner --}}
    @if (! ($rows !== [] && collect($rows)->contains(static fn ($row): bool => $row->assignedMinor > 0 || $row->spentMinor > 0)))
        <div class="mb-6 rounded-lg border p-4 {{ $showCopyBanner ? 'border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/20' : 'border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700' }}">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('budgets::messages.empty.nothing_assigned_heading') }}</h2>
            <p class="mt-1 text-sm {{ $showCopyBanner ? 'text-amber-700 dark:text-amber-400' : 'text-slate-500 dark:text-slate-400' }}">
                {{-- "Click into a cell" names a gesture the phone does not
                     have, the way the drop-zone's "Drop your file here" did. --}}
                @if ($showCopyBanner)
                    {{ UserDataPathService::isMobileRuntime()
                        ? Lang::get('budgets::messages.empty.copy_hint_touch')
                        : Lang::get('budgets::messages.empty.copy_hint') }}
                @else
                    {{ UserDataPathService::isMobileRuntime()
                        ? Lang::get('budgets::messages.empty.first_hint_touch')
                        : Lang::get('budgets::messages.empty.first_hint') }}
                @endif
            </p>
            @if ($showCopyBanner)
                <x-core::neutral-button
                    class="mt-3"
                    wire:click="copyLastMonth"
                >{{ Lang::get('budgets::messages.empty.copy_button') }}</x-core::neutral-button>
            @endif
        </div>
    @endif

    {{-- Envelope grid --}}
    @if (count($rows) === 0)
        <x-core::empty-state
            :heading="Lang::get('budgets::messages.no_categories.heading')"
            :body="Lang::get('budgets::messages.no_categories.body')"
        />
    @else
        {{-- Desktop grid (>=768px). The scroll container is the point: the
             table's min-content width exceeds the content column in the
             desktop shell's default window, and without it the whole document
             scrolls sideways instead. --}}
        <div class="hidden overflow-x-auto md:block">
        <table class="w-full text-left text-sm md:table">
            <thead class="border-b border-slate-200 bg-slate-50 dark:bg-slate-900 dark:border-slate-700">
                <tr>
                    <x-core::th align="left">{{ Lang::get('budgets::messages.table.category') }}</x-core::th>
                    <x-core::th align="right">{{ Lang::get('budgets::messages.table.assigned') }}</x-core::th>
                    <x-core::th align="right">{{ Lang::get('budgets::messages.table.carried_in') }}</x-core::th>
                    <x-core::th align="right">{{ Lang::get('budgets::messages.table.moved') }}</x-core::th>
                    <x-core::th align="right">{{ Lang::get('budgets::messages.table.spent') }}</x-core::th>
                    <x-core::th align="right">{{ Lang::get('budgets::messages.table.available') }}</x-core::th>
                    {{-- The tip sits on the header rather than on each row's
                         select: one panel per page, and the id it needs is
                         unique only up here. The space before the mark is
                         non-breaking so a narrowed column takes its last word
                         down with the mark instead of stranding it. --}}
                    <x-core::th align="left">{{ Lang::get('budgets::messages.table.if_overspent') }}&nbsp;<x-core::help-tip
                        topic="budgets-overspend"
                        :label="Lang::get('budgets::messages.table.if_overspent')"
                        :body="Lang::get('budgets::help.if_overspent', [
                            'reduce' => Lang::get('budgets::messages.overspend.reduce'),
                            'carry' => Lang::get('budgets::messages.overspend.carry'),
                        ])"
                    /></x-core::th>
                    <x-core::th align="right">{{ Lang::get('budgets::messages.table.notify_at') }}</x-core::th>
                    <x-core::th align="right"><span class="sr-only">{{ Lang::get('budgets::messages.table.actions') }}</span></x-core::th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="group border-b border-slate-100 dark:border-slate-800" wire:key="envelope-row-{{ $row->categoryId }}">
                        <td class="px-4 py-2">
                            <span class="truncate text-slate-900 dark:text-slate-100">{{ $row->categoryPath }}</span>
                            @if ($row->overspendMode === \Modules\Budgets\Public\Enums\OverspendMode::CarryNegative)
                                <x-core::status-pill tone="warning" class="ml-2">{{ Lang::get('budgets::messages.badge.carries_negative') }}</x-core::status-pill>
                            @endif
                            @if ($row->unconvertedSpentMinor != 0)
                                <span
                                    class="ml-2 inline-flex h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400 align-middle dark:bg-amber-500"
                                    role="img"
                                    aria-label="{{ Lang::get('budgets::messages.badge.unconverted_aria') }}"
                                    title="{{ Lang::get('budgets::messages.badge.unconverted_title') }}"
                                ></span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <input
                                type="text"
                                inputmode="{{ MoneyInput::decimalPlaces($row->currency) === 0 ? 'numeric' : 'decimal' }}"
                                wire:model="assignedInputs.{{ $row->categoryId }}"
                                wire:keydown.enter="setAssigned('{{ $row->categoryId }}')"
                                wire:blur="setAssigned('{{ $row->categoryId }}')"
                                aria-label="{{ Lang::get('budgets::messages.row.assigned_aria', ['category' => $row->categoryPath]) }}"
                                placeholder="{{ MoneyInput::formatAbsMinor(0, $row->currency) }}"
                                class="w-24 rounded-md border border-slate-200 bg-white px-2 py-1 text-right text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                style="font-variant-numeric: tabular-nums;"
                            >
                        </td>
                        {{-- available = assigned + carried in + moved - spent, and three
                             of the five terms used to be on the grid. A reader with
                             €200.00 assigned against €50.00 spent read €358.00
                             available with nowhere to find the rest of it. --}}
                        <td class="px-4 py-2 text-right text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                            {{ $fmt($row->carriedInMinor, $row->currency) }}
                        </td>
                        <td class="px-4 py-2 text-right text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                            {{ $fmt($row->netMovedMinor, $row->currency) }}
                        </td>
                        <td class="px-4 py-2 text-right text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                            {{ $fmt($row->spentMinor, $row->currency) }}
                        </td>
                        <td class="px-4 py-2 text-right font-medium {{ $row->availableMinor < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-slate-100' }}" style="font-variant-numeric: tabular-nums;">
                            {{ $fmt($row->availableMinor, $row->currency) }}
                        </td>
                        <td class="px-4 py-2">
                            <select
                                x-data="{ mode: @js($row->overspendMode->value) }"
                                x-on:change="if ($event.target.value !== mode) { mode = $event.target.value; $wire.setOverspendMode('{{ $row->categoryId }}', mode) }"
                                aria-label="{{ Lang::get('budgets::messages.row.overspend_aria', ['category' => $row->categoryPath]) }}"
                                class="rounded-md border border-slate-200 bg-white px-1.5 py-1 text-xs text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-300"
                            >
                                <option value="{{ \Modules\Budgets\Public\Enums\OverspendMode::ReduceToBudget->value }}" @selected($row->overspendMode === \Modules\Budgets\Public\Enums\OverspendMode::ReduceToBudget)>{{ Lang::get('budgets::messages.overspend.reduce') }}</option>
                                <option value="{{ \Modules\Budgets\Public\Enums\OverspendMode::CarryNegative->value }}" @selected($row->overspendMode === \Modules\Budgets\Public\Enums\OverspendMode::CarryNegative)>{{ Lang::get('budgets::messages.overspend.carry') }}</option>
                            </select>
                        </td>
                        <td class="px-4 py-2 text-right align-top">
                            <div class="inline-flex items-center gap-1">
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    wire:model="thresholdInputs.{{ $row->categoryId }}"
                                    wire:keydown.enter="setNotifyThreshold('{{ $row->categoryId }}')"
                                    wire:blur="setNotifyThreshold('{{ $row->categoryId }}')"
                                    aria-label="{{ Lang::get('budgets::messages.row.notify_aria', ['category' => $row->categoryPath]) }}"
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
                                wire:click="openMove('{{ $row->categoryId }}')"
                                x-on:click="$flux.modal('envelope-move').show()"
                                class="hidden text-sm text-slate-600 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 group-hover:inline dark:hover:text-slate-100 dark:text-slate-400"
                            >{{ Lang::get('budgets::messages.row.move_money') }}</button>
                        </td>
                    </tr>
                    @if (count($recentMoves[$row->categoryId] ?? []) > 0)
                        <tr class="border-b border-slate-100 dark:border-slate-800" x-data="{ open: false }" wire:key="envelope-history-row-{{ $row->categoryId }}">
                            <td colspan="9" class="px-4 pb-3">
                                <button
                                    type="button"
                                    x-on:click="open = !open"
                                    :aria-expanded="open.toString()"
                                    aria-controls="envelope-history-{{ $row->categoryId }}"
                                    class="text-xs text-slate-600 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-300 dark:text-slate-400"
                                >
                                    <span x-show="!open">{{ Lang::get('budgets::messages.history.show') }}</span>
                                    <span x-show="open" x-cloak>{{ Lang::get('budgets::messages.history.hide') }}</span>
                                </button>
                                <div id="envelope-history-{{ $row->categoryId }}" x-show="open" x-collapse class="mt-2 border-t border-slate-100 dark:border-slate-800">
                                    <ul>
                                        @foreach ($recentMoves[$row->categoryId] as $move)
                                            <li class="flex items-center justify-between gap-4 py-2 text-sm">
                                                <div class="min-w-0">
                                                    <span class="text-xs text-slate-600 dark:text-slate-400 tabular-nums">{{ substr($move->createdAt, 0, 10) }}</span>
                                                    {{-- A kind this build has no case for keeps the row and drops
                                                         the direction word: the sentence would otherwise claim a side
                                                         of the move that only the newer version knows. --}}
                                                    <span class="ml-2 text-sm text-slate-500 dark:text-slate-400">{{ $move->kind === null ? Lang::get('budgets::messages.history.moved_unreadable', ['category' => $move->counterpartCategoryName]) : ($move->kind->isIncoming() ? Lang::get('budgets::messages.history.moved_from', ['category' => $move->counterpartCategoryName]) : Lang::get('budgets::messages.history.moved_to', ['category' => $move->counterpartCategoryName])) }}</span>
                                                    @if ($move->memo !== null && $move->memo !== '')
                                                        <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $move->memo }}</span>
                                                    @endif
                                                </div>
                                                <div class="flex shrink-0 items-center gap-3">
                                                    <span class="text-sm tabular-nums {{ $move->kind?->isIncoming() ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400' }}">
                                                        {{-- The stored figure carries its own sign, which is the one
                                                             fact about direction this build still has. --}}
                                                        {{ $move->kind === null ? $fmt($move->amountMinor, $move->currency) : ($move->kind->isIncoming() ? '+' : '').$fmt(abs($move->amountMinor), $move->currency) }}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        wire:click="undoMove('{{ $move->id }}')"
                                                        class="text-xs text-slate-600 hover:text-rose-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-rose-400 dark:text-slate-400"
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
        </div>

        {{-- Phone stacked list (<768px) --}}
        <div class="rounded-lg border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700 overflow-hidden md:hidden">
            @foreach ($rows as $row)
                {{-- The row is two stacked lines, not three side-by-side columns:
                     at phone width the name column was squeezed to ~110px, which
                     broke "spent · available" across three lines and left the
                     separator hanging on one of its own. The name and the figures
                     take the full width; the controls get the line below. --}}
                <div class="card-list-item" wire:key="envelope-phone-{{ $row->categoryId }}">
                    <div class="w-full min-w-0">
                        <p class="primary truncate">
                            {{ $row->categoryPath }}
                            @if ($row->overspendMode === \Modules\Budgets\Public\Enums\OverspendMode::CarryNegative)
                                <x-core::status-pill tone="warning" class="ml-1">{{ Lang::get('budgets::messages.badge.carries_negative') }}</x-core::status-pill>
                            @endif
                            @if ($row->unconvertedSpentMinor != 0)
                                <span
                                    class="ml-1 inline-flex h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400 align-middle dark:bg-amber-500"
                                    role="img"
                                    aria-label="{{ Lang::get('budgets::messages.badge.unconverted_aria') }}"
                                    title="{{ Lang::get('budgets::messages.badge.unconverted_title') }}"
                                ></span>
                            @endif
                        </p>
                        <p class="secondary" style="font-variant-numeric: tabular-nums;">
                            {{ Lang::get('budgets::messages.phone.spent', ['amount' => $fmt($row->spentMinor, $row->currency)]) }}
                            {{-- Only when they carry something: the two terms explain a
                                 figure the reader cannot otherwise derive, and a row
                                 where both are nought has nothing to explain. --}}
                            @if ($row->carriedInMinor !== 0)
                                ·&nbsp;{{ Lang::get('budgets::messages.phone.carried_in', ['amount' => $fmt($row->carriedInMinor, $row->currency)]) }}
                            @endif
                            @if ($row->netMovedMinor !== 0)
                                ·&nbsp;{{ Lang::get('budgets::messages.phone.moved', ['amount' => $fmt($row->netMovedMinor, $row->currency)]) }}
                            @endif
                            ·&nbsp;<span class="{{ $row->availableMinor < 0 ? 'text-rose-600 dark:text-rose-400' : '' }}">{{ Lang::get('budgets::messages.phone.available', ['amount' => $fmt($row->availableMinor, $row->currency)]) }}</span>
                        </p>
                    </div>
                    {{-- Both fields are h-8 and w-24, and each row is justify-between,
                         so the two boxes share a left edge and a right edge. The
                         label widths differ per language and the % rides inside
                         the notify field, so neither can be what aligns them:
                         measured at 99/112 and 184/214 when the rows were sized
                         by their own content. --}}
                    <div class="flex flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                        <label class="flex w-full flex-wrap items-center justify-between gap-1 text-xs text-slate-600 dark:text-slate-400">
                            <span>{{ Lang::get('budgets::messages.phone.notify_at') }}</span>
                            <span class="relative inline-flex items-center">
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    wire:model="thresholdInputs.{{ $row->categoryId }}"
                                    wire:keydown.enter="setNotifyThreshold('{{ $row->categoryId }}')"
                                    wire:blur="setNotifyThreshold('{{ $row->categoryId }}')"
                                    aria-label="{{ Lang::get('budgets::messages.row.notify_aria', ['category' => $row->categoryPath]) }}"
                                    placeholder="{{ $defaultNotifyThreshold }}"
                                    class="h-8 w-24 rounded-md border border-slate-200 bg-white pl-2 pr-6 text-right text-sm text-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    style="font-variant-numeric: tabular-nums;"
                                >
                                <span class="pointer-events-none absolute right-2 text-xs text-slate-600 dark:text-slate-400">%</span>
                            </span>
                        </label>
                        {{-- The column header the table gives this field is not
                             drawn here, so the box that assigns the money was the
                             bare one and the notify threshold beside it was the
                             labelled one. Same wrapper, same header string. --}}
                        <label class="flex w-full flex-wrap items-center justify-between gap-1 text-xs text-slate-600 dark:text-slate-400">
                            <span>{{ Lang::get('budgets::messages.table.assigned') }}</span>
                            <input
                                type="text"
                                inputmode="{{ MoneyInput::decimalPlaces($row->currency) === 0 ? 'numeric' : 'decimal' }}"
                                wire:model="assignedInputs.{{ $row->categoryId }}"
                                wire:keydown.enter="setAssigned('{{ $row->categoryId }}')"
                                wire:blur="setAssigned('{{ $row->categoryId }}')"
                                aria-label="{{ Lang::get('budgets::messages.row.assigned_aria', ['category' => $row->categoryPath]) }}"
                                placeholder="{{ MoneyInput::formatAbsMinor(0, $row->currency) }}"
                                class="amount h-8 w-24 rounded-md border border-slate-200 bg-white px-2 text-right text-sm text-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                style="font-variant-numeric: tabular-nums;"
                            >
                        </label>
                        @if (! empty($thresholdErrors[$row->categoryId]))
                            <p class="w-full text-xs text-rose-600 dark:text-rose-400">{{ $thresholdErrors[$row->categoryId] }}</p>
                        @endif
                    </div>
                    {{-- Under 640px the mark stands alone as the verb, so it is an
                         emoji: ⇄ came out of the body font at 8px, off baseline,
                         and read as punctuation. 🔄 is the pots row's mark for a
                         move. Not x-core::emoji-action — the word comes back at
                         sm:, and that component is the icon and nothing else. --}}
                    <button
                        type="button"
                        wire:click="openMove('{{ $row->categoryId }}')"
                        x-on:click="$dispatch('open-sheet', { name: 'envelope-move' })"
                        class="text-xs text-slate-600 hover:text-slate-900 focus:outline-none min-w-[44px] min-h-[44px] flex items-center justify-center dark:hover:text-slate-100 dark:text-slate-400"
                     title="{{ Lang::get('budgets::messages.row.move') }}"><span aria-hidden="true" class="sm:hidden">🔄</span><span class="sr-only sm:not-sr-only">{{ Lang::get('budgets::messages.row.move') }}</span></button>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ------------------------------------------------------------------- --}}
    {{-- Move-money modal — structural clone of Pots' pot-move --}}
    {{-- ------------------------------------------------------------------- --}}
    <flux:modal name="envelope-move" dismissible>
        <div class="pt-[44px]" style="max-width: 480px;">
            <x-core::section-heading :title="Lang::get('budgets::messages.modal.move_from', ['name' => $moveFromCategory?->categoryPath ?? Lang::get('budgets::messages.modal.move_from_fallback')])" />
            <form wire:submit="moveMoney" class="mt-6 space-y-4">
                <x-core::form-field
                    :label="Lang::get('budgets::messages.modal.move_to')"
                    name="moveToCategoryId"
                    field-id="envelope-move-to"
                    type="select"
                    wire:model="moveToCategoryId"
                >
                    <option value="">{{ count($moveDestinations) === 0 ? Lang::get('budgets::messages.modal.no_other') : Lang::get('budgets::messages.modal.select') }}</option>
                    @foreach ($moveDestinations as $dest)
                        <option value="{{ $dest->categoryId }}">{{ $dest->categoryPath }}</option>
                    @endforeach
                </x-core::form-field>
                <div>
                    {{-- $moveError is a plain property, not a validation-bag
                         entry, so the component's error line cannot read it.
                         The message stays a sibling below and the field is
                         pointed at it by hand. --}}
                    <x-core::form-field
                        :label="Lang::get('budgets::messages.modal.amount')"
                        name="moveAmount"
                        field-id="envelope-move-amount"
                        wire:model="moveAmount"
                        inputmode="{{ MoneyInput::decimalPlaces($moveFromCategory?->currency) === 0 ? 'numeric' : 'decimal' }}"
                        placeholder="{{ MoneyInput::formatAbsMinor(0, $moveFromCategory?->currency) }}"
                        :aria-invalid="$moveError !== '' ? 'true' : null"
                        :aria-describedby="$moveError !== '' ? 'envelope-move-amount-error' : null"
                        style="font-variant-numeric: tabular-nums;"
                    />
                    @if ($moveFromCategory !== null)
                        <p class="mt-1 text-xs text-slate-600 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                            {{ Lang::get('budgets::messages.modal.available_in', ['name' => $moveFromCategory->categoryPath, 'amount' => $fmt($moveFromCategory->availableMinor, $moveFromCategory->currency)]) }}
                        </p>
                    @endif
                    @if ($moveError !== '')
                        <p id="envelope-move-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $moveError }}</p>
                    @endif
                </div>
                <x-core::form-field
                    :label="Lang::get('budgets::messages.modal.note')"
                    name="moveMemo"
                    field-id="envelope-move-memo"
                    wire:model="moveMemo"
                    placeholder="{{ Lang::get('budgets::messages.modal.note_placeholder') }}"
                />
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="$dispatch('modal-close', { name: 'envelope-move' })" class="rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400">{{ Lang::get('budgets::messages.modal.cancel') }}</button>
                    <x-core::neutral-button
                        class="disabled:opacity-50 disabled:cursor-not-allowed"
                        :disabled="count($moveDestinations) === 0"
                        type="submit"
                    >{{ Lang::get('budgets::messages.modal.move_funds') }}</x-core::neutral-button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- Phone bottom sheet: Move envelope money --}}
    <x-core::bottom-sheet name="envelope-move" :title="Lang::get('budgets::messages.modal.move_funds')">
        <form wire:submit="moveMoney" class="space-y-4">
            {{-- The inline font-size stays. This theme redefines --text-base
                 as 0.9375rem, so `size="base"` renders 15px here, not 16px —
                 under the threshold at which Safari zooms the viewport on
                 focus, which is the whole reason these two phone fields
                 pinned 16px by hand. The prop states the intent; the inline
                 rule is what currently delivers it. --}}
            <x-core::form-field
                :label="Lang::get('budgets::messages.modal.move_to')"
                name="moveToCategoryId"
                field-id="envelope-move-to-sheet"
                type="select"
                size="base"
                wire:model="moveToCategoryId"
                style="font-size: 16px;"
            >
                <option value="">{{ count($moveDestinations) === 0 ? Lang::get('budgets::messages.modal.no_other') : Lang::get('budgets::messages.modal.select') }}</option>
                @foreach ($moveDestinations as $dest)
                    <option value="{{ $dest->categoryId }}">{{ $dest->categoryPath }}</option>
                @endforeach
            </x-core::form-field>
            <div>
                <x-core::form-field
                    :label="Lang::get('budgets::messages.modal.amount')"
                    name="moveAmount"
                    field-id="envelope-move-amount-sheet"
                    size="base"
                    wire:model="moveAmount"
                    inputmode="{{ MoneyInput::decimalPlaces($moveFromCategory?->currency) === 0 ? 'numeric' : 'decimal' }}"
                    placeholder="{{ MoneyInput::formatAbsMinor(0, $moveFromCategory?->currency) }}"
                    :aria-invalid="$moveError !== '' ? 'true' : null"
                    :aria-describedby="$moveError !== '' ? 'envelope-move-amount-sheet-error' : null"
                    style="font-size: 16px; font-variant-numeric: tabular-nums;"
                />
                @if ($moveError !== '')
                    <p id="envelope-move-amount-sheet-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $moveError }}</p>
                @endif
            </div>
            <div class="flex gap-3 pt-2">
                <x-core::neutral-button
                    block="flex"
                    class="disabled:opacity-50"
                    :disabled="count($moveDestinations) === 0"
                    type="submit"
                >{{ Lang::get('budgets::messages.modal.move_funds') }}</x-core::neutral-button>
                <x-core::secondary-button wire:click="$dispatch('modal-close', { name: 'envelope-move' })">{{ Lang::get('budgets::messages.modal.cancel') }}</x-core::secondary-button>
            </div>
        </form>
    </x-core::bottom-sheet>
</div>
