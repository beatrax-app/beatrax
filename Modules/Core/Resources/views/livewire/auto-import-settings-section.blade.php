@use('Modules\Core\Public\Support\Lang')
{{--
    Auto-import from the drop folder. Extracted out of the Settings page so it
    could move to Data & Devices whole: it describes where statements enter
    this install, which is the same question as bank connections and backups,
    and not a preference like theme or currency.
--}}
<div class="space-y-2">
    <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">
        {{ Lang::get('core::settings.auto_import.heading') }}
    </h2>

    <label for="auto-import-toggle" class="flex cursor-pointer items-start gap-3">
        <input
            type="checkbox"
            id="auto-import-toggle"
            @checked($enabled)
            wire:change="toggle"
            aria-describedby="auto-import-help"
            class="mt-1 h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600 dark:border-slate-600 dark:text-emerald-500 dark:focus:ring-emerald-500"
        />
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
    </label>
</div>
