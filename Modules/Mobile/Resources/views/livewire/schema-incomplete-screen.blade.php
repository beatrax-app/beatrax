{{--
    Full-screen and chromeless like the other lock-layout screens: there is no
    working app behind it to navigate to. The retry is offered because the
    marker is re-derived from the migrator on every attempt, so a failure that
    WAS transient clears itself and the reader never sees this again.
--}}
@use('Modules\Core\Public\Support\Lang')
<div class="safe-screen min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-sm px-6 py-10 space-y-6 text-center">
        <x-core::app-mark class="mx-auto" />

        {{-- A <p>, like the sibling lock-layout screens: layouts.lock draws no
             page chrome, so there is no x-core::page-heading to sit inside. --}}
        <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">
            {{ Lang::get('mobile::schema.heading') }}
        </p>

        <p class="text-sm text-slate-600 dark:text-slate-400">
            {{ Lang::get('mobile::schema.body') }}
        </p>

        <x-core::primary-button wire:click="retry" data-testid="schema-retry">
            {{ Lang::get('mobile::schema.retry') }}
        </x-core::primary-button>

        {{-- Only after an attempt: saying a repair is impossible before one has
             been tried is advice the reader has no reason to believe. --}}
        @if ($retryFailed)
            <p aria-live="polite" class="text-sm text-slate-600 dark:text-slate-400" data-testid="schema-retry-failed">
                {{ Lang::get('mobile::schema.retry_failed') }}
            </p>
        @endif
    </div>
</div>
