@use('Modules\Core\Public\Support\Lang')
{{--
    Auto-import from the drop folder. Extracted out of the Settings page so it
    could move to Data & Devices whole: it describes where statements enter
    this install, which is the same question as bank connections and backups,
    and not a preference like theme or currency.
--}}
<div class="space-y-2">
    <x-core::section-heading :title="Lang::get('core::settings.auto_import.heading')" />

    {{-- Not x-core::setting-row: the help line carries a <code> path, which
         that component's description prop escapes, and it needs an id of its
         own for aria-describedby to point at. --}}
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <span class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.auto_import.label') }}</span>
            <p id="auto-import-help" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                @if ($enabled)
                    {!! Lang::get('core::settings.auto_import.active_html', ['userId' => $userId]) !!}
                @else
                    {!! Lang::get('core::settings.auto_import.inactive_html', ['userId' => $userId]) !!}
                @endif
            </p>
        </div>
        <x-core::switch
            id="auto-import-toggle"
            :on="$enabled"
            :label="Lang::get('core::settings.auto_import.label')"
            wire:click="toggle"
            aria-describedby="auto-import-help"
        />
    </div>
</div>
