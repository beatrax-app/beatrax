@use('Modules\Core\Public\Support\Lang')
@use('Modules\DriftAlerts\Public\Enums\DriftPageTab')
{{--
    A single anomaly alert row — one alert per transaction, possibly
    multi-reason. Cloned from drift-alert-row but re-shaped for a
    point-in-time unusual charge:
      - reason micro-chips (large / first-time / duplicate) after the
        merchant name, color-coded per UI-SPEC §2
      - a `baseline → actual · detected {date} · sensitivity ±{N}%`
        sub-line (no per-year cost-projection column — anomalies are
        one-off events, not recurring cost projections)
      - four action chips on the Open tab: Acknowledge (emerald primary
        when single-reason) / Snooze ▾ / Mark as expected / Dismiss

    Variables in scope:
      - $alert : AnomalyAlertDto
      - $tab : DriftPageTab
      - $fmt($money) : currency-aware Money formatter
      - $tintFor($alert) : direction-aware Tailwind text-color class
      - $snoozeTargets : SnoozeWindow::targetsFrom() output

    Blade default `{{ }}` escaping for every interpolation.
--}}

@php
    $tint = $tintFor($alert);
    $isExpense = $alert->direction === \Modules\Ledger\Public\Enums\Direction::Expense->value;
    // Whether the charge moved "up" (more spend / more income) relative
    // to the baseline — drives the arrow glyph + large-charge chip tint.
    $latestMinor = $alert->latestAmount->toMinor();
    $baselineMinor = $alert->baselineAmount->toMinor();
    $upArrow = abs($latestMinor) >= abs($baselineMinor);
@endphp

<x-core::card padding="tight">
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
                <span class="font-medium text-slate-900 dark:text-slate-100">{{ $alert->displayName !== '' ? $alert->displayName : Lang::get('anomaly::alerts.unknown_merchant') }}</span>
                @foreach ($alert->reasons as $reason)
                    @php $label = Lang::get('anomaly::alerts.reasons.'.$reason); @endphp
                    @if ($reason === 'first_time')
                        <span
                            role="img"
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            style="background: var(--color-blue-bg); color: var(--color-blue);"
                            aria-label="{{ Lang::get('anomaly::alerts.reason_aria.first_time') }}"
                        >{{ $label }}</span>
                    @elseif ($reason === 'duplicate')
                        <span
                            role="img"
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            style="background: var(--color-amber-bg); color: var(--color-amber);"
                            aria-label="{{ Lang::get('anomaly::alerts.reason_aria.duplicate') }}"
                        >{{ $label }}</span>
                    @else
                        {{-- large (or any future detector): direction-aware tint --}}
                        <span
                            role="img"
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium {{ $tint }} dark:bg-slate-800"
                            aria-label="{{ Lang::get('anomaly::alerts.reason_aria.generic', ['label' => strtolower($label)]) }}"
                        >{{ $label }}</span>
                    @endif
                @endforeach
            </p>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                <span style="font-variant-numeric: tabular-nums;">{{ Lang::get('anomaly::alerts.baseline_to_actual', ['baseline' => $fmt($alert->baselineAmount), 'actual' => $fmt($alert->latestAmount)]) }}</span>
                <span class="mx-1">·</span>
                <span style="font-variant-numeric: tabular-nums;">{{ Lang::get('anomaly::alerts.detected', ['date' => $alert->detectedAt->translatedFormat('d M')]) }}</span>
                <span class="mx-1">·</span>
                <span style="font-variant-numeric: tabular-nums;">{{ Lang::get('anomaly::alerts.sensitivity', ['percent' => $alert->sensitivityPercentUsed]) }}</span>
            </p>
        </div>

        @if ($tab === DriftPageTab::Open)
            @php $primaryAcknowledge = count($alert->reasons) <= 1; @endphp
            {{-- Desktop: inline action chips. Phone (<640px): collapsed
                 into a per-row <details> disclosure with full-width
                 ≥44px stacked chips. --}}
            <details class="shrink-0 sm:hidden">
                <summary class="cursor-pointer list-none rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ Lang::get('anomaly::alerts.actions_summary') }}</summary>
                <div class="mt-2 flex flex-col gap-2">
                    @include('anomaly::livewire.partials.anomaly-action-chips', [
                        'alert' => $alert,
                        'snoozeTargets' => $snoozeTargets,
                        'primaryAcknowledge' => $primaryAcknowledge,
                        'stacked' => true,
                    ])
                </div>
            </details>
            <div class="hidden shrink-0 items-center gap-2 sm:flex">
                @include('anomaly::livewire.partials.anomaly-action-chips', [
                    'alert' => $alert,
                    'snoozeTargets' => $snoozeTargets,
                    'primaryAcknowledge' => $primaryAcknowledge,
                    'stacked' => false,
                ])
            </div>
        @endif
    </div>
</x-core::card>
