@use('Modules\Core\Public\Support\Lang')
{{--
    /recurring/review page — Pending / Rejected / Cadence-changed
    tabs over recurring_series rows. Each pending or cadence-changed
    row carries Approve / Reject / Snooze / Edit-name actions;
    rejected rows carry an Un-reject action. Approve / Reject /
    Snooze / Edit-name each dispatch a 10-second Undo toast via
    `$this->dispatch('toast', ...)` (the layout binds `x-on:toast` on
    the window).

    Blade default `{{ }}` escaping for every interpolation. No raw
    HTML output anywhere.
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (Money $money): string => $money->format();

    $tabs = [
        'pending' => Lang::get('recurring::review.tabs.pending'),
        'rejected' => Lang::get('recurring::review.tabs.rejected'),
        'cadence_changed' => Lang::get('recurring::review.tabs.cadence_changed'),
    ];
@endphp

<div class="mx-auto max-w-5xl px-4 py-12">
    <header class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('recurring::review.title') }}</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('recurring::review.subtitle') }}
        </p>
    </header>

    <nav class="mb-6 flex items-center gap-2 border-b border-slate-200 dark:border-slate-700">
        @foreach ($tabs as $key => $label)
            <button
                type="button"
                wire:click="setTab('{{ $key }}')"
                @class([
                    'px-3 py-2 text-sm',
                    'border-b-2 border-slate-900 font-medium text-slate-900 dark:border-slate-100 dark:text-slate-100' => $tab === $key,
                    'border-b-2 border-transparent text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100' => $tab !== $key,
                ])
            >{{ $label }}</button>
        @endforeach
    </nav>

    @if (count($selectedIds) > 0)
        <section
            class="fixed bottom-4 left-1/2 z-40 flex -translate-x-1/2 items-center gap-3 rounded-full border border-slate-200 bg-white px-4 py-2 shadow-lg dark:bg-slate-950 dark:border-slate-700"
            aria-label="{{ Lang::get('recurring::review.bulk.aria') }}"
        >
            <span class="text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ Lang::get('recurring::review.bulk.selected', ['count' => count($selectedIds)]) }}</span>
            <button
                type="button"
                wire:click="bulkApprove"
                class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
            >{{ Lang::get('recurring::review.bulk.approve', ['count' => count($selectedIds)]) }}</button>
            <button
                type="button"
                wire:click="bulkReject"
                class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:bg-rose-950 dark:text-rose-500 dark:hover:bg-rose-900"
            >{{ Lang::get('recurring::review.bulk.reject', ['count' => count($selectedIds)]) }}</button>
        </section>
    @endif

    @if (count($rows) === 0)
        @php
            $emptyBody = match ($tab) {
                'pending' => Lang::get('recurring::review.empty.pending'),
                'rejected' => Lang::get('recurring::review.empty.rejected'),
                default => Lang::get('recurring::review.empty.cadence_changed'),
            };
        @endphp
        <x-core::empty-state
            :heading="Lang::get('recurring::review.empty.heading')"
            :body="$emptyBody"
        />
    @else
        {{-- Power-surface fallback: wrap in overflow-x:auto at phone width.
             The multi-action row (Approve/Reject/Snooze/Edit-name) cannot be
             cleanly mapped to a card at <768px without significant redesign —
             the overflow-x scroller ensures all columns remain reachable. --}}
        <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
        <ul class="space-y-3" style="min-width: 560px;">
            @foreach ($rows as $row)
                <li class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-slate-900 dark:text-slate-100">
                                <input
                                    type="checkbox"
                                    wire:model.live="selectedIds"
                                    value="{{ $row->seriesId }}"
                                    aria-label="{{ Lang::get('recurring::review.select_aria', ['id' => $row->seriesId]) }}"
                                    class="mr-2 align-middle"
                                />
                                <span class="font-medium">{{ $row->displayName() }}</span>
                                <span class="ml-2 text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->latestAmount) }}</span>
                            </p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ $row->cadence->label() }}
                                @if ($row->nextExpectedAt)
                                    · {{ Lang::get('recurring::review.next') }} {{ $row->nextExpectedAt->translatedFormat('d M Y') }}
                                @endif
                                @if ($row->state === \Modules\Recurring\Public\Enums\RecurringSeriesState::CadenceChanged->value)
                                    · {{ Lang::get('recurring::review.cadence_changed_note') }}
                                @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($tab === 'rejected')
                                <button
                                    type="button"
                                    wire:click="unReject({{ $row->seriesId }})"
                                    class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                                >{{ Lang::get('recurring::review.un_reject') }}</button>
                            @else
                                <button
                                    type="button"
                                    wire:click="approve({{ $row->seriesId }})"
                                    aria-label="{{ Lang::get('recurring::review.approve_aria', ['id' => $row->seriesId]) }}"
                                    class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                                >{{ Lang::get('recurring::review.approve') }}</button>
                                <button
                                    type="button"
                                    wire:click="reject({{ $row->seriesId }})"
                                    aria-label="{{ Lang::get('recurring::review.reject_aria', ['id' => $row->seriesId]) }}"
                                    class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-600 hover:bg-rose-100 dark:bg-rose-950 dark:text-rose-500 dark:hover:bg-rose-900"
                                >{{ Lang::get('recurring::review.reject') }}</button>
                                <div x-data="{ open: false }" class="relative">
                                    <button
                                        type="button"
                                        x-on:click="open = ! open"
                                        class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                                    >{{ Lang::get('recurring::review.snooze') }}</button>
                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-on:click.outside="open = false"
                                        class="absolute right-0 z-10 mt-1 w-48 rounded-md border border-slate-200 bg-white p-2 text-xs shadow-lg dark:bg-slate-950 dark:border-slate-700"
                                    >
                                        <button
                                            type="button"
                                            wire:click="snooze({{ $row->seriesId }}, '{{ $snoozeTargets['1w'] }}')"
                                            x-on:click="open = false"
                                            class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900"
                                        >{{ Lang::get('recurring::review.snooze_1w') }}</button>
                                        <button
                                            type="button"
                                            wire:click="snooze({{ $row->seriesId }}, '{{ $snoozeTargets['1m'] }}')"
                                            x-on:click="open = false"
                                            class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900"
                                        >{{ Lang::get('recurring::review.snooze_1m') }}</button>
                                        <button
                                            type="button"
                                            wire:click="snooze({{ $row->seriesId }}, '{{ $snoozeTargets['3m'] }}')"
                                            x-on:click="open = false"
                                            class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900"
                                        >{{ Lang::get('recurring::review.snooze_3m') }}</button>
                                    </div>
                                </div>
                                <div x-data="{ editing: false, newName: @js($row->displayName()) }" class="relative">
                                    <button
                                        type="button"
                                        x-on:click="editing = ! editing"
                                        class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                                    >{{ Lang::get('recurring::review.edit_name') }}</button>
                                    <div
                                        x-show="editing"
                                        x-cloak
                                        x-on:click.outside="editing = false"
                                        class="absolute right-0 z-10 mt-1 w-64 rounded-md border border-slate-200 bg-white p-2 shadow-lg dark:bg-slate-950 dark:border-slate-700"
                                    >
                                        <label for="series-name-{{ $row->seriesId }}" class="sr-only">{{ Lang::get('recurring::review.new_name_label') }}</label>
                                        <input
                                            id="series-name-{{ $row->seriesId }}"
                                            type="text"
                                            x-model="newName"
                                            class="block w-full rounded-md border border-slate-200 px-2 py-1 text-xs dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                                        />
                                        <x-core::neutral-button
                                            size="sm"
                                            class="mt-2 gap-1"
                                            x-on:click="$wire.editName({{ $row->seriesId }}, newName); editing = false"
                                        >{{ Lang::get('recurring::review.save') }}</x-core::neutral-button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        </div>{{-- end overflow-x scroller --}}
    @endif
</div>
