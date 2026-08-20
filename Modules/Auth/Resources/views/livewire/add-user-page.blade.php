@use('Modules\Core\Public\Support\Lang')
<div class="mx-auto max-w-md px-4 py-12 space-y-12 sm:px-8">
    <x-core::page-header
        :title="Lang::get('auth::add_user.title')"
        :subtitle="Lang::get('auth::add_user.subtitle')"
    />

    <form wire:submit="submit" class="space-y-4">
        <div class="space-y-1">
            <label for="username" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::add_user.username') }}</label>
            <input
                type="text"
                id="username"
                wire:model="username"
                autocomplete="off"
                autofocus
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
            />
        </div>

        <div class="space-y-1">
            <label for="initial-password" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::add_user.initial_password') }}</label>
            <input
                type="password"
                id="initial-password"
                wire:model="initialPassword"
                autocomplete="new-password"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
            />
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('auth::add_user.initial_password_hint') }}</p>
        </div>

        <div class="space-y-1">
            <label for="initial-password-confirmation" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::add_user.confirm_initial_password') }}</label>
            <input
                type="password"
                id="initial-password-confirmation"
                wire:model="initialPasswordConfirmation"
                autocomplete="new-password"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
            />
        </div>

        @if ($flashMessage !== '')
            <p class="text-sm text-slate-700 dark:text-slate-300">{{ $flashMessage }}</p>
        @endif

        <x-core::primary-button>
            {{ Lang::get('auth::add_user.submit') }}
        </x-core::primary-button>
    </form>
</div>
