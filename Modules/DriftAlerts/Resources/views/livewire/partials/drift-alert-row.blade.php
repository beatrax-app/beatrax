{{--
    A single drift alert row. Renders the direction-aware icon + delta
    + annualized impact + (Open-tab only) Acknowledge / Snooze / "I
    cancelled this" action chips. The Snooze chip opens a popover
    (Alpine `x-data="{ open: false }"`, click-outside closes) offering
    1 week / 1 month / 3 months. The "I cancelled this" chip is wired
    to the DismissDriftAlertAsCancelled action — the action is
    reversible during the 10-second toast window.

    Variables in scope:
      - $alert : DriftAlertDto
      - $tab : 'open' | 'history' | 'dismissed'
      - $tintFor($alert) : Tailwind text-color class
      - $signedFmt($alert) : formatted delta string with leading sign
      - $annualizedFmt($alert) : formatted yearly impact with leading sign
      - $fmt($money) : currency-aware Money formatter
      - $snoozeTargets : array<'1w'|'1m'|'3m', string ISO8601>
      - $primaryAcknowledge : bool — emerald primary chip if true, slate otherwise
      - $seriesStates : array<int, string> — series id → state
      - $cancellationImpact : ?CancellationImpactDto — projected savings if cancelled (null = unavailable)
      - $showThresholdEditor : bool (default false) — when true, mount the inline threshold-editor popover next to action chips
--}}

@php
    $showThresholdEditor = $showThresholdEditor ?? false;
@endphp

@php
    $tint = $tintFor($alert);
    $deltaText = $signedFmt($alert);
    $annualizedText = $annualizedFmt($alert);
    $isExpense = $alert->direction === 'expense';
    $upArrow = ! $alert->delta->isNegative();
    $seriesState = $seriesStates[$alert->recurringSeriesId] ?? null;
@endphp

<div class="rounded-lg border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-baseline gap-2 text-sm">
                @if ($upArrow)
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 shrink-0 {{ $tint }}" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" />
                    </svg>
                @else
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 shrink-0 {{ $tint }}" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6 9 12.75l4.286-4.286a11.948 11.948 0 0 1 4.306 6.43l.776 2.898m0 0 3.182-5.511m-3.182 5.51-5.511-3.181" />
                    </svg>
                @endif
                <span class="font-medium text-slate-900 dark:text-slate-100">{{ $alert->displayName }}</span>
                <span class="{{ $tint }}" style="font-variant-numeric: tabular-nums;">{{ $deltaText }}</span>
                <span class="text-slate-500 dark:text-slate-400">→</span>
                <span class="{{ $tint }}" style="font-variant-numeric: tabular-nums;">{{ $annualizedText }}/yr</span>
            </p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                <span style="font-variant-numeric: tabular-nums;">prior {{ $fmt($alert->baselineAmount) }} → now {{ $fmt($alert->latestAmount) }}</span>
                <span class="mx-1">·</span>
                <span style="font-variant-numeric: tabular-nums;">detected {{ $alert->detectedAt->format('d M') }}</span>
                <span class="mx-1">·</span>
                <span style="font-variant-numeric: tabular-nums;">threshold ±{{ $alert->thresholdPercentUsed }}%</span>
                @if ($alert->eurEquivalent !== null)
                    <span class="mx-1">·</span>
                    <span class="text-slate-400 dark:text-slate-500" style="font-variant-numeric: tabular-nums;">(≈ {{ $fmt($alert->eurEquivalent) }}/yr)</span>
                @endif
                @if ($cancellationImpact !== null)
                    <span class="mx-1">·</span>
                    <span style="font-variant-numeric: tabular-nums;">Cancel this → save {{ $fmt($cancellationImpact->annualSavings) }}/yr</span>
                @endif
            </p>
            @if ($seriesState === 'cadence_changed')
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Cadence flipped — also showing in
                    <a
                        href="{{ route('recurring.review') }}"
                        class="text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:text-slate-100 dark:hover:text-slate-300"
                    >/recurring/review</a>
                </p>
            @endif
        </div>
        @if ($tab === 'open')
            <div class="flex shrink-0 items-center gap-2">
                @if ($showThresholdEditor)
                    @livewire('drift-alerts.drift-threshold-editor', ['recurringSeriesId' => $alert->recurringSeriesId], key('threshold-row-'.$alert->driftAlertId))
                @endif
                <button
                    type="button"
                    wire:click="acknowledge({{ $alert->driftAlertId }})"
                    aria-label="Acknowledge drift alert {{ $alert->driftAlertId }}"
                    @class([
                        'inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
                        'bg-emerald-600 text-white hover:bg-emerald-700 focus-visible:ring-emerald-600 dark:bg-emerald-500 dark:hover:bg-emerald-400' => $primaryAcknowledge,
                        'bg-slate-100 text-slate-700 hover:bg-slate-200 focus-visible:ring-slate-900 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700' => ! $primaryAcknowledge,
                    ])
                >Acknowledge</button>
                <div x-data="{ open: false }" class="relative">
                    <button
                        type="button"
                        x-on:click="open = ! open"
                        class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                    >Snooze ▾</button>
                    <div
                        x-show="open"
                        x-cloak
                        x-on:click.outside="open = false"
                        class="absolute right-0 z-10 mt-1 w-48 rounded-md border border-slate-200 bg-white p-2 text-xs shadow-lg dark:bg-slate-950 dark:border-slate-700"
                    >
                        <button
                            type="button"
                            wire:click="snooze({{ $alert->driftAlertId }}, '{{ $snoozeTargets['1w'] }}')"
                            x-on:click="open = false"
                            class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900"
                        >1 week</button>
                        <button
                            type="button"
                            wire:click="snooze({{ $alert->driftAlertId }}, '{{ $snoozeTargets['1m'] }}')"
                            x-on:click="open = false"
                            class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900"
                        >1 month</button>
                        <button
                            type="button"
                            wire:click="snooze({{ $alert->driftAlertId }}, '{{ $snoozeTargets['3m'] }}')"
                            x-on:click="open = false"
                            class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900"
                        >3 months</button>
                    </div>
                </div>
                <button
                    type="button"
                    wire:click="modelCancelInForecast({{ $alert->driftAlertId }})"
                    aria-label="Model cancel — models the cancellation in the forecast for drift alert {{ $alert->driftAlertId }}"
                    class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-900 transition hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                    style="font-variant-numeric: tabular-nums;"
                >Model cancel ↗</button>
                <button
                    type="button"
                    wire:click="dismissAsCancelled({{ $alert->driftAlertId }})"
                    aria-label="I cancelled this — dismisses drift alert {{ $alert->driftAlertId }} as cancelled"
                    class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                >I cancelled this</button>
            </div>
        @endif
    </div>
</div>
