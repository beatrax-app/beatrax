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
        <form wire:submit="export" class="mt-3 space-y-3" data-testid="export-everything-form">
            <x-core::form-field
                field-id="export-everything-passphrase"
                name="passphrase"
                type="password"
                :label="Lang::get('core::help.export_passphrase_label')"
                wire:model="passphrase"
                autocomplete="new-password"
            />
            <x-core::form-field
                field-id="export-everything-passphrase-confirm"
                name="confirmPassphrase"
                type="password"
                :label="Lang::get('core::help.export_confirm_label')"
                wire:model="confirmPassphrase"
                autocomplete="new-password"
            />

            <p class="text-xs text-amber-700 dark:text-amber-400">
                {{ Lang::get('core::help.export_passphrase_hint') }}
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
                data-testid="export-everything-cta"
                wire:loading.attr="disabled"
                wire:target="export"
            >
                <span wire:loading.remove wire:target="export">{{ Lang::get('core::help.export_cta') }}</span>
                <span wire:loading wire:target="export">{{ Lang::get('core::help.export_working') }}</span>
            </x-core::secondary-button>
        </form>
    @endif
</div>
