{{--
    Anomaly action chips (Open tab). Four chips in UI-SPEC order:
    Acknowledge | Snooze ▾ | Mark as expected | Dismiss.

    Each chip wires to a method on the DriftPage component (the page is
    owned by DriftAlerts; these methods inject the Anomaly Public Actions
    as method params).

    Variables in scope:
      - $alert : AnomalyAlertDto
      - $snoozeTargets : array<'1w'|'1m'|'3m', string ISO8601>
      - $primaryAcknowledge : bool — emerald primary chip if true
      - $stacked : bool — phone disclosure (full-width ≥44px) vs inline
--}}

@php
    $base = 'inline-flex items-center justify-center gap-1 rounded-md text-xs font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2';
    $size = $stacked ? 'w-full min-h-[44px] px-3 py-2 text-sm' : 'px-2.5 py-1';
@endphp

<button
    type="button"
    wire:click="acknowledgeAnomaly({{ $alert->anomalyAlertId }})"
    aria-label="Acknowledge anomaly alert for {{ $alert->displayName !== '' ? $alert->displayName : 'unknown merchant' }}"
    @class([
        $base,
        $size,
        'bg-emerald-600 text-white hover:bg-emerald-700 focus-visible:ring-emerald-600 dark:bg-emerald-500 dark:hover:bg-emerald-400' => $primaryAcknowledge,
        'bg-slate-100 text-slate-700 hover:bg-slate-200 focus-visible:ring-slate-900 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700' => ! $primaryAcknowledge,
    ])
>Acknowledge</button>

<div x-data="{ open: false }" class="relative {{ $stacked ? 'w-full' : '' }}">
    <button
        type="button"
        x-on:click="open = ! open"
        x-on:keydown.escape="open = false"
        aria-label="Snooze options"
        class="{{ $base }} {{ $size }} bg-slate-100 text-slate-700 hover:bg-slate-200 focus-visible:ring-slate-900 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
    >Snooze <span aria-hidden="true">▾</span></button>
    <div
        x-show="open"
        x-cloak
        x-on:click.outside="open = false"
        role="menu"
        aria-label="Snooze options"
        class="absolute right-0 z-10 mt-1 w-48 rounded-md border border-slate-200 bg-white p-2 text-xs shadow-lg dark:bg-slate-950 dark:border-slate-700"
    >
        <button type="button" role="menuitem" wire:click="snoozeAnomaly({{ $alert->anomalyAlertId }}, '{{ $snoozeTargets['1w'] }}')" x-on:click="open = false" class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900">1 week</button>
        <button type="button" role="menuitem" wire:click="snoozeAnomaly({{ $alert->anomalyAlertId }}, '{{ $snoozeTargets['1m'] }}')" x-on:click="open = false" class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900">1 month</button>
        <button type="button" role="menuitem" wire:click="snoozeAnomaly({{ $alert->anomalyAlertId }}, '{{ $snoozeTargets['3m'] }}')" x-on:click="open = false" class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900">3 months</button>
    </div>
</div>

<button
    type="button"
    wire:click="markAnomalyExpected({{ $alert->anomalyAlertId }})"
    aria-label="Mark anomaly alert for {{ $alert->displayName !== '' ? $alert->displayName : 'unknown merchant' }} as expected"
    class="{{ $base }} {{ $size }} bg-slate-100 text-slate-700 hover:bg-slate-200 focus-visible:ring-slate-900 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
>Mark as expected</button>

<button
    type="button"
    wire:click="dismissAnomaly({{ $alert->anomalyAlertId }})"
    aria-label="Dismiss anomaly alert for {{ $alert->displayName !== '' ? $alert->displayName : 'unknown merchant' }}"
    class="{{ $base }} {{ $size }} text-slate-700 hover:bg-slate-100 focus-visible:ring-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
>Dismiss</button>
