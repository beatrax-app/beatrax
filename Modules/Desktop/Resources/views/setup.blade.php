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
                alt="Beatrax"
                class="h-16 w-16"
            />
        </div>

        @if ($isPending)
            {{--
                In-flight state (D-21). No CTA — the tick APPLIES the
                migrations rather than waiting for them, because the only
                other caller runs before the window opens; polling alone
                waited on a state nothing could change.
            --}}
            <div wire:poll.2000ms="poll" class="space-y-2">
                <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('desktop::screens.setup.pending_heading') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ $failed ? Lang::get('desktop::screens.setup.failed_body') : Lang::get('desktop::screens.setup.pending_body') }}
                </p>
            </div>
        @else
            {{--
                Once the bootstrap reports no pending migrations the gate
                middleware will let the user through to the welcome / login
                screen on the next request — render a calm "ready" line
                while we wait for the redirect.
            --}}
            <div wire:poll.500ms="poll" class="space-y-2">
                <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('desktop::screens.setup.ready_heading') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('desktop::screens.setup.ready_body') }}
                </p>
            </div>
        @endif
    </div>
</div>
