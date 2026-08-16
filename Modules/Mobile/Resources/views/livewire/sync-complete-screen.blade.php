{{--
    Post-setup confirmation. The setup gate used to redirect straight into the
    dashboard the instant parity was reached, so the one moment the user was
    owed an answer — did it work, and what happens now — passed in a flash of
    a progress bar.

    Full-screen safe-area chrome and the 48x48 /icon.png app-mark block are
    reused verbatim from setup-progress-screen.blade.php, so the two screens
    read as one flow rather than two designs.

    Unlike that screen this one is NOT blocking: it offers exactly one way on,
    and nothing here is doing work in the background.
--}}
@use('Modules\Core\Public\Support\Lang')
<div
    class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950
            px-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)]
            pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)]
            motion-reduce:transition-none"
>
    <div class="w-full max-w-sm px-6 py-10 space-y-8">

        <div class="space-y-4 text-center">
            <div class="flex justify-center">
                <img
                    src="/icon.png"
                    width="48"
                    height="48"
                    alt="Beatrax"
                    class="rounded-xl"
                    aria-hidden="true"
                />
            </div>

            <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                {{ Lang::get('mobile::sync_complete.heading') }}
            </h1>

            {{-- A completed catch-up that copied nothing is a real outcome —
                 the devices were already level — and reporting it as
                 "0 records" reads as a failure rather than as parity. --}}
            <p class="text-sm text-slate-500 dark:text-slate-400">
                @if ($recordsApplied === 0)
                    {{ Lang::get('mobile::sync_complete.records_none', ['peer' => $peerName]) }}
                @else
                    {{ Lang::get('mobile::sync_complete.records', ['count' => $recordsApplied, 'peer' => $peerName]) }}
                @endif
            </p>
        </div>

        <div class="space-y-5">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                {{ Lang::get('mobile::sync_complete.how_it_works') }}
            </h2>

            {{-- dt/dd are DIRECT children: grouping each pair in a <div> is
                 valid HTML5 but the analyser still applies the older rule that
                 a term must sit immediately inside its list, and reported four
                 bugs for it. Spacing moves onto the items instead. --}}
            <dl>
                <dt class="mt-5 text-sm font-medium text-slate-900 first:mt-0 dark:text-slate-100">
                    {{ Lang::get('mobile::sync_complete.automatic_title') }}
                </dt>
                <dd class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('mobile::sync_complete.automatic_body') }}
                </dd>

                <dt class="mt-5 text-sm font-medium text-slate-900 first:mt-0 dark:text-slate-100">
                    {{ Lang::get('mobile::sync_complete.lan_title') }}
                </dt>
                <dd class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('mobile::sync_complete.lan_body') }}
                </dd>

                {{-- Relay copy is conditional: promising that changes travel
                     while the devices are apart is only true once an endpoint
                     is actually configured on this device. --}}
                <dt class="mt-5 text-sm font-medium text-slate-900 first:mt-0 dark:text-slate-100">
                    {{ $hasRelay
                        ? Lang::get('mobile::sync_complete.relay_title')
                        : Lang::get('mobile::sync_complete.no_relay_title') }}
                </dt>
                <dd class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ $hasRelay
                        ? Lang::get('mobile::sync_complete.relay_body')
                        : Lang::get('mobile::sync_complete.no_relay_body') }}
                </dd>

                <dt class="mt-5 text-sm font-medium text-slate-900 first:mt-0 dark:text-slate-100">
                    {{ Lang::get('mobile::sync_complete.encrypted_title') }}
                </dt>
                <dd class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('mobile::sync_complete.encrypted_body') }}
                </dd>
            </dl>
        </div>

        {{-- Flux's primary variant paints its own accent, which is not the
             slate this app uses for every other primary action — on the last
             screen of onboarding that read as a stray button from another app. --}}
        <button
            type="button"
            wire:click="continueToApp"
            class="w-full min-h-[44px] rounded-md bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white
                   hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                   dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
        >
            {{ Lang::get('mobile::sync_complete.continue') }}
        </button>

    </div>
</div>
