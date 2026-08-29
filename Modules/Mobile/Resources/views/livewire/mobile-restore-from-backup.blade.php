@use('Modules\Core\Public\Support\Lang')
{{-- .safe-screen: this renders signed out on a fresh install, where
     layouts.app draws no bar and nothing else reserves the system bars. --}}
<div class="safe-screen min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-md mx-auto px-6 space-y-6">
        <header class="space-y-2 text-center">
            <x-core::page-heading>{{ Lang::get('mobile::restore.heading') }}</x-core::page-heading>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('mobile::restore.intro') }}
            </p>
        </header>

        @if ($error !== '')
            <x-core::alert tone="negative">
                <p>{{ $error }}</p>
            </x-core::alert>
        @endif

        <form wire:submit="restore" class="space-y-4">
            <div class="space-y-1">
                <label for="restore-file" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('core::backup.restore.file_label') }}</label>
                {{-- Livewire fires this on the input when its own upload
                     request comes back non-2xx, which is how a body refused
                     by post_max_size arrives. --}}
                <x-core::file-input
                    id="restore-file"
                    wire:model="backup"
                    accept=".enc"
                    x-on:livewire-upload-error="$wire.uploadFailed()"
                />
                <div wire:loading wire:target="backup" class="text-xs text-slate-600 dark:text-slate-400">{{ Lang::get('core::backup.restore.uploading') }}</div>
            </div>

            <x-core::form-field
                field-id="restore-passphrase"
                name="passphrase"
                type="password"
                :label="Lang::get('core::backup.restore.passphrase')"
                wire:model="passphrase"
                autocomplete="off"
            />

            <x-core::primary-button type="submit" block="full">
                <span wire:loading.remove wire:target="restore">{{ Lang::get('mobile::restore.submit') }}</span>
                <span wire:loading wire:target="restore">{{ Lang::get('core::backup.restore.restoring') }}</span>
            </x-core::primary-button>
        </form>

        <p class="text-xs text-slate-500 dark:text-slate-400">
            {{ Lang::get('mobile::restore.sign_in_note') }}
        </p>

        <x-core::secondary-button :href="route('mobile.welcome')" block="full">
            {{ Lang::get('mobile::restore.back') }}
        </x-core::secondary-button>
    </div>
</div>
