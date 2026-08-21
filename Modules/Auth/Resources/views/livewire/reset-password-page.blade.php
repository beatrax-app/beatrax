@use('Modules\Core\Public\Support\Lang')
<div class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-sm px-6 space-y-6">
        <x-core::page-header
            :title="Lang::get('auth::reset_password.title')"
            :subtitle="Lang::get('auth::reset_password.subtitle')"
        />

        <form wire:submit="submit" class="space-y-4">
            <x-core::form-field
                name="username"
                :label="Lang::get('auth::reset_password.username')"
                wire:model="username"
                autocomplete="username"
                autofocus
            />

            <x-core::form-field
                field-id="recovery-code"
                name="recoveryCode"
                :label="Lang::get('auth::reset_password.recovery_code')"
                :hint="Lang::get('auth::reset_password.recovery_code_hint')"
                wire:model="recoveryCode"
                autocomplete="off"
                placeholder="A2BJ-XK9M-PQ7N-RX4F-V8HD"
                class="font-mono"
            />

            <x-core::form-field
                field-id="new-password"
                name="newPassword"
                type="password"
                :label="Lang::get('auth::reset_password.new_password')"
                wire:model="newPassword"
                autocomplete="new-password"
            />

            <x-core::form-field
                field-id="new-password-confirmation"
                name="newPasswordConfirmation"
                type="password"
                :label="Lang::get('auth::reset_password.confirm_new_password')"
                wire:model="newPasswordConfirmation"
                autocomplete="new-password"
            />

            @if ($flashMessage !== '')
                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $flashMessage }}</p>
            @endif

            <x-core::primary-button>
                {{ Lang::get('auth::reset_password.submit') }}
            </x-core::primary-button>
        </form>

        <p class="text-sm">
            <a
                href="/login"
                class="text-slate-500 underline underline-offset-2 hover:text-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
            >
                {{ Lang::get('auth::reset_password.back') }}
            </a>
        </p>
    </div>
</div>
