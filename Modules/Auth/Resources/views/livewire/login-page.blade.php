@use('Modules\Core\Public\Support\Lang')
<div class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-sm px-6 space-y-6">
        <x-core::page-header
            :title="Lang::get('auth::login.title')"
            :subtitle="Lang::get('auth::login.subtitle')"
        />

        <form wire:submit="submit" class="space-y-4">
            <x-core::form-field
                name="username"
                :label="Lang::get('auth::login.username')"
                wire:model="username"
                autocomplete="username"
                autofocus
            />

            <x-core::form-field
                name="password"
                type="password"
                :label="Lang::get('auth::login.password')"
                wire:model="password"
                autocomplete="current-password"
            />

            <label for="remember-me" class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                <input
                    type="checkbox"
                    id="remember-me"
                    wire:model="rememberMe"
                    class="rounded border-slate-300 dark:border-slate-600"
                />
                {{ Lang::get('auth::login.remember') }}
            </label>

            @if ($flashMessage !== '')
                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $flashMessage }}</p>
            @endif

            <x-core::primary-button>
                {{ Lang::get('auth::login.submit') }}
            </x-core::primary-button>
        </form>

        <p class="text-sm">
            <a
                href="/reset-password"
                class="text-slate-500 underline underline-offset-2 hover:text-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
            >
                {{ Lang::get('auth::login.lost_password') }}
            </a>
        </p>

        <x-core::locale-switcher />
    </div>
</div>
