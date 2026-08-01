@use('Modules\Core\Public\Support\Lang')
@php
    /** @var bool $isPending */
@endphp
<div class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-md mx-auto px-6 space-y-6 text-center">
        <div class="flex justify-center">
            {{-- Brand mark — matches the welcome / login layout (D-18). --}}
            <img
                src="{{ asset('icon.png') }}"
                alt="beatrax"
                class="h-16 w-16"
            />
        </div>

        @if ($isPending)
            {{--
                In-flight state (D-21). No CTA — the page polls itself
                until migrations finish, at which point the
                EnsureDatabaseReady gate lets the request through to the
                welcome screen on the next navigation.
            --}}
            <div wire:poll.2000ms class="space-y-2">
                <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('desktop::screens.setup.pending_heading') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('desktop::screens.setup.pending_body') }}
                </p>
            </div>
        @else
            {{--
                Once the bootstrap reports no pending migrations the gate
                middleware will let the user through to the welcome / login
                screen on the next request — render a calm "ready" line
                while we wait for the redirect.
            --}}
            <div wire:poll.500ms class="space-y-2">
                <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('desktop::screens.setup.ready_heading') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('desktop::screens.setup.ready_body') }}
                </p>
            </div>
        @endif
    </div>
</div>
