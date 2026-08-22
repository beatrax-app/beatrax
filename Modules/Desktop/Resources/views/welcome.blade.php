@use('Modules\Core\Public\Support\Lang')
{{-- .safe-screen: layouts.app draws no bar for a signed-out reader, so this
     screen is the only thing between its own content and the system bars. --}}
<div class="safe-screen min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-md mx-auto px-6 space-y-6 text-center">
        <div class="flex justify-center">
            {{-- Brand mark — same surface the welcome / login screens use. --}}
            <img
                src="{{ asset('icon.png') }}"
                alt="Beatrax"
                class="h-20 w-20"
            />
        </div>

        <header class="space-y-2">
            <h1 class="text-3xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('desktop::screens.welcome.heading') }}</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('desktop::screens.welcome.subtitle') }}
            </p>
        </header>

        <x-core::primary-button href="{{ route('signup') }}">
            {{ Lang::get('desktop::screens.welcome.get_started') }}
        </x-core::primary-button>
    </div>
</div>
