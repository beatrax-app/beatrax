@use('Modules\Core\Public\Support\Lang')
<div class="space-y-2" wire:loading.class="opacity-60">
    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('core::settings.sample_data.heading') }}</h2>
    <p class="max-w-prose text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('core::settings.sample_data.help') }}</p>

    @if ($confirming)
        <p class="max-w-prose text-xs text-amber-700 dark:text-amber-500">{{ Lang::get('core::settings.sample_data.warning') }}</p>
        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                wire:click="load"
                wire:loading.attr="disabled"
                class="inline-flex min-h-11 items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-100 dark:text-slate-900 dark:focus-visible:ring-slate-100"
            >{{ Lang::get('core::settings.sample_data.confirm') }}</button>
            <button
                type="button"
                wire:click="cancel"
                class="inline-flex min-h-11 items-center rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:border-slate-700 dark:text-slate-100 dark:focus-visible:ring-slate-100"
            >{{ Lang::get('core::settings.sample_data.cancel') }}</button>
        </div>
    @else
        <button
            type="button"
            wire:click="ask"
            class="inline-flex min-h-11 items-center rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:border-slate-700 dark:text-slate-100 dark:focus-visible:ring-slate-100"
        >{{ Lang::get('core::settings.sample_data.load') }}</button>
    @endif

    <p class="text-xs text-slate-500 dark:text-slate-400" wire:loading wire:target="load">{{ Lang::get('core::settings.sample_data.working') }}</p>

    @if ($loadedRows !== null)
        <p class="text-xs text-emerald-700 dark:text-emerald-500">{{ Lang::get('core::settings.sample_data.loaded', ['count' => $loadedRows]) }}</p>
    @endif
</div>
