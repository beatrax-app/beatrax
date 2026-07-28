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

    $fmt = static fn (Money $money): string => $money->currency() === 'EUR'
        ? $money->format('nl_NL')
        : $money->format('en_US');

    $tabs = [
        'pending' => 'Pending',
        'rejected' => 'Rejected',
        'cadence_changed' => 'Cadence changed',
    ];
@endphp

<div class="mx-auto max-w-5xl px-4 py-12">
    <header class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Review recurring</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            Approve, snooze, or reject detected recurring suggestions.
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
        <div
            class="fixed bottom-4 left-1/2 z-40 flex -translate-x-1/2 items-center gap-3 rounded-full border border-slate-200 bg-white px-4 py-2 shadow-lg dark:bg-slate-950 dark:border-slate-700"
            role="region"
            aria-label="Bulk actions"
        >
            <span class="text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ count($selectedIds) }} selected</span>
            <button
                type="button"
                wire:click="bulkApprove"
                class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
            >Approve {{ count($selectedIds) }}</button>
            <button
                type="button"
                wire:click="bulkReject"
                class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:bg-rose-950 dark:text-rose-500 dark:hover:bg-rose-900"
            >Reject {{ count($selectedIds) }}</button>
        </div>
    @endif

    @if (count($rows) === 0)
        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Nothing to review</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                @if ($tab === 'pending')
                    Recurring suggestions land here as the detector spots stable monthly clusters.
                @elseif ($tab === 'rejected')
                    Rejected suggestions appear here so you can bring them back if your mind changes.
                @else
                    Approved series whose cadence has flipped show up here for re-review.
                @endif
            </p>
        </div>
    @else
        {{-- D-06 power-surface fallback: wrap in overflow-x:auto at phone width.
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
                                    aria-label="Select recurring series {{ $row->seriesId }}"
                                    class="mr-2 align-middle"
                                />
                                <span class="font-medium">{{ $row->displayName() }}</span>
                                <span class="ml-2 text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->latestAmount) }}</span>
                            </p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ ucfirst($row->cadence) }}
                                @if ($row->nextExpectedAt)
                                    · Next {{ $row->nextExpectedAt->format('d M Y') }}
                                @endif
                                @if ($row->state === 'cadence_changed')
                                    · cadence changed
                                @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($tab === 'rejected')
                                <button
                                    type="button"
                                    wire:click="unReject({{ $row->seriesId }})"
                                    class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                                >Un-reject</button>
                            @else
                                <button
                                    type="button"
                                    wire:click="approve({{ $row->seriesId }})"
                                    aria-label="Approve recurring series {{ $row->seriesId }}"
                                    class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                                >Approve</button>
                                <button
                                    type="button"
                                    wire:click="reject({{ $row->seriesId }})"
                                    aria-label="Reject recurring series {{ $row->seriesId }}"
                                    class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-600 hover:bg-rose-100 dark:bg-rose-950 dark:text-rose-500 dark:hover:bg-rose-900"
                                >Reject</button>
                                <div x-data="{ open: false }" class="relative">
                                    <button
                                        type="button"
                                        x-on:click="open = ! open"
                                        class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                                    >Snooze</button>
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
                                        >1 week</button>
                                        <button
                                            type="button"
                                            wire:click="snooze({{ $row->seriesId }}, '{{ $snoozeTargets['1m'] }}')"
                                            x-on:click="open = false"
                                            class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900"
                                        >1 month</button>
                                        <button
                                            type="button"
                                            wire:click="snooze({{ $row->seriesId }}, '{{ $snoozeTargets['3m'] }}')"
                                            x-on:click="open = false"
                                            class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900"
                                        >3 months</button>
                                    </div>
                                </div>
                                <div x-data="{ editing: false, newName: @js($row->displayName()) }" class="relative">
                                    <button
                                        type="button"
                                        x-on:click="editing = ! editing"
                                        class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                                    >Edit name</button>
                                    <div
                                        x-show="editing"
                                        x-cloak
                                        x-on:click.outside="editing = false"
                                        class="absolute right-0 z-10 mt-1 w-64 rounded-md border border-slate-200 bg-white p-2 shadow-lg dark:bg-slate-950 dark:border-slate-700"
                                    >
                                        <label for="series-name-{{ $row->seriesId }}" class="sr-only">New name for this series</label>
                                        <input
                                            id="series-name-{{ $row->seriesId }}"
                                            type="text"
                                            x-model="newName"
                                            class="block w-full rounded-md border border-slate-200 px-2 py-1 text-xs dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                                        />
                                        <button
                                            type="button"
                                            x-on:click="$wire.editName({{ $row->seriesId }}, newName); editing = false"
                                            class="mt-2 inline-flex items-center gap-1 rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300"
                                        >Save</button>
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
