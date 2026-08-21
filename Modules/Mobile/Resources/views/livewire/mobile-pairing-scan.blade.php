{{--
    Camera-first mobile pairing entry (15-UI-SPEC.md §1). Extends the
    pairing-flow-modal.blade.php step chrome (Step N of 3 counters,
    min-h-[44px] buttons, JetBrains Mono word-code input,
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
@use('Illuminate\Support\Js')
{{-- Full-screen safe-area chrome, matching setup-progress-screen and
     sync-complete-screen. This screen used to render inside layouts.app,
     which brought the drawer, sidebar and mobile top bar along with it —
     app navigation wrapped around a blocking setup step the user cannot
     leave, and tapping it left the wizard mid-ceremony. --}}
<div class="min-h-screen bg-white dark:bg-slate-950
            px-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)]
            pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)]"
     data-testid="mobile-pairing-scan"
     wire:key="pairing-step-{{ $step }}">
<div class="max-w-lg mx-auto px-6 py-8 space-y-4">

    {{-- ===== Step: camera scan (default landing) ===== --}}
    @if ($step === 'scan')
        <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::pairing.scan_heading') }}</h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('mobile::pairing.scan_subtitle') }}</p>

        @if ($flashMessage !== '')
            <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
        @endif

        {{-- The preview runs inside this frame rather than taking over the
             screen: a full-screen activity appearing over the pairing page
             reads as the app navigating somewhere else. The camera is the
             WebView's own (getUserMedia), decoded in-page by the platform
             BarcodeDetector — no JS decode library — and the scanner plugin's
             full-screen activity is the fallback where neither is offered.
             Both funnel into the same submitCode(). --}}
        <div x-data="beatraxInlineScanner($wire)" x-init="probe()" x-on:beatrax-step-left.window="stop()">
            {{-- The filled panel is the IDLE state's affordance — it shows
                 there is a frame to fill. Once the camera is live it is pure
                 chrome behind an opaque video, and dropping it lets the
                 preview sit in the page instead of arriving as a grey box
                 that suddenly turns into a camera. Everything around it —
                 heading, guides, buttons — stays put. --}}
            <div
                :class="live ? 'bg-transparent' : 'bg-slate-100 dark:bg-slate-800'"
                class="relative mx-auto aspect-square w-full max-w-sm overflow-hidden rounded-xl transition-colors duration-200"
                data-testid="qr-viewfinder"
            >
                <video
                    x-ref="preview"
                    x-show="live"
                    class="absolute inset-0 h-full w-full object-cover"
                    playsinline
                    muted
                    aria-label="{{ Lang::get('mobile::pairing.viewfinder_aria') }}"
                ></video>

                {{-- Over a live camera the slate guides lose contrast against
                     whatever the lens sees, so they go white with a drop
                     shadow the moment the preview starts. --}}
                <svg
                    :class="live ? 'text-white drop-shadow-[0_1px_2px_rgba(0,0,0,0.65)]' : 'text-slate-700 dark:text-slate-300'"
                    class="absolute inset-6 h-[calc(100%-3rem)] w-[calc(100%-3rem)]"
                    viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"
                >
                    <path stroke-linecap="round" d="M4 20V8a4 4 0 014-4h12" />
                    <path stroke-linecap="round" d="M96 20V8a4 4 0 00-4-4H80" />
                    <path stroke-linecap="round" d="M4 80v12a4 4 0 004 4h12" />
                    <path stroke-linecap="round" d="M96 80v12a4 4 0 01-4 4H80" />
                </svg>

                {{-- Centred in the frame, not pinned to its bottom edge: with
                     the camera off the frame is empty, so the copy explaining
                     why is the only thing in it and belongs in the middle. --}}
                <p
                    x-show="! live"
                    class="absolute inset-0 flex items-center justify-center px-8 text-center text-xs text-slate-500 dark:text-slate-400"
                >{{ Lang::get('mobile::pairing.viewfinder_idle') }}</p>
            </div>

            <p
                x-show="permissionPending"
                x-cloak
                class="mt-3 text-xs text-amber-600 dark:text-amber-400"
                aria-live="polite"
            >{{ Lang::get('mobile::pairing.camera_permission_pending') }}</p>

            <x-core::neutral-button
                block="full"
                class="mt-4 min-h-[44px]"
                x-on:click="toggle()"
                x-bind:disabled="starting"
                x-text="starting ? {{ Js::from(Lang::get('mobile::pairing.opening_camera')) }} : (live ? {{ Js::from(Lang::get('mobile::pairing.close_camera')) }} : {{ Js::from(Lang::get('mobile::pairing.open_camera')) }})"
            >{{ Lang::get('mobile::pairing.open_camera') }}</x-core::neutral-button>
        </div>

        {{-- Offered while importing too. A typed code carries the token and
             nothing else, which is why the arm used to be hidden here; the
             importing device now asks the LAN for the initiator's public
             identity, so the arm can complete rather than only ever error.
             Someone who cannot or will not use the camera has a route in. --}}
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

    {{-- ===== Step: enter a code (fallback — camera unavailable/denied or user choice) ===== --}}
    @if ($step === 'enter_code')
        <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::pairing.enter_heading') }}</h1>

        @if ($cameraUnavailableNotice)
            <x-core::alert
                tone="warning"
                data-testid="camera-unavailable-notice"
                aria-live="polite" aria-atomic="true"
            >
                {{ Lang::get('mobile::pairing.camera_off') }}
            </x-core::alert>
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
            <x-core::neutral-button
                block="flex"
                class="min-h-[44px] disabled:opacity-50 disabled:cursor-wait"
                {{-- The null is load-bearing: submitCode()'s first parameter is the scanned QR payload, and calling it with no argument at all makes Livewire try to resolve a ?string from the container, which 500s. --}}
                wire:click="submitCode(null)"
                {{-- Submitting an import code asks the network who holds it, which takes a couple of seconds. Without this the button sat live and unresponsive for the whole browse, which reads as a tap that did not register. --}}
                wire:loading.attr="disabled"
                wire:target="submitCode"
            >
                {{ Lang::get('mobile::pairing.submit_code') }}
            </x-core::neutral-button>
            {{-- Backs out of TYPING, not out of pairing. cancelPairing() here
                 expired the token and left the flow entirely, landing the user
                 on a screen they had already finished. --}}
            <x-core::secondary-button
                block="flex"
                class="min-h-[44px]"
                wire:click="backToScan"
            >
                {{ Lang::get('mobile::pairing.cancel') }}
            </x-core::secondary-button>
        </div>
    @endif

    {{-- ===== Step: confirm safety numbers (the trust gate) ===== --}}
    @if ($step === 'confirm')
        <div wire:poll.3s="checkPairingState">
            <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::pairing.confirm_heading') }}</h1>

            {{-- The words prove the CHANNEL is untampered; the names say
                 WHICH two devices it connects. Both are part of the check. --}}
            <p class="mb-3 text-sm text-slate-700 dark:text-slate-300">
                <span class="font-medium">{{ $selfDeviceName }}</span>
                <span class="text-slate-400 dark:text-slate-500">&harr;</span>
                <span class="font-medium">{{ $peerDeviceName }}</span>
            </p>

            @php
                $rowOne = array_slice($safetyWords, 0, 3);
                $rowTwo = array_slice($safetyWords, 3, 3);
            @endphp
            <div
                class="space-y-2"
                role="group"
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
                    <x-core::spinner />
                    {{ Lang::get('mobile::pairing.awaiting_peer') }}
                </p>
            @endif

            <div class="mt-4 flex gap-3">
                <x-core::neutral-button
                    block="flex"
                    :class="'min-h-[44px]' . ' ' . ($awaitingPeer ? 'opacity-50 cursor-wait' : '')"
                    :disabled="$awaitingPeer"
                    wire:click="confirmMatch"
                >
                    {{ Lang::get('mobile::pairing.confirm_match') }}
                </x-core::neutral-button>
                <x-core::secondary-button
                    block="flex"
                    class="min-h-[44px]"
                    wire:click="cancelPairing"
                >
                    {{ Lang::get('mobile::pairing.cancel') }}
                </x-core::secondary-button>
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
            <x-core::neutral-button
                block="full"
                class="min-h-[44px]"
                wire:click="finishPairing"
            >
                {{ Lang::get('mobile::pairing.done') }}
            </x-core::neutral-button>
        </div>
    @endif

</div>
</div>
