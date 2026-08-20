@use('Modules\Core\Public\Support\Lang')
<div class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-sm px-6 space-y-6">
        <x-core::page-header
            :title="Lang::get('auth::change_password.title')"
            :subtitle="Lang::get('auth::change_password.subtitle')"
        />

        <form wire:submit="submit" class="space-y-4">
            <div class="space-y-1">
                <label for="current-password" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::change_password.current_password') }}</label>
                <input
                    type="password"
                    id="current-password"
                    wire:model="currentPassword"
                    autocomplete="current-password"
                    autofocus
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                />
            </div>

            <div class="space-y-1">
                <label for="new-password" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::change_password.new_password') }}</label>
                <input
                    type="password"
                    id="new-password"
                    wire:model="newPassword"
                    autocomplete="new-password"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                />
            </div>

            <div class="space-y-1">
                <label for="new-password-confirmation" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::change_password.confirm_new_password') }}</label>
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
                {{ Lang::get('auth::change_password.submit') }}
            </x-core::primary-button>
        </form>
    </div>
</div>
