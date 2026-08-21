@use('Modules\Core\Public\Support\Lang')
{{--
    Drift alerts list. Three tabs (Open / History / Dismissed) over
    drift_alerts rows. The Open tab groups multiple alerts that share
    a recurring_series_id under a `<flux:card>` header with an Alpine
    `x-data="{ open: false }"` collapse toggle. Per-alert actions:
    Acknowledge (emerald primary on single-alert groups, slate
    secondary inside multi-alert groups), Snooze (slate, opens a
    1w / 1m / 3m popover), "I cancelled this" (slate; dispatches the
    DismissDriftAlertAsCancelled action and emits the corresponding
    Public event).

    Direction-aware sign-color rules are applied to the delta + icon
    only — chrome stays slate.

    Blade default `{{ }}` escaping for every interpolation.
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (Money $money): string => $money->format();

    $tabs = [
        'open' => Lang::get('drift-alerts::alerts.tabs.open'),
        'history' => Lang::get('drift-alerts::alerts.tabs.history'),
        'dismissed' => Lang::get('drift-alerts::alerts.tabs.dismissed'),
    ];

    /**
     * Direction-aware tint for a single drift alert. Expense up and
     * income down read as rose; expense down and income up read as
     * emerald. Dark companions step into rose-300 / emerald-300 for
     * the inline pill text on a slate-950 surface (UI-SPEC D-15).
     */
    $tintFor = static function (object $row): string {
        $isNegative = $row->delta->isNegative();
        if ($row->direction === \Modules\Ledger\Public\Enums\Direction::Expense->value) {
            return $isNegative ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300';
        }
        // income
        return $isNegative ? 'text-rose-700 dark:text-rose-300' : 'text-emerald-700 dark:text-emerald-300';
    };

    $signedFmt = static function (object $row) use ($fmt): string {
        $primary = $fmt($row->delta);
        if (! $row->delta->isNegative() && ! str_starts_with($primary, '+')) {
            return '+'.$primary;
        }
        return $primary;
    };

    $annualizedFmt = static function (object $row) use ($fmt): string {
        $primary = $fmt($row->annualizedImpact);
        if (! $row->annualizedImpact->isNegative() && ! str_starts_with($primary, '+')) {
            return '+'.$primary;
        }
        return $primary;
    };

    $cadenceShort = static function (int $thresholdPercent): string {
        // Display the threshold inline; the per-row meta line echoes
        // detected_at because the source series's cadence is not joined
        // into this query.
        return (string) $thresholdPercent;
    };
@endphp

<div class="mx-auto max-w-5xl px-4 py-12">
    {{-- flex-wrap plus min-w-0: at phone width the two children were both
         squeezed rather than reflowed, and the action lost, breaking
         "Drempel aanpassen →" over three lines with the arrow alone on the
         last. Wrapping moves it to its own row intact. --}}
    <header class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('drift-alerts::alerts.heading') }}</h1>
            <p class="mt-2 max-w-prose text-sm text-slate-500 dark:text-slate-400">
                @if ($type === 'anomaly')
                    {{ Lang::get('drift-alerts::alerts.intro_anomaly') }}
                @else
                    {{ Lang::get('drift-alerts::alerts.intro_drift') }}
                @endif
            </p>
        </div>
        @if ($type === 'drift')
            <a
                href="{{ route('settings') }}#drift-threshold"
                class="shrink-0 whitespace-nowrap text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-slate-100"
            >{{ Lang::get('drift-alerts::alerts.adjust_threshold') }}</a>
        @else
            <a
                href="{{ route('settings') }}#anomaly-detection"
                class="shrink-0 whitespace-nowrap text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-slate-100"
            >{{ Lang::get('drift-alerts::alerts.adjust_sensitivity') }}</a>
        @endif
    </header>

    {{-- Level 1 — type switch. Segmented button group; "type
         first, lifecycle second". Stacks full-width on phone. --}}
    <div class="mb-6 flex w-full gap-1 rounded-lg border border-slate-200 bg-slate-100 p-1 dark:border-slate-700 dark:bg-slate-800 sm:w-auto sm:inline-flex" role="tablist" aria-label="{{ Lang::get('drift-alerts::alerts.type_aria') }}">
        @foreach (['drift' => Lang::get('drift-alerts::alerts.type.drift'), 'anomaly' => Lang::get('drift-alerts::alerts.type.anomaly')] as $typeKey => $typeLabel)
            <button
                type="button"
                role="tab"
                aria-selected="{{ $type === $typeKey ? 'true' : 'false' }}"
                wire:click="setType('{{ $typeKey }}')"
                @class([
                    'flex-1 rounded-md px-3 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 sm:flex-none',
                    'bg-white text-slate-900 shadow-sm dark:bg-slate-950 dark:text-slate-100' => $type === $typeKey,
                    'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100' => $type !== $typeKey,
                ])
            >{{ $typeLabel }}</button>
        @endforeach
    </div>

    {{-- Level 2 — lifecycle tabs (shared between types). --}}
    <nav class="mb-6 flex items-center gap-2 border-b border-slate-200 dark:border-slate-700" role="tablist" aria-label="{{ Lang::get('drift-alerts::alerts.lifecycle_aria') }}">
        @foreach ($tabs as $key => $label)
            <x-core::tab
                :active="$tab === $key"
                id="drift-lifecycle-tab-{{ $key }}"
                aria-controls="drift-lifecycle-panel"
                wire:click="setTab('{{ $key }}')"
            >{{ $label }}</x-core::tab>
        @endforeach
    </nav>

    {{-- One panel, not three: only the selected tab's rows are ever in the
         DOM, so every tab points aria-controls at this one element and the
         panel takes its name from whichever tab is selected. --}}
    <div id="drift-lifecycle-panel" role="tabpanel" aria-labelledby="drift-lifecycle-tab-{{ $tab }}">
        @if ($type === 'anomaly')
            @php
                $anomalyFmt = static fn (Money $money): string => $money->format();

                $anomalyTintFor = static function (object $row): string {
                    $up = abs($row->latestAmount->toMinor()) >= abs($row->baselineAmount->toMinor());
                    if ($row->direction === \Modules\Ledger\Public\Enums\Direction::Expense->value) {
                        return $up ? 'text-rose-700 dark:text-rose-300' : 'text-emerald-700 dark:text-emerald-300';
                    }
                    // income: up is good (emerald), down is bad (rose)
                    return $up ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300';
                };

                $anomalyEmpty = [
                    'open' => [Lang::get('drift-alerts::alerts.anomaly_empty.open_heading'), Lang::get('drift-alerts::alerts.anomaly_empty.open_body')],
                    'history' => [Lang::get('drift-alerts::alerts.anomaly_empty.history_heading'), Lang::get('drift-alerts::alerts.anomaly_empty.history_body')],
                    'dismissed' => [Lang::get('drift-alerts::alerts.anomaly_empty.dismissed_heading'), Lang::get('drift-alerts::alerts.anomaly_empty.dismissed_body')],
                ];
            @endphp

            @if (count($anomalyRows) === 0)
                @php [$emptyHeading, $emptyBody] = $anomalyEmpty[$tab] ?? $anomalyEmpty['open']; @endphp
                <x-core::empty-state :heading="$emptyHeading" :body="$emptyBody" />
            @else
                <ul class="space-y-3" aria-live="polite">
                    @foreach ($anomalyRows as $anomaly)
                        <li>
                            @include('anomaly::livewire.partials.anomaly-alert-row', [
                                'alert' => $anomaly,
                                'tab' => $tab,
                                'fmt' => $anomalyFmt,
                                'tintFor' => $anomalyTintFor,
                                'snoozeTargets' => $snoozeTargets,
                            ])
                        </li>
                    @endforeach
                </ul>
                @if (count($anomalyRows) >= 26)
                    <div class="mt-6 flex justify-center">
                        <button
                            type="button"
                            wire:click="loadMoreAnomalies('{{ $anomalyRows[count($anomalyRows) - 1]->detectedAt->toDateTimeString() }}', '{{ $anomalyRows[count($anomalyRows) - 1]->anomalyAlertId }}')"
                            class="shrink-0 whitespace-nowrap text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-slate-100"
                        >{{ Lang::get('drift-alerts::alerts.load_more') }}</button>
                    </div>
                @endif
            @endif
        @elseif ($tab === 'open')
            @if (count($grouped) === 0)
                <x-core::empty-state :heading="Lang::get('drift-alerts::alerts.empty_open.heading')">
                    {{-- A slot, not the :body prop: the settings link finishes the
                         sentence, and the prop escapes its value. --}}
                    <x-slot:body>
                        {{ Lang::get('drift-alerts::alerts.empty_open.body') }}
                        <a
                            href="{{ route('settings') }}#drift-threshold"
                            class="text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:text-slate-100 dark:hover:text-slate-300"
                        >{{ Lang::get('drift-alerts::alerts.empty_open.link') }}</a>.
                    </x-slot:body>
                </x-core::empty-state>
            @else
                <div class="space-y-4">
                    @foreach ($grouped as $seriesId => $groupAlerts)
                        @php
                            $alertCount = count($groupAlerts);
                            $firstAlert = $groupAlerts[0];
                        @endphp
                        @if ($alertCount >= 2)
                            <flux:card
                                x-data="{ open: false }"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700"
                            >
                                <div class="flex items-center justify-between gap-4">
                                    <button
                                        type="button"
                                        x-on:click="open = ! open"
                                        aria-expanded="false"
                                        x-bind:aria-expanded="open ? 'true' : 'false'"
                                        aria-controls="drift-group-body-{{ $seriesId }}"
                                        class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm font-medium text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-100"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 transition-transform" x-bind:class="open ? 'rotate-0' : '-rotate-90'" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                        </svg>
                                        <span class="truncate">{{ $firstAlert->displayName }}</span>
                                        <flux:badge color="slate" size="sm" class="ml-2" style="font-variant-numeric: tabular-nums;">{{ Lang::get('drift-alerts::alerts.group_count', ['count' => $alertCount]) }}</flux:badge>
                                    </button>
                                    <div class="shrink-0">
                                        @livewire('drift-alerts.drift-threshold-editor', ['recurringSeriesId' => $seriesId], key('threshold-group-'.$seriesId))
                                    </div>
                                </div>
                                <div
                                    x-show="open"
                                    x-cloak
                                    id="drift-group-body-{{ $seriesId }}"
                                    class="mt-3 space-y-2 border-t border-slate-200 pt-3 dark:border-slate-700"
                                >
                                    @foreach ($groupAlerts as $alert)
                                        @include('drift-alerts::livewire.partials.drift-alert-row', [
                                            'alert' => $alert,
                                            'tab' => 'open',
                                            'tintFor' => $tintFor,
                                            'signedFmt' => $signedFmt,
                                            'annualizedFmt' => $annualizedFmt,
                                            'fmt' => $fmt,
                                            'snoozeTargets' => $snoozeTargets,
                                            'primaryAcknowledge' => false,
                                            'seriesStates' => $seriesStates,
                                            'cancellationImpact' => $impactBySeriesId[$alert->recurringSeriesId] ?? null,
                                        ])
                                    @endforeach
                                </div>
                            </flux:card>
                        @else
                            @include('drift-alerts::livewire.partials.drift-alert-row', [
                                'alert' => $firstAlert,
                                'tab' => 'open',
                                'tintFor' => $tintFor,
                                'signedFmt' => $signedFmt,
                                'annualizedFmt' => $annualizedFmt,
                                'fmt' => $fmt,
                                'snoozeTargets' => $snoozeTargets,
                                'primaryAcknowledge' => true,
                                'seriesStates' => $seriesStates,
                                'cancellationImpact' => $impactBySeriesId[$firstAlert->recurringSeriesId] ?? null,
                                'showThresholdEditor' => true,
                            ])
                        @endif
                    @endforeach
                </div>
            @endif
        @else
            @if (count($rows) === 0)
                @if ($tab === 'history')
                    <x-core::empty-state
                        :heading="Lang::get('drift-alerts::alerts.empty_history.heading')"
                        :body="Lang::get('drift-alerts::alerts.empty_history.body')"
                    />
                @else
                    <x-core::empty-state
                        :heading="Lang::get('drift-alerts::alerts.empty_dismissed.heading')"
                        :body="Lang::get('drift-alerts::alerts.empty_dismissed.body')"
                    />
                @endif
            @else
                <ul class="space-y-3">
                    @foreach ($rows as $alert)
                        <li>
                            @include('drift-alerts::livewire.partials.drift-alert-row', [
                                'alert' => $alert,
                                'tab' => $tab,
                                'tintFor' => $tintFor,
                                'signedFmt' => $signedFmt,
                                'annualizedFmt' => $annualizedFmt,
                                'fmt' => $fmt,
                                'snoozeTargets' => $snoozeTargets,
                                'primaryAcknowledge' => false,
                                'seriesStates' => $seriesStates,
                                'cancellationImpact' => $impactBySeriesId[$alert->recurringSeriesId] ?? null,
                            ])
                        </li>
                    @endforeach
                </ul>
                @if (count($rows) >= 26)
                    <div class="mt-6 flex justify-center">
                        <button
                            type="button"
                            wire:click="$set('cursorId', {{ $rows[count($rows) - 1]->driftAlertId }})"
                            class="shrink-0 whitespace-nowrap text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-slate-100"
                        >{{ Lang::get('drift-alerts::alerts.load_more') }}</button>
                    </div>
                @endif
            @endif
        @endif
    </div>
</div>
