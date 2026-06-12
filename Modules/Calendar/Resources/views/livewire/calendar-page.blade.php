{{--
    /calendar page — Phase 6 Plan 02 wired view.

    Renders a Mon–Sun calendar grid sourced from CalendarQuery.
    Full layout polish and the account popover arrive in Plan 03.
    This view concentrates on data correctness: entry names, balance
    line, paid/missed indicators, and drill-through links.

    The calendar grid always renders (balance lines appear even when there
    are no recurring entries). The "No upcoming payments" notice appears
    as an inline prompt inside the empty grid header row.
--}}
<div class="mx-auto max-w-7xl px-4 py-12">
    <header class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Calendar</h1>
        <p class="mt-2 max-w-prose text-sm text-slate-500 dark:text-slate-400">
            Upcoming payments and your projected daily balance.
        </p>
    </header>

    @if (!$hasEntries)
        {{-- Empty state notice: no approved recurring series yet --}}
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">No upcoming payments</p>
            <p class="mt-0.5 text-sm text-amber-700 dark:text-amber-300">
                Approve recurring patterns to populate the calendar.
                <a href="/recurring/review"
                   class="ml-1 font-medium underline hover:no-underline">
                    Review recurring
                </a>
            </p>
        </div>
    @endif

    {{-- Calendar grid: 7-column Mon–Sun layout (always rendered for balance lines) --}}
    <div class="grid grid-cols-7 gap-px rounded-lg overflow-hidden bg-slate-200 dark:bg-slate-700">
        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $heading)
            <div class="bg-slate-50 dark:bg-slate-900 px-2 py-1 text-center text-xs font-semibold text-slate-500 dark:text-slate-400">
                {{ $heading }}
            </div>
        @endforeach

        @foreach ($days as $day)
            <div
                wire:click="$set('selectedDay', '{{ $day->date->toDateString() }}')"
                class="min-h-20 cursor-pointer bg-white dark:bg-slate-950 p-2
                    {{ $day->isToday ? 'ring-2 ring-inset ring-indigo-500' : '' }}
                    {{ $day->isRisk ? 'bg-rose-50 dark:bg-rose-950' : '' }}"
                data-date="{{ $day->date->toDateString() }}"
            >
                {{-- Day number --}}
                <span class="block text-right text-xs font-semibold
                    {{ $day->isToday ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-700 dark:text-slate-300' }}">
                    {{ $day->date->day }}
                </span>

                {{-- Entries --}}
                @foreach ($day->entries as $entry)
                    <div class="mt-0.5 truncate text-xs
                        {{ $entry->isPaid ? 'text-emerald-700 dark:text-emerald-400' : '' }}
                        {{ $entry->isMissed ? 'text-rose-700 dark:text-rose-400' : '' }}"
                        @if ($entry->isPaid) data-state="paid" @endif
                        @if ($entry->isMissed) data-state="missed" @endif
                    >
                        {{-- Series link (drill-through) --}}
                        <a href="/recurring/series/{{ $entry->seriesId }}"
                           class="hover:underline"
                           wire:click.stop>
                            {{ $entry->name }}
                        </a>
                        {{-- Paid / missed badge --}}
                        @if ($entry->isPaid)
                            <span class="ml-0.5 text-emerald-600 dark:text-emerald-400" aria-label="paid">paid</span>
                        @elseif ($entry->isMissed)
                            <span class="ml-0.5 text-rose-600 dark:text-rose-400" aria-label="missed">missed</span>
                        @endif
                        {{-- Counterparty link --}}
                        @if ($entry->counterpartySlug !== null)
                            <a href="/counterparties/{{ $entry->counterpartySlug }}"
                               class="ml-0.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 hover:underline"
                               wire:click.stop>↗</a>
                        @endif
                    </div>
                @endforeach

                {{-- Balance line (always rendered; shows "—" when computing) --}}
                <div class="mt-1 text-right text-xs
                    {{ $day->isRisk && !$day->isComputing ? 'text-rose-600 dark:text-rose-400' : 'text-slate-400 dark:text-slate-500' }}"
                    data-balance="{{ $day->isComputing ? 'computing' : $day->eodBalanceMinor }}">
                    @if ($day->isComputing)
                        <span aria-label="balance computing">—</span>
                    @else
                        {{ number_format(abs($day->eodBalanceMinor / 100), 0, ',', '.') }}
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Day panel (desktop) / bottom sheet (phone): rendered when a day is selected --}}
    @if ($selectedDayDto !== null)
        <aside class="mt-8 rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-950"
               data-panel="day-panel">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                {{ $selectedDayDto->date->format('l j F Y') }}
            </h2>

            @if ($selectedDayDto->entries === [])
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">No payments on this day.</p>
            @else
                <ul class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($selectedDayDto->entries as $entry)
                        <li class="flex items-center justify-between py-2">
                            <div>
                                <a href="/recurring/series/{{ $entry->seriesId }}"
                                   class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 hover:underline">
                                    {{ $entry->name }}
                                </a>
                                @if ($entry->counterpartySlug !== null)
                                    <a href="/counterparties/{{ $entry->counterpartySlug }}"
                                       class="ml-2 text-xs text-slate-400 hover:underline">
                                        /counterparties/{{ $entry->counterpartySlug }}
                                    </a>
                                @endif
                            </div>
                            <span class="text-sm tabular-nums text-slate-600 dark:text-slate-300">
                                {{ number_format(abs($entry->amountMinor / 100), 2, ',', '.') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            {{-- Balance line for the selected day --}}
            <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
                <span class="text-xs text-slate-500 dark:text-slate-400">End-of-day balance</span>
                <span class="text-sm font-semibold tabular-nums
                    {{ $selectedDayDto->isRisk ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-slate-100' }}">
                    @if ($selectedDayDto->isComputing)
                        —
                    @else
                        {{ $selectedDayDto->eodBalanceMinor >= 0 ? '' : '−' }}{{ number_format(abs($selectedDayDto->eodBalanceMinor / 100), 2, ',', '.') }}
                    @endif
                </span>
            </div>
        </aside>
    @endif
</div>
