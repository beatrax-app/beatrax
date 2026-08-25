@use('Modules\Core\Public\Support\Lang')
<div>
    @if ($sqliteOnly)
        <div class="mt-6 border-t border-slate-100 pt-6 dark:border-slate-800">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('core::backup.restore.heading') }}</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {!! Lang::get('core::backup.restore.intro_html') !!}
            </p>

            @if ($snapshotPath !== '')
                <x-core::alert tone="positive" class="mt-3">
                    <p>{{ Lang::get('core::backup.restore.restored') }}</p>
                    <p class="mt-1 text-xs opacity-80">{{ Lang::get('core::backup.restore.snapshot_saved_prefix') }} <code class="font-mono">{{ $snapshotPath }}</code>.</p>
                </x-core::alert>
            @else
                <form wire:submit="restore" class="mt-3 space-y-3">
                    <div class="space-y-1">
                        <label for="restore-file" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::backup.restore.file_label') }}</label>
                        {{-- `file:` utilities style the native button but cannot
                             relabel it: the words inside stay engine-supplied
                             English in every language. --}}
                        {{-- Livewire fires this on the input when its own
                             upload request comes back non-2xx, which is how a
                             body refused by post_max_size arrives. --}}
                        <x-core::file-input
                            id="restore-file"
                            wire:model="backup"
                            accept=".enc"
                            x-on:livewire-upload-error="$wire.uploadFailed()"
                        />
                        <div wire:loading wire:target="backup" class="text-xs text-slate-400">{{ Lang::get('core::backup.restore.uploading') }}</div>
                    </div>
                    <x-core::form-field
                        field-id="restore-passphrase"
                        name="passphrase"
                        type="password"
                        :label="Lang::get('core::backup.restore.passphrase')"
                        wire:model="passphrase"
                        autocomplete="off"
                    />
                    {{-- The label is markup, not a string: the phrase to be
                         typed is set in code inside it. So it goes as a slot. --}}
                    <x-core::form-field
                        field-id="restore-confirm"
                        name="confirmation"
                        wire:model="confirmation"
                        autocomplete="off"
                        :placeholder="$confirmPhrase"
                    >
                        <x-slot:label>
                            {{ Lang::get('core::backup.restore.confirm_prefix') }} <code class="font-mono font-semibold">{{ $confirmPhrase }}</code> {{ Lang::get('core::backup.restore.confirm_suffix') }}
                        </x-slot:label>
                    </x-core::form-field>

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
