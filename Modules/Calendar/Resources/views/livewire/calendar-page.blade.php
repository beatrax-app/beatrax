@use('Modules\Core\Public\Support\Lang')
@use('Modules\Ledger\Public\Enums\Direction')
{{--
    /calendar page — the full month-grid surface.

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
        <h1 class="text-2xl font-semibold tracking-tight" style="color: var(--color-text);">{{ Lang::get('calendar::messages.page.title') }}</h1>
        <p class="mt-1 max-w-prose text-sm" style="color: var(--color-text-muted);">
            {{ Lang::get('calendar::messages.page.subtitle') }}
        </p>
    </header>

    {{-- §6.2 Month summary strip (hidden when no risk days, unless computing) --}}
    @php
        // Count only days OF the display month — the Mon–Sun grid
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
                    <x-core::spinner wire:loading />
                    {{ Lang::get('calendar::messages.summary.computing') }}
                </span>
            @else
                <span style="color: var(--color-rose);">
                    {{ Lang::choice('calendar::messages.summary.risk', $riskCount, ['count' => $riskCount, 'date' => $riskDayList[0]->date->translatedFormat('j M')]) }}
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
                aria-label="{{ Lang::get('calendar::messages.toolbar.prev_month') }}"
                style="min-width: 44px; min-height: 44px;"
            >←</button>
            <span class="text-base font-semibold" style="color: var(--color-text);">
                {{ \Carbon\CarbonImmutable::create($displayYear, $displayMonth, 1)->translatedFormat('M Y') }}
            </span>
            <button
                wire:click="nextMonth"
                class="flex h-11 w-11 items-center justify-center rounded hover:bg-slate-100 dark:hover:bg-slate-800 disabled:opacity-50"
                aria-label="{{ Lang::get('calendar::messages.toolbar.next_month') }}"
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
                {{ Lang::get('calendar::messages.toolbar.accounts') }}
                <span class="ml-0.5 text-xs" aria-hidden="true">▾</span>
            </button>
            <div
                x-show="popoverOpen"
                @click.outside="popoverOpen = false; $wire.persistAccountPrefs()"
                {{-- Guard on popoverOpen — a window-scoped Escape (e.g. closing
                     the day panel) must not fire a persistence round-trip --}}
                @keydown.escape.window="if (popoverOpen) { popoverOpen = false; $wire.persistAccountPrefs() }"
                class="absolute right-0 z-30 mt-1 rounded-lg border bg-white py-2 shadow-lg dark:bg-slate-900"
                style="min-width: 260px; border-color: var(--color-border); top: 100%;"
                role="dialog"
                aria-label="{{ Lang::get('calendar::messages.toolbar.popover_aria') }}"
            >
                @if (empty($accountRoster))
                    <p class="px-4 py-2 text-sm" style="color: var(--color-text-faint);">{{ Lang::get('calendar::messages.toolbar.no_accounts') }}</p>
                @else
                    <div class="px-3 pb-1 pt-2">
                        <div class="mb-1 grid grid-cols-3 gap-1 text-xs font-semibold" style="color: var(--color-text-faint);">
                            <span class="col-span-1">{{ Lang::get('calendar::messages.toolbar.col_account') }}</span>
                            <span class="text-center">{{ Lang::get('calendar::messages.toolbar.col_entries') }}</span>
                            <span class="text-center">{{ Lang::get('calendar::messages.toolbar.col_balance') }}</span>
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
                                        aria-label="{{ Lang::get('calendar::messages.toolbar.show_entries_aria', ['name' => $acct['name']]) }}"
                                    />
                                </div>
                                <div class="flex justify-center">
                                    <input
                                        type="checkbox"
                                        wire:click="toggleBalanceAccount({{ $acct['id'] }})"
                                        @checked(in_array($acct['id'], $balanceAccountIds))
                                        class="h-4 w-4 rounded"
                                        aria-label="{{ Lang::get('calendar::messages.toolbar.count_balance_aria', ['name' => $acct['name']]) }}"
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
    @if (!$hasProjectableEntries)
        <x-core::empty-state
            class="mb-6"
            :heading="Lang::get('calendar::messages.empty.heading')"
            :body="Lang::get('calendar::messages.empty.body')"
        >
            {{-- The 44px floor in app.css covers <button> and <summary>, and
                 the one way out of an empty calendar is a link — 20px tall on
                 a phone. Spelled inline, as the month arrows above are. --}}
            <a
                href="{{ route('recurring.review') }}"
                class="inline-flex items-center text-sm font-medium underline-offset-2 hover:underline"
                style="color: var(--color-text); min-height: 44px;"
            >
                {{ Lang::get('calendar::messages.empty.review') }}
            </a>
        </x-core::empty-state>
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
            aria-label="{{ Lang::get('calendar::messages.grid.aria', ['month' => \Carbon\CarbonImmutable::create($displayYear, $displayMonth, 1)->translatedFormat('F Y')]) }}"
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
                    @foreach ([Lang::get('calendar::messages.weekdays.mon'), Lang::get('calendar::messages.weekdays.tue'), Lang::get('calendar::messages.weekdays.wed'), Lang::get('calendar::messages.weekdays.thu'), Lang::get('calendar::messages.weekdays.fri'), Lang::get('calendar::messages.weekdays.sat'), Lang::get('calendar::messages.weekdays.sun')] as $heading)
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

                            // Today is marked on the day number itself, not with
                            // .cal-cell--today: an inset ring on a cell that already
                            // has four borders read as a stray box, not as a state.
                            $cellClasses  = 'cal-cell';
                            if ($day->isRisk)     $cellClasses .= ' cal-cell--risk';
                            if ($day->date->month !== $displayMonth) $cellClasses .= ' cal-cell--other-month';
                            elseif ($day->isPast && !$day->isToday) $cellClasses .= ' cal-cell--past';

                            // Cells render whole units (half-up) as a deliberate density
                            // trade-off — sub-euro balances can read "€ -0"/"€ 1" while the day
                            // panel shows two decimals. The panel is the precise surface; the
                            // grid corner is a glanceable magnitude, and the rose risk tint
                            // (driven by the exact minor-unit sign, not this string) stays correct.
                            $balanceStr = $day->isComputing
                                ? '—'
                                : Money::ofMinor($day->eodBalanceMinor, $day->currency)->formatWholeUnits();
                            $balanceColor = ($day->isComputing || $day->eodBalanceMinor >= 0)
                                ? 'var(--color-text-muted)'
                                : 'var(--color-rose)';

                            $entriesWord = Lang::choice('calendar::messages.cell.entry', $entryCount);
                            $ariaLabel = Lang::get('calendar::messages.cell.aria', [
                                'date' => $day->date->translatedFormat('j F Y'),
                                'count' => $entryCount,
                                'entries' => $entriesWord,
                            ]);
                            if (!$day->isComputing) {
                                // Announce the sign — a screen reader on a −€450 risk day must not hear "€450"
                                $balanceAmount = Money::ofMinor(abs($day->eodBalanceMinor), $day->currency)->formatWholeUnits();
                                $ariaLabel .= $day->eodBalanceMinor < 0
                                    ? Lang::get('calendar::messages.cell.aria_balance_negative', ['amount' => $balanceAmount])
                                    : Lang::get('calendar::messages.cell.aria_balance_positive', ['amount' => $balanceAmount]);
                            }
                        @endphp
                        <td
                            tabindex="0"
                            wire:click="selectDay('{{ $dateStr }}')"
                            @keydown.enter.prevent="$wire.selectDay('{{ $dateStr }}')"
                            @keydown.space.prevent="$wire.selectDay('{{ $dateStr }}')"
                            @keydown.escape.prevent="panelOpen = false; $wire.set('selectedDay', null)"
                            class="{{ $cellClasses }} align-middle text-center sm:align-top sm:text-left"
                            data-date="{{ $dateStr }}"
                            aria-label="{{ $ariaLabel }}"
                            @if ($day->isRisk && $riskCount > 0) aria-describedby="summary-strip" @endif
                        >
                            {{-- Day number (centred on phone, top-left beside the
                                 balance corner once the entry rows appear) --}}
                            <div class="flex items-center justify-center sm:justify-between">
                                <span class="cal-day-num
                                    {{ $day->isToday ? 'inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-white dark:bg-blue-500' : '' }}"
                                    style="{{ $day->isToday ? '' : ($day->date->month !== $displayMonth ? 'color: var(--color-text-faint);' : 'color: var(--color-text);') }}"
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
                                        if ($entry->direction === Direction::Income->value) $entryClass .= ' cal-entry--inflow';
                                        if ($entry->isApproximate)          $entryClass .= ' cal-entry--approx';
                                        if ($entry->isPaid)                 $entryClass .= ' cal-entry--paid';
                                        if ($entry->isMissed)               $entryClass .= ' cal-entry--missed';

                                        $amountSign   = $entry->direction === Direction::Income->value ? '+' : '−';
                                        $amountStr    = $amountSign . Money::ofMinor(abs($entry->amountMinor), $entry->currency)->formatWholeUnits();
                                    @endphp
                                    <div class="{{ $entryClass }}">
                                        <span class="min-w-0 flex-1 truncate">
                                            @if ($entry->isApproximate)<span aria-hidden="true" class="text-xs" style="color: var(--color-text-faint);">~</span>@endif{{ $entry->name }}
                                        </span>
                                        {{-- The ✓ / ! markers below carry no aria-label: this span is
                                             aria-hidden, so nothing inside it reaches a screen reader.
                                             Per-entry paid/missed is announced in the day panel. --}}
                                        <span class="flex-shrink-0 tabular-nums text-xs" aria-hidden="true">
                                            {{ $amountStr }}
                                            @if ($entry->isPaid)<span class="ml-0.5" style="color: var(--color-emerald);">✓</span>@endif
                                            @if ($entry->isMissed)<span class="ml-0.5" style="color: var(--color-amber);">!</span>@endif
                                        </span>
                                    </div>
                                @endforeach

                                @if ($overflow > 0)
                                    <div class="cal-overflow">{{ Lang::get('calendar::messages.cell.overflow', ['count' => $overflow]) }}</div>
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
                @keydown.escape.window="panelOpen = false; $wire.set('selectedDay', null)"
                x-transition:enter="transition ease-[var(--ease-smooth,ease)] duration-200"
                x-transition:enter-start="translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-[var(--ease-smooth,ease)] duration-150"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full"
                aria-label="{{ Lang::get('calendar::messages.panel.aria') }}"
                data-panel="day-panel"
            >
                @include('calendar::livewire.partials.day-panel', ['dayDto' => $selectedDayDto])
            </aside>
        @endif
    </div>

    {{-- §6.5 Phone bottom sheet (open-sheet event dispatched from selectDay()) --}}
    {{-- The sheet's title IS the panel's heading here — j M Y, not j M, so the
         year is not lost when the panel stops repeating it. --}}
    <x-core::bottom-sheet name="day-detail" :title="$selectedDayDto ? $selectedDayDto->date->translatedFormat('j M Y') : ''">
        @if ($selectedDayDto !== null)
            @include('calendar::livewire.partials.day-panel', ['dayDto' => $selectedDayDto, 'showDate' => false])
        @endif
    </x-core::bottom-sheet>
</div>
