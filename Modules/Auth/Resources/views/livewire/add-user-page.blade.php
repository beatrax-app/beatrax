@use('Modules\Core\Public\Support\Lang')
<div class="mx-auto max-w-md px-4 py-6 space-y-12 sm:px-8">
    <x-core::page-header
        :title="Lang::get('auth::add_user.title')"
        :subtitle="Lang::get('auth::add_user.subtitle')"
    />

    <form wire:submit="submit" class="space-y-4">
        <x-core::form-field
            name="username"
            :label="Lang::get('auth::add_user.username')"
            wire:model="username"
            autocomplete="off"
            autofocus
        />

        <x-core::form-field
            field-id="initial-password"
            name="initialPassword"
            type="password"
            :label="Lang::get('auth::add_user.initial_password')"
            :hint="Lang::get('auth::add_user.initial_password_hint')"
            wire:model="initialPassword"
            autocomplete="new-password"
        />

        <x-core::form-field
            field-id="initial-password-confirmation"
            name="initialPasswordConfirmation"
            type="password"
            :label="Lang::get('auth::add_user.confirm_initial_password')"
            wire:model="initialPasswordConfirmation"
            autocomplete="new-password"
        />

        @if ($flashMessage !== '')
            <p class="text-sm text-slate-700 dark:text-slate-300">{{ $flashMessage }}</p>
        @endif

        <x-core::primary-button>
            {{ Lang::get('auth::add_user.submit') }}
        </x-core::primary-button>
    </form>
</div>
