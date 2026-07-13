<div class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-md mx-auto px-6 space-y-6 text-center">
        <div class="flex justify-center">
            {{-- Brand mark — same surface the desktop welcome screen uses. --}}
            <img
                src="{{ asset('icon.png') }}"
                alt="beatrax"
                class="h-20 w-20"
            />
        </div>

        <header class="space-y-2">
            <h1 class="text-3xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">Welcome to beatrax</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Set up this device to see your finances in one place.
            </p>
        </header>

        <div class="space-y-3">
            <a
                href="{{ route('signup') }}"
                class="inline-block w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
            >
                Create account
            </a>

            <a
                href="{{ route('mobile.pair') }}"
                class="inline-block w-full border border-slate-200 text-slate-700 hover:bg-slate-50 font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-900"
            >
                Import from another device
            </a>
        </div>
    </div>
</div>
