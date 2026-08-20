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
            <x-core::form-field
                field-id="backup-passphrase"
                name="passphrase"
                type="password"
                :label="Lang::get('core::backup.download.passphrase')"
                wire:model="passphrase"
                autocomplete="new-password"
            />
            <x-core::form-field
                field-id="backup-passphrase-confirm"
                name="confirmPassphrase"
                type="password"
                :label="Lang::get('core::backup.download.confirm_passphrase')"
                wire:model="confirmPassphrase"
                autocomplete="new-password"
            />

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
