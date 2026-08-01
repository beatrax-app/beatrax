@use('Modules\Core\Public\Support\Lang')
<div class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-md mx-auto px-6 space-y-6 text-center">
        <div class="flex justify-center">
            {{-- Brand mark — same surface the welcome / login screens use (D-18). --}}
            <img
                src="{{ asset('icon.png') }}"
                alt="beatrax"
                class="h-20 w-20"
            />
        </div>

        <header class="space-y-2">
            <h1 class="text-3xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('desktop::screens.welcome.heading') }}</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('desktop::screens.welcome.subtitle') }}
            </p>
        </header>

        <a
            href="{{ route('signup') }}"
            class="inline-block w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
        >
            {{ Lang::get('desktop::screens.welcome.get_started') }}
        </a>
    </div>
</div>
