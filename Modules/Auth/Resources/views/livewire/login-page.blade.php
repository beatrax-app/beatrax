@use('Modules\Core\Public\Support\Lang')
<div class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-sm px-6 space-y-6">
        <header class="space-y-1">
            <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('auth::login.title') }}</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('auth::login.subtitle') }}</p>
        </header>

        <form wire:submit="submit" class="space-y-4">
            <div class="space-y-1">
                <label for="username" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::login.username') }}</label>
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
                <label for="password" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::login.password') }}</label>
                <input
                    type="password"
                    id="password"
                    wire:model="password"
                    autocomplete="current-password"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                />
            </div>

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

            <button
                type="submit"
                class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
            >
                {{ Lang::get('auth::login.submit') }}
            </button>
        </form>

        <p class="text-sm">
            <a
                href="/reset-password"
                class="text-slate-500 underline underline-offset-2 hover:text-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
            >
                {{ Lang::get('auth::login.lost_password') }}
            </a>
        </p>
    </div>
</div>
