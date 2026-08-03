{{--
    Camera-first mobile pairing entry (D-01/D-02, MOBILE-01, 15-UI-SPEC.md
    §1). Extends the Phase 12 pairing-flow-modal.blade.php step chrome (Step
    N of 3 counters, min-h-[44px] buttons, JetBrains Mono word-code input,
    calm-slate) as a standalone page rather than a nested modal step — the
    confirm/success step markup below is duplicated from
    pairing-flow-modal.blade.php's own confirm/success steps (a cross-module
    Blade @include was deliberately avoided, matching how
    mobile-lock-screen.blade.php already duplicated lock-screen.blade.php's
    PIN-pad markup).

    Camera view: the frame below is a placeholder, not a preview. The real
    scan surface is `nativephp/mobile-scanner`'s own full-screen activity,
    launched through QrScanBridge::open() and closing back into this component
    via the CodeScanned / ScannerCancelled events MobilePairingScan listens
    for. The plugin lives only in mobile-app/vendor, so the repo-root
    toolchain cannot resolve it — hence the runtime FQCN strings there and the
    reflection-only signatures in tools/phpstan-stubs.
--}}
@use('Modules\Core\Public\Support\Lang')
<div class="max-w-lg mx-auto px-6 py-8 space-y-4" data-testid="mobile-pairing-scan" wire:key="pairing-step-{{ $step }}">

    {{-- ===== Step: camera scan (default landing, D-01) ===== --}}
    @if ($step === 'scan')
        <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::pairing.scan_heading') }}</h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('mobile::pairing.scan_subtitle') }}</p>

        @if ($flashMessage !== '')
            <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
        @endif

        {{-- Framing placeholder, not a live preview: the scanner plugin
             presents its own full-screen camera activity, decodes there, and
             dispatches CodeScanned / ScannerCancelled back into this
             component.

             Opened from the button below, never automatically: firing
             startScan() from wire:init, x-init or livewire:initialized lands
             while the component is still hydrating and comes back 404, which
             paints an error modal over the page. From the button it returns
             200 and the OS scanner launches. --}}
        <div
            class="relative mx-auto aspect-square w-full max-w-sm overflow-hidden rounded-xl bg-slate-100 dark:bg-slate-800"
            data-testid="qr-viewfinder"
        >
            <svg class="absolute inset-6 h-[calc(100%-3rem)] w-[calc(100%-3rem)] text-slate-700 dark:text-slate-300" viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" d="M4 20V8a4 4 0 014-4h12" />
                <path stroke-linecap="round" d="M96 20V8a4 4 0 00-4-4H80" />
                <path stroke-linecap="round" d="M4 80v12a4 4 0 004 4h12" />
                <path stroke-linecap="round" d="M96 80v12a4 4 0 01-4 4H80" />
            </svg>
        </div>

        <button
            type="button"
            wire:click="startScan"
            class="w-full min-h-[44px] rounded-md bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white
                   hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                   dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
        >
            {{ Lang::get('mobile::pairing.open_camera') }}
        </button>

        <div class="text-center">
            <button
                type="button"
                wire:click="useWordCode"
                class="min-h-[44px] px-2 text-sm text-slate-500 underline-offset-2 hover:underline
                       focus:outline-none focus-visible:underline dark:text-slate-400"
            >
                {{ Lang::get('mobile::pairing.enter_code_instead') }}
            </button>
        </div>
    @endif

    {{-- ===== Step: enter a code (D-02 fallback — camera unavailable/denied or user choice) ===== --}}
    @if ($step === 'enter_code')
        <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::pairing.enter_heading') }}</h1>

        @if ($cameraUnavailableNotice)
            <div
                class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300"
                data-testid="camera-unavailable-notice"
                aria-live="polite" aria-atomic="true"
            >
                {{ Lang::get('mobile::pairing.camera_off') }}
            </div>
        @endif

        <div
            class="space-y-1"
            x-data="{
                format(el) {
                    let v = el.value.toUpperCase().replace(/[^A-Z2-7]/g, '');
                    el.value = (v.match(/.{1,4}/g) || []).join('-');
                },
            }"
        >
            <input
                id="mobile-word-code-input"
                type="text"
                inputmode="text"
                autocomplete="off"
                spellcheck="false"
                wire:model="wordCode"
                x-on:input="format($event.target)"
                placeholder="XXXX-XXXX-XXXX-XXXX"
                aria-label="{{ Lang::get('mobile::pairing.word_code_aria') }}"
                class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-base font-mono uppercase tracking-widest text-slate-900
                       focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                       dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
            />
            @if ($flashMessage !== '')
                <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
            @endif
        </div>

        <div class="flex gap-3">
            <button
                type="button"
                wire:click="submitCode"
                class="flex-1 min-h-[44px] rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white
                       hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                       dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
            >
                {{ Lang::get('mobile::pairing.submit_code') }}
            </button>
            <button
                type="button"
                wire:click="cancelPairing"
                class="flex-1 min-h-[44px] rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900
                       hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                       dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100"
            >
                {{ Lang::get('mobile::pairing.cancel') }}
            </button>
        </div>
    @endif

    {{-- ===== Step: confirm safety numbers (the trust gate, D-07/D-08) ===== --}}
    @if ($step === 'confirm')
        <div wire:poll.3s="checkPairingState">
            <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::pairing.confirm_heading') }}</h1>

            @php
                $rowOne = array_slice($safetyWords, 0, 3);
                $rowTwo = array_slice($safetyWords, 3, 3);
            @endphp
            <div
                class="space-y-2"
                aria-label="{{ Lang::get('mobile::pairing.safety_words_aria', ['words' => strtoupper(implode(' ', $safetyWords))]) }}"
            >
                <div class="flex justify-center gap-2">
                    @foreach ($rowOne as $word)
                        <span class="rounded bg-slate-100 px-2 py-1 font-mono text-sm uppercase text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $word }}</span>
                    @endforeach
                </div>
                <div class="flex justify-center gap-2">
                    @foreach ($rowTwo as $word)
                        <span class="rounded bg-slate-100 px-2 py-1 font-mono text-sm uppercase text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $word }}</span>
                    @endforeach
                </div>
            </div>

            <p class="mx-auto mt-4 max-w-sm text-center text-xs text-slate-500 dark:text-slate-400">
                {{ Lang::get('mobile::pairing.confirm_body') }}
            </p>

            @if ($awaitingPeer)
                <p class="mt-4 flex items-center justify-center gap-2 text-sm text-slate-500 dark:text-slate-400" aria-live="polite">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    {{ Lang::get('mobile::pairing.awaiting_peer') }}
                </p>
            @endif

            <div class="mt-4 flex gap-3">
                <button
                    type="button"
                    wire:click="confirmMatch"
                    @disabled($awaitingPeer)
                    @class([
                        'flex-1 min-h-[44px] rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white',
                        'hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2',
                        'dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100',
                        'opacity-50 cursor-wait' => $awaitingPeer,
                    ])
                >
                    {{ Lang::get('mobile::pairing.confirm_match') }}
                </button>
                <button
                    type="button"
                    wire:click="cancelPairing"
                    class="flex-1 min-h-[44px] rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900
                           hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                           dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100"
                >
                    {{ Lang::get('mobile::pairing.cancel') }}
                </button>
            </div>
        </div>
    @endif

    {{-- ===== Step: success ===== --}}
    @if ($step === 'success')
        <div class="space-y-3 text-center">
            <svg class="mx-auto h-6 w-6 text-emerald-600 dark:text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::pairing.success_heading') }}</h1>
            <p class="mx-auto max-w-xs text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('mobile::pairing.success_body') }}
            </p>
            <button
                type="button"
                wire:click="finishPairing"
                class="w-full min-h-[44px] rounded-md bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white
                       hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                       dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
            >
                {{ Lang::get('mobile::pairing.done') }}
            </button>
        </div>
    @endif

</div>
