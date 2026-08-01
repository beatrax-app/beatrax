@use('Modules\Core\Public\Support\Lang')
<div>
    @if ($sqliteOnly)
        <div class="mt-6 border-t border-slate-100 pt-6 dark:border-slate-800">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('core::backup.restore.heading') }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {!! Lang::get('core::backup.restore.intro_html') !!}
            </p>

            @if ($snapshotPath !== '')
                <div class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">
                    <p>{{ Lang::get('core::backup.restore.restored') }}</p>
                    <p class="mt-1 text-xs opacity-80">{{ Lang::get('core::backup.restore.snapshot_saved_prefix') }} <code class="font-mono">{{ $snapshotPath }}</code>.</p>
                </div>
            @else
                <form wire:submit="restore" class="mt-3 space-y-3">
                    <div class="space-y-1">
                        <label for="restore-file" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::backup.restore.file_label') }}</label>
                        <input
                            type="file"
                            id="restore-file"
                            wire:model="backup"
                            accept=".enc"
                            class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border file:border-slate-200 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-slate-50 dark:text-slate-300 dark:file:border-slate-700 dark:file:bg-slate-900"
                        />
                        <div wire:loading wire:target="backup" class="text-xs text-slate-400">{{ Lang::get('core::backup.restore.uploading') }}</div>
                    </div>
                    <div class="space-y-1">
                        <label for="restore-passphrase" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::backup.restore.passphrase') }}</label>
                        <input type="password" id="restore-passphrase" wire:model="passphrase" autocomplete="off"
                            class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100" />
                    </div>
                    <div class="space-y-1">
                        <label for="restore-confirm" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::backup.restore.confirm_prefix') }} <code class="font-mono font-semibold">{{ $confirmPhrase }}</code> {{ Lang::get('core::backup.restore.confirm_suffix') }}</label>
                        <input type="text" id="restore-confirm" wire:model="confirmation" autocomplete="off" placeholder="{{ $confirmPhrase }}"
                            class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" />
                    </div>

                    @if ($error !== '')
                        <p class="text-sm text-rose-600 dark:text-rose-500">{{ $error }}</p>
                    @endif

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="restore,backup"
                        class="inline-flex items-center rounded-md border border-rose-300 bg-white px-3 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-50 disabled:opacity-50 dark:border-rose-800 dark:bg-slate-950 dark:text-rose-400 dark:hover:bg-rose-950"
                    >
                        <span wire:loading.remove wire:target="restore">{{ Lang::get('core::backup.restore.submit') }}</span>
                        <span wire:loading wire:target="restore">{{ Lang::get('core::backup.restore.restoring') }}</span>
                    </button>
                </form>
            @endif
        </div>
    @endif
</div>
