@use('Modules\Core\Public\Support\Lang')
<div class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-sm px-6 space-y-6">
        <x-core::page-header
            :title="Lang::get('auth::reset_password.title')"
            :subtitle="Lang::get('auth::reset_password.subtitle')"
        />

        <form wire:submit="submit" class="space-y-4">
            <div class="space-y-1">
                <label for="username" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::reset_password.username') }}</label>
                <input
                    type="text"
                    id="username"
                    wire:model="username"
                    autocomplete="username"
                    autofocus
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                />
            </div>

            <div class="space-y-1">
                <label for="recovery-code" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::reset_password.recovery_code') }}</label>
                <input
                    type="text"
                    id="recovery-code"
                    wire:model="recoveryCode"
                    autocomplete="off"
                    placeholder="A2BJ-XK9M-PQ7N-RX4F-V8HD"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                />
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('auth::reset_password.recovery_code_hint') }}</p>
            </div>

            <div class="space-y-1">
                <label for="new-password" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::reset_password.new_password') }}</label>
                <input
                    type="password"
                    id="new-password"
                    wire:model="newPassword"
                    autocomplete="new-password"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                />
            </div>

            <div class="space-y-1">
                <label for="new-password-confirmation" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::reset_password.confirm_new_password') }}</label>
                <input
                    type="password"
                    id="new-password-confirmation"
                    wire:model="newPasswordConfirmation"
                    autocomplete="new-password"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                />
            </div>

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
