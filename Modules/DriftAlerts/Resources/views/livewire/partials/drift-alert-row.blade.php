@use('Modules\Core\Public\Enums\SnoozeWindow')
@use('Modules\DriftAlerts\Public\Enums\DriftPageTab')
@use('Modules\Core\Public\Support\Lang')
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
      - $tab : DriftPageTab
      - $tintFor($alert) : Tailwind text-color class
      - $signedFmt($alert) : formatted delta string with leading sign
      - $annualizedFmt($alert) : formatted yearly impact with leading sign
      - $fmt($money) : currency-aware Money formatter
      - $primaryAcknowledge : bool — emerald primary chip if true, slate otherwise
      - $seriesStates : array<int, string> — series id → state
      - $cancellationImpact : ?CancellationImpactDto — projected savings if cancelled (null = unavailable)
      - $showThresholdEditor : bool (default false) — when true, mount the inline threshold-editor popover next to action chips
      - $thresholdBySeriesId : ?array<int, ?int> (default null) — series id → drift_threshold_percent override, loaded by the parent; null (the variable, not an entry) means it was not loaded and the editor reads its own row
--}}

@php
    $showThresholdEditor = $showThresholdEditor ?? false;
    $thresholdBySeriesId = $thresholdBySeriesId ?? null;
@endphp

@php
    $tint = $tintFor($alert);
    $deltaText = $signedFmt($alert);
    $annualizedText = $annualizedFmt($alert);
    $isExpense = $alert->direction === \Modules\Ledger\Public\Enums\Direction::Expense->value;
    $upArrow = ! $alert->delta->isNegative();
    $seriesState = $seriesStates[$alert->recurringSeriesId] ?? null;
@endphp

<x-core::card padding="tight">
    <div class="flex flex-col items-start gap-3 sm:flex-row sm:justify-between sm:gap-4">
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
                <span class="{{ $tint }}" style="font-variant-numeric: tabular-nums;">{{ $annualizedText }}{{ Lang::get('drift-alerts::alerts.row.per_year') }}</span>
            </p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                <span style="font-variant-numeric: tabular-nums;">{{ Lang::get('drift-alerts::alerts.row.meta_prior_now', ['prior' => $fmt($alert->baselineAmount), 'now' => $fmt($alert->latestAmount)]) }}</span>
                <span class="mx-1">·</span>
                <span style="font-variant-numeric: tabular-nums;">{{ Lang::get('drift-alerts::alerts.row.meta_detected', ['date' => $alert->detectedAt->format('d M')]) }}</span>
                <span class="mx-1">·</span>
                <span style="font-variant-numeric: tabular-nums;">{{ Lang::get('drift-alerts::alerts.row.meta_threshold', ['percent' => $alert->thresholdPercentUsed]) }}</span>
                @if ($alert->eurEquivalent !== null)
                    <span class="mx-1">·</span>
                    <span class="text-slate-400 dark:text-slate-500" style="font-variant-numeric: tabular-nums;">{{ Lang::get('drift-alerts::alerts.row.meta_eur_equiv', ['amount' => $fmt($alert->eurEquivalent)]) }}</span>
                @endif
                @if ($cancellationImpact !== null)
                    <span class="mx-1">·</span>
                    <span style="font-variant-numeric: tabular-nums;">{{ Lang::get('drift-alerts::alerts.row.cancel_impact', ['amount' => $fmt($cancellationImpact->annualSavings)]) }}</span>
                @endif
            </p>
            @if ($seriesState === 'cadence_changed')
                {{-- This read "/recurring/review": a route path shown as prose,
                     and the one untranslated fragment in a localised sentence.
                     The link text is the destination page's own title. --}}
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ Lang::get('drift-alerts::alerts.row.cadence_flipped') }}
                    <a
                        href="{{ route('recurring.review') }}"
                        class="tap-link text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:text-slate-100 dark:hover:text-slate-300"
                    >{{ Lang::get('drift-alerts::alerts.row.cadence_flipped_link') }}</a>
                </p>
            @endif
        </div>
        @if ($tab === DriftPageTab::Open)
            <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
                @if ($showThresholdEditor)
                    @livewire('drift-alerts.drift-threshold-editor', [
                        'recurringSeriesId' => $alert->recurringSeriesId,
                        'currentValue' => $thresholdBySeriesId[$alert->recurringSeriesId] ?? null,
                        'currentValueLoaded' => $thresholdBySeriesId !== null,
                    ], key('threshold-row-'.$alert->driftAlertId))
                @endif
                <button
                    type="button"
                    wire:click="acknowledge({{ $alert->driftAlertId }})"
                    aria-label="{{ Lang::get('drift-alerts::alerts.row.acknowledge_aria', ['id' => $alert->driftAlertId]) }}"
                    @class([
                        'inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
                        'bg-emerald-600 text-white hover:bg-emerald-700 focus-visible:ring-emerald-600 dark:bg-emerald-500 dark:hover:bg-emerald-400' => $primaryAcknowledge,
                        'bg-slate-100 text-slate-700 hover:bg-slate-200 focus-visible:ring-slate-900 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700' => ! $primaryAcknowledge,
                    ])
                >{{ Lang::get('drift-alerts::alerts.row.acknowledge') }}</button>
                <div x-data="{ open: false }" class="relative">
                    <button
                        type="button"
                        x-on:click="open = ! open"
                        class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                    >{{ Lang::get('drift-alerts::alerts.row.snooze') }}</button>
                    <div
                        x-show="open"
                        x-cloak
                        x-on:click.outside="open = false"
                        class="absolute right-0 z-10 mt-1 w-48 rounded-md border border-slate-200 bg-white p-2 text-xs shadow-lg dark:bg-slate-950 dark:border-slate-700"
                    >
                        @foreach (SnoozeWindow::cases() as $window)
                            <button
                                type="button"
                                wire:click="snooze({{ $alert->driftAlertId }}, '{{ $snoozeTargets[$window->value] }}')"
                                x-on:click="open = false"
                                class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900"
                            >{{ Lang::get($window->labelKey('drift-alerts::alerts.row')) }}</button>
                        @endforeach
                    </div>
                </div>
                <button
                    type="button"
                    wire:click="modelCancelInForecast({{ $alert->driftAlertId }})"
                    aria-label="{{ Lang::get('drift-alerts::alerts.row.model_cancel_aria', ['id' => $alert->driftAlertId]) }}"
                    class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-900 transition hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                    style="font-variant-numeric: tabular-nums;"
                >{{ Lang::get('drift-alerts::alerts.row.model_cancel') }}</button>
                <button
                    type="button"
                    wire:click="dismissAsCancelled({{ $alert->driftAlertId }})"
                    aria-label="{{ Lang::get('drift-alerts::alerts.row.cancelled_aria', ['id' => $alert->driftAlertId]) }}"
                    class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                >{{ Lang::get('drift-alerts::alerts.row.cancelled') }}</button>
            </div>
        @endif
    </div>
</x-core::card>
