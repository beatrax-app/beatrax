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

    Camera view: full-bleed viewfinder inside a --color-surface-2 frame with
    a corner-bracket overlay (1.5px stroke, currentColor). The REAL native
    scan surface (`nativephp/mobile-scanner`'s camera view + its
    `CodeScanned` event) is unreachable from the repo-root toolchain (only
    mobile-app/vendor carries the plugin, 15-03-SUMMARY.md) — this markup
    renders the viewfinder frame and wires the Livewire call sites
    (`submitCode`, `cameraDenied`) the native runtime is expected to invoke;
    the actual on-device camera-permission/decode wiring is verified by a
    manual on-device UAT pass (15-11), mirroring BiometricUnlockBridge's
    identical "compile-correct, UAT-verified" precedent (15-06-SUMMARY.md).
--}}
<div class="max-w-lg mx-auto px-6 py-8 space-y-4" data-testid="mobile-pairing-scan" wire:key="pairing-step-{{ $step }}">

    {{-- ===== Step: camera scan (default landing, D-01) ===== --}}
    @if ($step === 'scan')
        <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Pair this device</h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">Point the camera at the code shown on the other device.</p>

        @if ($flashMessage !== '')
            <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
        @endif

        {{-- Full-bleed 1:1 viewfinder — --color-surface-2 frame, corner-bracket
             overlay (1.5px stroke, currentColor, slate-700/slate-300). No JS
             QR-decode library — the native mobile-scanner plugin decodes and
             the runtime is expected to call $wire.submitCode(payload) on a
             successful frame, or $wire.cameraDenied() when the OS permission
             is refused. --}}
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

        <div class="text-center">
            <button
                type="button"
                wire:click="enterACode"
                class="min-h-[44px] px-2 text-sm text-slate-500 underline-offset-2 hover:underline
                       focus:outline-none focus-visible:underline dark:text-slate-400"
            >
                Enter code instead
            </button>
        </div>
    @endif

    {{-- ===== Step: enter a code (D-02 fallback — camera unavailable/denied or user choice) ===== --}}
    @if ($step === 'enter_code')
        <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Enter the code</h1>

        @if ($cameraUnavailableNotice)
            <div
                class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300"
                data-testid="camera-unavailable-notice"
                aria-live="polite" aria-atomic="true"
            >
                Camera access is off. Enter the code from the other device instead.
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
                aria-label="Enter the word code from the other device"
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
                Submit code
            </button>
            <button
                type="button"
                wire:click="cancelPairing"
                class="flex-1 min-h-[44px] rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900
                       hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                       dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100"
            >
                Cancel
            </button>
        </div>
    @endif

    {{-- ===== Step: confirm safety numbers (the trust gate, D-07/D-08) ===== --}}
    @if ($step === 'confirm')
        <div wire:poll.3s="checkPairingState">
            <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Compare these words with the other device</h1>

            @php
                $rowOne = array_slice($safetyWords, 0, 3);
                $rowTwo = array_slice($safetyWords, 3, 3);
            @endphp
            <div
                class="space-y-2"
                aria-label="Safety number words: {{ strtoupper(implode(' ', $safetyWords)) }}"
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
                Both devices must show the exact same words. If they differ, tap Cancel — a man-in-the-middle attack may be in progress.
            </p>

            @if ($awaitingPeer)
                <p class="mt-4 flex items-center justify-center gap-2 text-sm text-slate-500 dark:text-slate-400" aria-live="polite">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    Waiting for the other device to confirm...
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
                    Confirm — they match
                </button>
                <button
                    type="button"
                    wire:click="cancelPairing"
                    class="flex-1 min-h-[44px] rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900
                           hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                           dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100"
                >
                    Cancel
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
            <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Device paired</h1>
            <p class="mx-auto max-w-xs text-sm text-slate-500 dark:text-slate-400">
                This device is now trusted. Your data will sync once you connect.
            </p>
            <button
                type="button"
                wire:click="finishPairing"
                class="w-full min-h-[44px] rounded-md bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white
                       hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                       dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
            >
                Done
            </button>
        </div>
    @endif

</div>
