@use('Modules\Core\Public\Support\Lang')
<div>
    @if (! $sqliteOnly)
        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('core::backup.download.unavailable') }}
        </p>
    @elseif (! $canDeliver)
        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('core::backup.download.no_download_route') }}
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

            @if ($notice !== '')
                <p aria-live="polite" class="text-sm text-emerald-700 dark:text-emerald-400">{{ $notice }}</p>
            @endif

            <x-core::secondary-button
                size="sm"
                class="disabled:opacity-50"
                type="submit"
                wire:loading.attr="disabled"
                wire:target="download"
            >
                <span wire:loading.remove wire:target="download">{{ Lang::get('core::backup.download.submit') }}</span>
                <span wire:loading wire:target="download">{{ Lang::get('core::backup.download.preparing') }}</span>
            </x-core::secondary-button>
        </form>
    @endif
</div>
