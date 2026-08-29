@use('Modules\Core\Public\Support\Lang')
<div class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-sm px-6 space-y-6">
        <x-core::page-header
            :title="Lang::get('auth::change_password.title')"
            :subtitle="Lang::get('auth::change_password.subtitle')"
        />

        <form wire:submit="submit" class="space-y-4">
            <x-core::form-field
                field-id="current-password"
                name="currentPassword"
                type="password"
                :label="Lang::get('auth::change_password.current_password')"
                wire:model="currentPassword"
                autocomplete="current-password"
                autofocus
            />

            <x-core::form-field
                field-id="new-password"
                name="newPassword"
                type="password"
                :label="Lang::get('auth::change_password.new_password')"
                wire:model="newPassword"
                autocomplete="new-password"
            />

            <x-core::form-field
                field-id="new-password-confirmation"
                name="newPasswordConfirmation"
                type="password"
                :label="Lang::get('auth::change_password.confirm_new_password')"
                wire:model="newPasswordConfirmation"
                autocomplete="new-password"
            />

            @if ($flashMessage !== '')
                <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
            @endif

            <x-core::primary-button>
                {{ Lang::get('auth::change_password.submit') }}
            </x-core::primary-button>
        </form>
    </div>
</div>
