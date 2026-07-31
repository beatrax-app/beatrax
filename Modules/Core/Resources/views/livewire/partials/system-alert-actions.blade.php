{{--
    Renders the per-row action buttons for a single system_alerts row.

    Variables in scope:
      - $alert : Modules\Core\Models\SystemAlert

    Layout-side intent:
      - Default branch (calm/operational alerts) keeps the historical
        `Mark as resolved` button that acknowledges the row.
      - update.available shows three buttons — Install on next launch
        (acknowledge), Skip this version (skipVersion), Release notes
        (OpenExternalUrlAction-driven https://github.com/... URL).
      - update.stale shows Update now (acknowledge) + Remind me later
        (acknowledge — banner-resurfaces is acceptable v1.0 posture;
        the 7-day-snooze refinement is captured in the plan as a
        follow-up).
      - update.critical shows Install on next launch ONLY — no skip
        option per the UI-SPEC critical contract.

    Tailwind classes are direct literal strings throughout — no
    interpolation — so Tailwind's content scanner picks them up.
--}}
@php
    $metadata = is_array($alert->metadata) ? $alert->metadata : [];
    $latestVersion = isset($metadata['latestVersion']) && is_string($metadata['latestVersion'])
        ? $metadata['latestVersion']
        : null;
    $newVersion = isset($metadata['newVersion']) && is_string($metadata['newVersion'])
        ? $metadata['newVersion']
        : $latestVersion;
    $releaseTagSource = $newVersion ?? $latestVersion;
    $releaseTag = $releaseTagSource !== null
        ? 'v'.ltrim($releaseTagSource, 'v')
        : null;
@endphp
@switch ($alert->kind)
    @case ('update.available')
        <div class="flex flex-wrap items-center justify-end gap-2">
            <button
                type="button"
                wire:click="acknowledge({{ $alert->id }})"
                wire:loading.attr="disabled"
                wire:target="acknowledge({{ $alert->id }})"
                aria-label="Install on next launch — marks system alert #{{ $alert->id }} as resolved"
                data-testid="resolve-alert-{{ $alert->id }}"
                class="rounded bg-slate-900 text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900 px-3 py-1.5 text-sm font-medium dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300"
            >Install on next launch</button>
            <button
                type="button"
                wire:click="skipVersion({{ $alert->id }})"
                wire:loading.attr="disabled"
                wire:target="skipVersion({{ $alert->id }})"
                class="rounded bg-slate-100 text-slate-700 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900 px-3 py-1.5 text-sm font-medium dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-300"
            >Skip this version</button>
            @if ($releaseTag !== null)
                <a
                    href="https://github.com/beatrax-app/beatrax/releases/tag/{{ $releaseTag }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rounded text-slate-700 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900 px-3 py-1.5 text-sm font-medium underline underline-offset-2 dark:text-slate-300 dark:hover:text-slate-100"
                >Release notes →</a>
            @endif
        </div>
        @break
    @case ('update.stale')
        <div class="flex flex-wrap items-center justify-end gap-2">
            <button
                type="button"
                wire:click="acknowledge({{ $alert->id }})"
                wire:loading.attr="disabled"
                wire:target="acknowledge({{ $alert->id }})"
                aria-label="Update now — marks system alert #{{ $alert->id }} as resolved"
                data-testid="resolve-alert-{{ $alert->id }}"
                class="rounded bg-amber-600 text-white hover:bg-amber-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-amber-600 px-3 py-1.5 text-sm font-medium dark:bg-amber-500 dark:hover:bg-amber-400"
            >Update now</button>
            <button
                type="button"
                wire:click="acknowledge({{ $alert->id }})"
                wire:loading.attr="disabled"
                wire:target="acknowledge({{ $alert->id }})"
                class="rounded bg-amber-100 text-amber-900 hover:bg-amber-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-amber-600 px-3 py-1.5 text-sm font-medium dark:bg-amber-900 dark:text-amber-200 dark:hover:bg-amber-800"
            >Remind me later</button>
        </div>
        @break
    @case ('update.critical')
        <div class="flex items-center justify-end">
            <button
                type="button"
                wire:click="acknowledge({{ $alert->id }})"
                wire:loading.attr="disabled"
                wire:target="acknowledge({{ $alert->id }})"
                aria-label="Install on next launch — marks system alert #{{ $alert->id }} as resolved"
                data-testid="resolve-alert-{{ $alert->id }}"
                class="rounded bg-rose-600 text-white hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-rose-600 px-3 py-1.5 text-sm font-medium dark:hover:bg-rose-400 dark:bg-rose-500"
            >Install on next launch</button>
        </div>
        @break
    @default
        <button
            type="button"
            wire:click="acknowledge({{ $alert->id }})"
            wire:loading.attr="disabled"
            wire:target="acknowledge({{ $alert->id }})"
            aria-label="Mark as resolved — system alert #{{ $alert->id }}"
            data-testid="resolve-alert-{{ $alert->id }}"
            @class([
                'rounded px-3 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
                'bg-rose-600 text-white hover:bg-rose-700 focus-visible:ring-rose-600 dark:hover:bg-rose-400 dark:bg-rose-500' => $alert->severity === \Modules\Core\Public\Enums\SystemAlertSeverity::Critical->value,
                'bg-slate-100 text-slate-700 hover:bg-slate-200 focus-visible:ring-slate-900 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-300' => $alert->severity !== \Modules\Core\Public\Enums\SystemAlertSeverity::Critical->value,
            ])
        >Mark as resolved</button>
@endswitch
