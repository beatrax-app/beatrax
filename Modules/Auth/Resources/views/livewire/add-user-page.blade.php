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

        {{-- Last, and after the two boxes above rather than beside them: those
             two describe the account being made, this one says who is making
             it. An owner session carries the authority on its own, which is
             what this asks the reader to supply in person. --}}
        <x-core::form-field
            field-id="add-user-owner-password"
            name="ownerPassword"
            type="password"
            :label="Lang::get('auth::add_user.owner_password_label')"
            wire:model="ownerPassword"
            autocomplete="current-password"
            data-testid="add-user-owner-password"
        />

        @if ($flashMessage !== '')
            <p class="text-sm text-slate-700 dark:text-slate-300">{{ $flashMessage }}</p>
        @endif

        <x-core::primary-button>
            {{ Lang::get('auth::add_user.submit') }}
        </x-core::primary-button>
    </form>
</div>
