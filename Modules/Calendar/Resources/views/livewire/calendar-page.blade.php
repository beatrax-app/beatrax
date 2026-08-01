{{--
    /calendar page — Phase 6 Plan 03 full UI-SPEC implementation.

    Renders:
      - Month summary strip (§6.2) — rose text when risk days exist; computing spinner
      - Toolbar (§6.1) — prev/next month nav, Accounts popover
      - 7-column Mon–Sun calendar grid (§5, §6.3) with role="grid" semantics
      - Day cells with day number, balance corner, entry rows, overflow badge
      - Desktop right-rail day panel (§6.4) — slide-in via Alpine
      - Phone bottom sheet (§6.5) — via <x-bottom-sheet name="day-detail">
      - Empty state (§7.1) when no approved series

    See UI-SPEC.md §2–§12 for the full binding visual contract.
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
<div class="mx-auto max-w-7xl px-4 py-12" x-data="{ panelOpen: false }">
    <header class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight" style="color: var(--color-text);">Calendar</h1>
        <p class="mt-1 max-w-prose text-sm" style="color: var(--color-text-muted);">
            Upcoming payments and your projected daily balance.
        </p>
    </header>

    {{-- §6.2 Month summary strip (hidden when no risk days, unless computing) --}}
    @php
        // IN-05: count only days OF the display month — the Mon–Sun grid
        // carries lead-in/lead-out cells from adjacent months, and a June
        // view must not headline "dips below €0 on Jul 1".
        $riskDays = array_filter($days, fn ($d) => $d->isRisk && $d->date->month === $displayMonth);
        $riskDayList = array_values($riskDays);
        $riskCount = count($riskDayList);
    @endphp
    @if ($isComputingAny || $riskCount > 0)
        <div class="cal-summary-strip" aria-live="polite" id="summary-strip">
            @if ($isComputingAny)
                <span style="color: var(--color-text-faint);">
                    <svg wire:loading class="inline-block h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Projection updating…
                </span>
            @elseif ($riskCount === 1)
                <span style="color: var(--color-rose);">
                    Balance dips below €0 on {{ $riskDayList[0]->date->format('M j') }}.
                </span>
            @else
                <span style="color: var(--color-rose);">
                    Balance dips below €0 on {{ $riskCount }} days — first: {{ $riskDayList[0]->date->format('M j') }}.
                </span>
            @endif
        </div>
    @endif

    {{-- §6.1 Toolbar: month nav + Accounts popover --}}
    <div class="cal-toolbar">
        <div class="flex items-center gap-2">
            <button
                wire:click="prevMonth"
                class="flex h-11 w-11 items-center justify-center rounded hover:bg-slate-100 dark:hover:bg-slate-800"
                aria-label="Previous month"
                style="min-width: 44px; min-height: 44px;"
            >←</button>
            <span class="text-base font-semibold" style="color: var(--color-text);">
                {{ \Carbon\CarbonImmutable::create($displayYear, $displayMonth, 1)->format('M Y') }}
            </span>
            <button
                wire:click="nextMonth"
                class="flex h-11 w-11 items-center justify-center rounded hover:bg-slate-100 dark:hover:bg-slate-800 disabled:opacity-50"
                aria-label="Next month"
                style="min-width: 44px; min-height: 44px;"
                @if ($atCeiling) disabled aria-disabled="true" @endif
            >→</button>
        </div>

        {{-- Accounts popover --}}
        <div x-data="{ popoverOpen: false }" class="relative">
            <button
                @click="popoverOpen = !popoverOpen"
                class="flex items-center gap-1 rounded border px-3 py-2 text-sm"
                style="border-color: var(--color-border); color: var(--color-text);"
                aria-haspopup="true"
                :aria-expanded="popoverOpen"
            >
                Accounts
                <span class="ml-0.5 text-xs" aria-hidden="true">▾</span>
            </button>
            <div
                x-show="popoverOpen"
                @click.outside="popoverOpen = false; $wire.persistAccountPrefs()"
                {{-- IN-08: guard on popoverOpen — a window-scoped Escape (e.g. closing
                     the day panel) must not fire a persistence round-trip --}}
                @keydown.escape.window="if (popoverOpen) { popoverOpen = false; $wire.persistAccountPrefs() }"
                class="absolute right-0 z-30 mt-1 rounded-lg border bg-white py-2 shadow-lg dark:bg-slate-900"
                style="min-width: 260px; border-color: var(--color-border); top: 100%;"
                role="dialog"
                aria-label="Account display settings"
            >
                @if (empty($accountRoster))
                    <p class="px-4 py-2 text-sm" style="color: var(--color-text-faint);">No accounts found.</p>
                @else
                    <div class="px-3 pb-1 pt-2">
                        <div class="mb-1 grid grid-cols-3 gap-1 text-xs font-semibold" style="color: var(--color-text-faint);">
                            <span class="col-span-1">Account</span>
                            <span class="text-center">Entries</span>
                            <span class="text-center">Balance</span>
                        </div>
                        @foreach ($accountRoster as $acct)
                            <div class="grid grid-cols-3 gap-1 items-center py-1">
                                <span class="col-span-1 truncate text-sm" style="color: var(--color-text);">{{ $acct['name'] }}</span>
                                <div class="flex justify-center">
                                    <input
                                        type="checkbox"
                                        wire:click="toggleEntriesAccount({{ $acct['id'] }})"
                                        @checked(in_array($acct['id'], $visibleAccountIds))
                                        class="h-4 w-4 rounded"
                                        aria-label="Show entries for {{ $acct['name'] }}"
                                    />
                                </div>
                                <div class="flex justify-center">
                                    <input
                                        type="checkbox"
                                        wire:click="toggleBalanceAccount({{ $acct['id'] }})"
                                        @checked(in_array($acct['id'], $balanceAccountIds))
                                        class="h-4 w-4 rounded"
                                        aria-label="Count {{ $acct['name'] }} in balance"
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- §7.1 Empty state --}}
    @if (!$hasEntries)
        <div class="mb-6 rounded-lg border p-8" style="border-color: var(--color-border); background: var(--color-surface);">
            <p class="font-semibold" style="color: var(--color-text);">No upcoming payments</p>
            <p class="mt-1 text-sm" style="color: var(--color-text-muted);">
                Connect an account or approve a recurring series to see your projected payments on the calendar.
            </p>
            <a
                href="/recurring/review"
                class="mt-3 inline-flex items-center text-sm underline hover:no-underline"
                style="color: var(--color-text);"
            >
                Review recurring →
            </a>
        </div>
    @endif

    {{-- §5 Calendar grid + §6.4 desktop right-rail day panel wrapper --}}
    <div class="relative">
        {{--
            7-column Mon–Sun calendar grid.

            A real <table> rather than divs carrying role="row"/"columnheader"/
            "gridcell". role="grid" stays on the table: under ARIA in HTML a
            grid-roled table maps <tr> to row, <th scope="col"> to columnheader
            and <td> to gridcell implicitly, so the accessibility tree is the
            same one the explicit roles built — but assistive tech that ignores
            roles on generic elements now gets the structure natively too.
            The cells used to sit in a flat grid with the week rows collapsed
            via `display: contents`, which is exactly the construct screen
            readers have historically dropped rows from.
        --}}
        <div class="cal-grid-frame">
        <table
            role="grid"
            class="cal-grid"
            aria-label="{{ \Carbon\CarbonImmutable::create($displayYear, $displayMonth, 1)->format('F Y') }} calendar"
            @keydown.arrow-left.prevent="
                let prev = document.activeElement.previousElementSibling;
                if (prev && prev.tagName === 'TD') prev.focus();
            "
            @keydown.arrow-right.prevent="
                let next = document.activeElement.nextElementSibling;
                if (next && next.tagName === 'TD') next.focus();
            "
        >
            {{-- Day-of-week headers --}}
            <thead>
                <tr>
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $heading)
                        <th
                            scope="col"
                            class="px-2 py-1 text-center text-xs font-semibold"
                            style="background: var(--color-bg-subtle); color: var(--color-text-faint); border-right: 1px solid var(--color-border); border-bottom: 1px solid var(--color-border);"
                        >
                            {{ $heading }}
                        </th>
                    @endforeach
                </tr>
            </thead>

            {{-- Day cells grouped in weeks --}}
            @php
                $chunks = array_chunk($days, 7);
            @endphp
            <tbody>
            @foreach ($chunks as $week)
                <tr>
                    @foreach ($week as $day)
                        @php
                            $dateStr      = $day->date->toDateString();
                            $isSelected   = $selectedDay === $dateStr;
                            $entryCount   = count($day->entries);
                            $maxDesktop   = 3;
                            $visibleEntries = array_slice($day->entries, 0, $maxDesktop);
                            $overflow     = $entryCount - $maxDesktop;

                            $cellClasses  = 'cal-cell';
                            if ($day->isToday)    $cellClasses .= ' cal-cell--today';
                            if ($day->isRisk)     $cellClasses .= ' cal-cell--risk';
                            if ($day->date->month !== $displayMonth) $cellClasses .= ' cal-cell--other-month';
                            elseif ($day->isPast && !$day->isToday) $cellClasses .= ' cal-cell--past';

                            // IN-06: cells render whole euros (half-up) as a deliberate density
                            // trade-off — sub-euro balances can read "−€0"/"€1" while the day
                            // panel shows two decimals. The panel is the precise surface; the
                            // grid corner is a glanceable magnitude, and the rose risk tint
                            // (driven by the exact minor-unit sign, not this string) stays correct.
                            $balanceStr = $day->isComputing
                                ? '—'
                                : (($day->eodBalanceMinor < 0 ? '−' : '') . '€' . number_format(abs($day->eodBalanceMinor / Money::MINOR_UNITS_PER_MAJOR), 0, ',', '.'));
                            $balanceColor = ($day->isComputing || $day->eodBalanceMinor >= 0)
                                ? 'var(--color-text-muted)'
                                : 'var(--color-rose)';

                            $ariaLabel = $day->date->format('F j, Y') . ': ' . $entryCount . ' ' . ($entryCount === 1 ? 'entry' : 'entries');
                            if (!$day->isComputing) {
                                // WR-10: announce the sign — a screen reader on a −€450 risk day must not hear "€450"
                                $ariaLabel .= ', projected balance ' . ($day->eodBalanceMinor < 0 ? 'minus ' : '') . '€' . number_format(abs($day->eodBalanceMinor / Money::MINOR_UNITS_PER_MAJOR), 0, ',', '.');
                            }
                        @endphp
                        <td
                            tabindex="0"
                            wire:click="selectDay('{{ $dateStr }}')"
                            @keydown.enter.prevent="$wire.selectDay('{{ $dateStr }}')"
                            @keydown.space.prevent="$wire.selectDay('{{ $dateStr }}')"
                            @keydown.escape.prevent="panelOpen = false; $wire.set('selectedDay', null)"
                            class="{{ $cellClasses }}"
                            data-date="{{ $dateStr }}"
                            aria-label="{{ $ariaLabel }}"
                            @if ($day->isRisk && $riskCount > 0) aria-describedby="summary-strip" @endif
                        >
                            {{-- Day number (top-left) + balance corner (top-right) --}}
                            <div class="flex items-start justify-between">
                                <span class="cal-day-num
                                    {{ $day->isToday ? 'text-blue-600 dark:text-blue-400' : '' }}
                                    {{ $day->date->month !== $displayMonth ? 'text-opacity-40' : '' }}"
                                    style="{{ $day->date->month !== $displayMonth ? 'color: var(--color-text-faint);' : ($day->isToday ? '' : 'color: var(--color-text);') }}"
                                >
                                    {{ $day->date->day }}
                                </span>
                                {{-- Balance corner: desktop only (hidden on phone via CSS) --}}
                                <span class="cal-day-balance hidden sm:inline-block" style="color: {{ $balanceColor }};" aria-hidden="true">
                                    {{ $balanceStr }}
                                </span>
                            </div>

                            {{-- Entry rows (desktop: up to 3; phone: just entry-count badge) --}}
                            <div class="hidden sm:block">
                                @foreach ($visibleEntries as $entry)
                                    @php
                                        $entryClass = 'cal-entry';
                                        if ($entry->direction === 'income') $entryClass .= ' cal-entry--inflow';
                                        if ($entry->isApproximate)          $entryClass .= ' cal-entry--approx';
                                        if ($entry->isPaid)                 $entryClass .= ' cal-entry--paid';
                                        if ($entry->isMissed)               $entryClass .= ' cal-entry--missed';

                                        $amountSign   = $entry->direction === 'income' ? '+' : '−';
                                        $amountStr    = $amountSign . '€' . number_format(abs($entry->amountMinor / Money::MINOR_UNITS_PER_MAJOR), 0, ',', '.');
                                    @endphp
                                    <div class="{{ $entryClass }}">
                                        <span class="min-w-0 flex-1 truncate">
                                            @if ($entry->isApproximate)<span aria-hidden="true" class="text-xs" style="color: var(--color-text-faint);">~</span>@endif{{ $entry->name }}
                                        </span>
                                        <span class="flex-shrink-0 tabular-nums text-xs" aria-hidden="true">
                                            {{ $amountStr }}
                                            @if ($entry->isPaid)<span class="ml-0.5" style="color: var(--color-emerald);" aria-label="Paid">✓</span>@endif
                                            @if ($entry->isMissed)<span class="ml-0.5" style="color: var(--color-amber);" aria-label="Expected — not found">!</span>@endif
                                        </span>
                                    </div>
                                @endforeach

                                @if ($overflow > 0)
                                    <div class="cal-overflow">+{{ $overflow }} more</div>
                                @endif
                            </div>

                            {{-- Phone: entry count badge only --}}
                            @if ($entryCount > 0)
                                <div class="cal-entry-count sm:hidden">{{ $entryCount }}</div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>

        {{-- §6.4 Desktop day panel (right-rail slide-in, hidden on phone) --}}
        @if ($selectedDayDto !== null)
            <aside
                class="cal-day-panel hidden sm:flex sm:flex-col"
                x-show="panelOpen"
                x-data
                x-init="panelOpen = true"
                x-trap.inert="panelOpen"
                @keydown.escape.window="panelOpen = false; $wire.set('selectedDay', null)"
                x-transition:enter="transition ease-[var(--ease-smooth,ease)] duration-200"
                x-transition:enter-start="translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-[var(--ease-smooth,ease)] duration-150"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full"
                aria-label="Day detail panel"
                data-panel="day-panel"
            >
                @include('calendar::livewire.partials.day-panel', ['dayDto' => $selectedDayDto])
            </aside>
        @endif
    </div>

    {{-- §6.5 Phone bottom sheet (open-sheet event dispatched from selectDay()) --}}
    <x-core::bottom-sheet name="day-detail" :title="$selectedDayDto ? $selectedDayDto->date->format('M j') : ''">
        @if ($selectedDayDto !== null)
            @include('calendar::livewire.partials.day-panel', ['dayDto' => $selectedDayDto])
        @endif
    </x-core::bottom-sheet>
</div>
