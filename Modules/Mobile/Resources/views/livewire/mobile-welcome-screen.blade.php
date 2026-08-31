@use('Modules\Core\Public\Support\Lang')
{{-- .safe-screen: the first screen of a fresh install renders signed out, and
     layouts.app draws no bar there — nothing else reserves the system bars. --}}
<div class="safe-screen min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-md mx-auto px-4 sm:px-6 space-y-6 text-center">
        <div class="flex justify-center">
            {{-- Brand mark — same surface the desktop welcome screen uses. --}}
            <x-core::app-mark :size="false" class="h-20 w-20" :decorative="false" />
        </div>

        <header class="space-y-2">
            <x-core::page-heading level="section">{{ Lang::get('mobile::welcome.heading') }}</x-core::page-heading>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('mobile::welcome.subtitle') }}
            </p>
        </header>

        {{-- Import leads. A phone is the second device in almost every case:
             the statements, the rules and the first month's tidying all happen
             on a bigger screen, and this one is where the result is read. --}}
        <div class="space-y-3">
            <x-core::primary-button href="{{ route('mobile.import') }}">
                {{ Lang::get('mobile::welcome.import') }}
            </x-core::primary-button>

            <x-core::secondary-button
                :href="route('signup')"
                block="full"
            >
                {{ Lang::get('mobile::welcome.create_account') }}
            </x-core::secondary-button>

            <p class="text-xs text-slate-500 dark:text-slate-400">
                {{ Lang::get('mobile::welcome.create_account_note') }}
            </p>

            {{-- Last, and quieter: it is the rarest of the three, but it was
                 missing altogether, so a reader holding a backup had no way
                 back that did not start by creating an account they did not
                 want. --}}
            <x-core::secondary-button :href="route('mobile.restore')" block="full">
                {{ Lang::get('mobile::welcome.restore') }}
            </x-core::secondary-button>
        </div>

        <x-core::locale-switcher />
    </div>
</div>
