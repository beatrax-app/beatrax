@use('Modules\Core\Public\Support\Lang')
<div>
    @if (! $sqliteOnly)
        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('core::backup.download.unavailable') }}
        </p>
    @else
        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('core::backup.download.intro') }}
        </p>

        <form wire:submit="download" class="mt-3 space-y-3">
            <div class="space-y-1">
                <label for="backup-passphrase" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::backup.download.passphrase') }}</label>
                <input
                    type="password"
                    id="backup-passphrase"
                    wire:model="passphrase"
                    autocomplete="new-password"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                />
            </div>
            <div class="space-y-1">
                <label for="backup-passphrase-confirm" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::backup.download.confirm_passphrase') }}</label>
                <input
                    type="password"
                    id="backup-passphrase-confirm"
                    wire:model="confirmPassphrase"
                    autocomplete="new-password"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                />
            </div>

            <p class="text-xs text-amber-700 dark:text-amber-400">
                {{ Lang::get('core::backup.download.keep_safe') }}
            </p>

            @if ($error !== '')
                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $error }}</p>
            @endif

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="download"
                class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
            >
                <span wire:loading.remove wire:target="download">{{ Lang::get('core::backup.download.submit') }}</span>
                <span wire:loading wire:target="download">{{ Lang::get('core::backup.download.preparing') }}</span>
            </button>
        </form>
    @endif
</div>
