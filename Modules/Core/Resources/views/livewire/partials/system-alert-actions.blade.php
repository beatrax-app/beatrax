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
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Enums\UpdateAlertKind')
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
    @case (UpdateAlertKind::Available->value)
        <div class="flex flex-wrap items-center justify-end gap-2">
            <x-core::neutral-button
                size="sm"
                wire:click="install({{ $alert->id }})"
                wire:loading.attr="disabled"
                wire:target="install({{ $alert->id }})"
                aria-label="{{ Lang::get('core::alerts.actions.install_next_launch_aria', ['id' => $alert->id]) }}"
                data-testid="resolve-alert-{{ $alert->id }}"
            >{{ Lang::get('core::alerts.actions.install_next_launch') }}</x-core::neutral-button>
            <button
                type="button"
                wire:click="skipVersion({{ $alert->id }})"
                wire:loading.attr="disabled"
                wire:target="skipVersion({{ $alert->id }})"
                class="rounded bg-slate-100 text-slate-700 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900 px-3 py-1.5 text-sm font-medium dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-300"
            >{{ Lang::get('core::alerts.actions.skip_version') }}</button>
            @if ($releaseTag !== null)
                <a
                    href="https://github.com/beatrax-app/beatrax/releases/tag/{{ $releaseTag }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rounded text-slate-700 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900 px-3 py-1.5 text-sm font-medium underline underline-offset-2 dark:text-slate-300 dark:hover:text-slate-100"
                >{{ Lang::get('core::alerts.actions.release_notes') }}</a>
            @endif
        </div>
        @break
    @case (UpdateAlertKind::Stale->value)
        <div class="flex flex-wrap items-center justify-end gap-2">
            <button
                type="button"
                wire:click="acknowledge({{ $alert->id }})"
                wire:loading.attr="disabled"
                wire:target="acknowledge({{ $alert->id }})"
                aria-label="{{ Lang::get('core::alerts.actions.update_now_aria', ['id' => $alert->id]) }}"
                data-testid="resolve-alert-{{ $alert->id }}"
                class="rounded bg-amber-600 text-white hover:bg-amber-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-amber-600 px-3 py-1.5 text-sm font-medium dark:bg-amber-500 dark:hover:bg-amber-400"
            >{{ Lang::get('core::alerts.actions.update_now') }}</button>
            <button
                type="button"
                wire:click="acknowledge({{ $alert->id }})"
                wire:loading.attr="disabled"
                wire:target="acknowledge({{ $alert->id }})"
                class="rounded bg-amber-100 text-amber-900 hover:bg-amber-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-amber-600 px-3 py-1.5 text-sm font-medium dark:bg-amber-900 dark:text-amber-200 dark:hover:bg-amber-800"
            >{{ Lang::get('core::alerts.actions.remind_later') }}</button>
        </div>
        @break
    @case (UpdateAlertKind::Critical->value)
        <div class="flex items-center justify-end">
            <button
                type="button"
                wire:click="install({{ $alert->id }})"
                wire:loading.attr="disabled"
                wire:target="install({{ $alert->id }})"
                aria-label="{{ Lang::get('core::alerts.actions.install_next_launch_aria', ['id' => $alert->id]) }}"
                data-testid="resolve-alert-{{ $alert->id }}"
                class="rounded bg-rose-600 text-white hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-rose-600 px-3 py-1.5 text-sm font-medium dark:hover:bg-rose-400 dark:bg-rose-500"
            >{{ Lang::get('core::alerts.actions.install_next_launch') }}</button>
        </div>
        @break
    @default
        <button
            type="button"
            wire:click="acknowledge({{ $alert->id }})"
            wire:loading.attr="disabled"
            wire:target="acknowledge({{ $alert->id }})"
            aria-label="{{ Lang::get('core::alerts.actions.mark_resolved_aria', ['id' => $alert->id]) }}"
            data-testid="resolve-alert-{{ $alert->id }}"
            @class([
                'rounded px-3 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
                'bg-rose-600 text-white hover:bg-rose-700 focus-visible:ring-rose-600 dark:hover:bg-rose-400 dark:bg-rose-500' => $alert->severity === \Modules\Core\Public\Enums\SystemAlertSeverity::Critical->value,
                'bg-slate-100 text-slate-700 hover:bg-slate-200 focus-visible:ring-slate-900 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-300' => $alert->severity !== \Modules\Core\Public\Enums\SystemAlertSeverity::Critical->value,
            ])
        >{{ Lang::get('core::alerts.actions.mark_resolved') }}</button>
@endswitch
