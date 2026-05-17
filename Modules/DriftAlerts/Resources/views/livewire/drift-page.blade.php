{{--
    /drift page — Open / History / Dismissed tabs over drift_alerts
    rows. The Open tab groups multiple alerts that share a
    recurring_series_id under a `<flux:card>` header with an Alpine
    `x-data="{ open: false }"` collapse toggle. Per-alert actions:
    Acknowledge (emerald primary on single-alert groups, slate
    secondary inside multi-alert groups), Snooze (slate, opens the
    Phase 8 D-810 popover), I cancelled this (slate; dispatches the
    DismissDriftAlertAsCancelled action and emits the corresponding
    Public event).

    Direction-aware sign-color rules are applied to the delta + icon
    only — chrome stays slate per UI-SPEC § Color.

    Blade default `{{ }}` escaping for every interpolation.
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (Money $money): string => $money->currency() === 'EUR'
        ? $money->format('nl_NL')
        : $money->format('en_US');

    $tabs = [
        'open' => 'Open',
        'history' => 'History',
        'dismissed' => 'Dismissed',
    ];

    /**
     * Direction-aware tint for a single drift alert. Expense up and
     * income down read as rose; expense down and income up read as
     * emerald. UI-SPEC § Direction-aware sign-color rules.
     */
    $tintFor = static function (object $row): string {
        $isNegative = $row->delta->isNegative();
        if ($row->direction === 'expense') {
            return $isNegative ? 'text-emerald-700' : 'text-rose-700';
        }
        // income
        return $isNegative ? 'text-rose-700' : 'text-emerald-700';
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
        // Display the threshold inline; the cadence display happens
        // through meta-text where the underlying series cadence is
        // sourced (the planner reserved a per-row cadence join for the
        // follow-on plan — the meta line here echoes detected_at).
        return (string) $thresholdPercent;
    };
@endphp

<div class="mx-auto max-w-5xl px-4 py-12">
    <header class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Drift alerts</h1>
            <p class="mt-2 max-w-prose text-sm text-slate-500">
                Approved recurring series whose latest charge moved outside your threshold.
            </p>
        </div>
        <a
            href="{{ route('settings') }}#drift-threshold"
            class="text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >Adjust threshold →</a>
    </header>

    <nav class="mb-6 flex items-center gap-2 border-b border-slate-200" role="tablist">
        @foreach ($tabs as $key => $label)
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                wire:click="setTab('{{ $key }}')"
                @class([
                    'px-3 py-2 text-sm',
                    'border-b-2 border-slate-900 font-medium text-slate-900' => $tab === $key,
                    'border-b-2 border-transparent text-slate-500 hover:text-slate-900' => $tab !== $key,
                ])
            >{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'open')
        @if (count($grouped) === 0)
            <div class="rounded-lg border border-slate-200 bg-white p-6">
                <h2 class="text-base font-semibold text-slate-900">No open drift alerts</h2>
                <p class="mt-2 max-w-prose text-sm text-slate-500">
                    diederik watches your approved recurring series and flags any whose latest charge differs from the prior amount by more than your threshold.
                    Adjust threshold on
                    <a
                        href="{{ route('settings') }}#drift-threshold"
                        class="text-slate-900 underline underline-offset-2 hover:text-slate-700"
                    >Settings → Default drift alert</a>.
                </p>
            </div>
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
                            class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                        >
                            <div class="flex items-center justify-between gap-4">
                                <button
                                    type="button"
                                    x-on:click="open = ! open"
                                    aria-expanded="false"
                                    x-bind:aria-expanded="open ? 'true' : 'false'"
                                    aria-controls="drift-group-body-{{ $seriesId }}"
                                    class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm font-medium text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 transition-transform" x-bind:class="open ? 'rotate-0' : '-rotate-90'" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                    <span class="truncate">{{ $firstAlert->displayName }}</span>
                                    <flux:badge color="slate" size="sm" class="ml-2" style="font-variant-numeric: tabular-nums;">{{ $alertCount }} drifts open</flux:badge>
                                </button>
                            </div>
                            <div
                                x-show="open"
                                x-cloak
                                id="drift-group-body-{{ $seriesId }}"
                                class="mt-3 space-y-2 border-t border-slate-200 pt-3"
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
                        ])
                    @endif
                @endforeach
            </div>
        @endif
    @else
        @if (count($rows) === 0)
            <div class="rounded-lg border border-slate-200 bg-white p-6">
                @if ($tab === 'history')
                    <h2 class="text-base font-semibold text-slate-900">No acknowledged drifts yet</h2>
                    <p class="mt-2 max-w-prose text-sm text-slate-500">
                        Acknowledged drift alerts will appear here so you can see what you've already reviewed.
                    </p>
                @else
                    <h2 class="text-base font-semibold text-slate-900">Nothing dismissed yet</h2>
                    <p class="mt-2 max-w-prose text-sm text-slate-500">
                        When you tell diederik you've cancelled a series, that decision lands here with a timestamp.
                    </p>
                @endif
            </div>
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
                        ])
                    </li>
                @endforeach
            </ul>
            @if (count($rows) >= 26)
                <div class="mt-6 flex justify-center">
                    <button
                        type="button"
                        wire:click="$set('cursorId', {{ $rows[count($rows) - 1]->driftAlertId }})"
                        class="text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                    >Load more</button>
                </div>
            @endif
        @endif
    @endif
</div>
