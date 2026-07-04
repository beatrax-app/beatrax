{{--
    Pairing-flow modal — UI-SPEC Surface B (Phase 12, D-04/D-05/D-07/D-13).
    Nested Livewire component hosted inside the Devices & Sync section's
    <flux:modal>. A step-based bidirectional pairing flow:

      Step 1  choose_direction — two equal cards: Show my code / Enter a code
      Step 2a show_code        — 240px QR + word-code + live countdown (D-05/D-13)
      Step 2b enter_code       — monospace uppercase input + inline error
      Step 3  confirm          — 6-word safety-number, mandatory both-screen
                                 confirmation (D-07); the sole gate to confirmed_at
      Step 4  success          — "Device paired"

    wire:poll.3s="checkPairingState" runs only on the show_code and confirm steps
    (it advances the flow when the peer acts). Calm-slate (sketch-findings-beatrax),
    weights 400/600 only, min-h-[44px] buttons, JetBrains Mono identifiers.
--}}

<div>
<flux:modal wire:model="open" class="md:max-w-md" @close="$wire.cancelPairing()">
<div class="space-y-4 p-6" wire:key="pairing-step-{{ $step }}">

    {{-- ===== Step 1: choose direction (D-04) ===== --}}
    @if ($step === 'choose_direction')
        <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100" id="pairing-modal-title">Pair a new device</h3>
        <p class="text-xs text-slate-500 dark:text-slate-400">Step 1 of 3</p>

        @if ($flashMessage !== '')
            <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
        @endif

        <div class="grid gap-3 sm:grid-cols-2">
            <button
                type="button"
                wire:click="showMyCode"
                class="flex min-h-[44px] flex-col items-start gap-1 rounded-xl border border-slate-200 bg-white p-4 text-left
                       hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                       dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800 dark:focus-visible:ring-slate-100"
            >
                <svg class="h-6 w-6 text-slate-700 dark:text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5zM13.5 19.125v-2.625m0 0V13.5m0 3h3m-3 0h-1.5" />
                </svg>
                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">Show my code</span>
                <span class="text-xs text-slate-500 dark:text-slate-400">Display this device's QR and word-code for the other device.</span>
            </button>

            <button
                type="button"
                wire:click="enterACode"
                class="flex min-h-[44px] flex-col items-start gap-1 rounded-xl border border-slate-200 bg-white p-4 text-left
                       hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                       dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800 dark:focus-visible:ring-slate-100"
            >
                <svg class="h-6 w-6 text-slate-700 dark:text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                </svg>
                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">Enter a code</span>
                <span class="text-xs text-slate-500 dark:text-slate-400">Type or scan the code shown on the other device.</span>
            </button>
        </div>
    @endif

    {{-- ===== Step 2a: show my code (QR + word-code + countdown, D-05/D-13) ===== --}}
    @if ($step === 'show_code')
        <div wire:poll.3s="checkPairingState">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Show this code</h3>
            <p class="mb-4 text-xs text-slate-500 dark:text-slate-400">Step 2 of 3</p>

            @if ($expiresInSeconds > 0)
                {{-- 240px QR on a white tile (QR needs a white background in dark mode too) --}}
                {{-- IN-03: $qrSvg is raw-echoed by necessity (inline SVG). SAFE because
                     the QR payload is built ENTIRELY from server-side identity + a CSPRNG
                     token (QrPayloadBuilder) — NO user input ever reaches it. Do not feed
                     any user-controlled string into the QR payload or this becomes XSS. --}}
                <div class="mx-auto w-fit rounded-xl bg-white dark:bg-white p-4">
                    <div class="h-[240px] w-[240px]">{!! $qrSvg !!}</div>
                </div>

                <p class="mt-4 select-all text-center font-mono text-sm uppercase tracking-widest text-slate-900 dark:text-slate-100">
                    {{ $wordCode }}
                </p>
                <p class="mt-2 text-center text-xs text-slate-500 dark:text-slate-400">
                    Enter this code on the other device, or let it scan the QR.
                </p>

                {{-- Live countdown (Alpine); aria-live only fires the expiry notice --}}
                <p
                    class="mt-3 text-center text-xs text-amber-700 dark:text-amber-400"
                    x-data="{
                        remaining: {{ $expiresInSeconds }},
                        interval: null,
                        get label() {
                            const m = Math.floor(this.remaining / 60);
                            const s = String(this.remaining % 60).padStart(2, '0');
                            return m + ':' + s;
                        },
                    }"
                    x-init="interval = setInterval(() => remaining > 0 ? remaining-- : (clearInterval(interval), $wire.onCodeExpired()), 1000)"
                >
                    Expires in <span x-text="label">{{ floor($expiresInSeconds / 60) }}:{{ str_pad((string) ($expiresInSeconds % 60), 2, '0', STR_PAD_LEFT) }}</span>
                </p>
            @else
                {{-- Expired state --}}
                {{-- IN-03: see note above — $qrSvg is server-generated from CSPRNG +
                     identity only, never from user input. --}}
                <div class="mx-auto w-fit rounded-xl bg-white dark:bg-white p-4 opacity-30">
                    <div class="h-[240px] w-[240px]">{!! $qrSvg !!}</div>
                </div>
                <p class="mt-4 text-center text-sm text-amber-700 dark:text-amber-400" role="alert" aria-live="polite">
                    Code expired.
                </p>
                <div class="mt-2 text-center">
                    <button
                        type="button"
                        wire:click="regenerateCode"
                        class="text-sm text-slate-500 underline-offset-2 hover:underline
                               focus:outline-none focus-visible:underline dark:text-slate-400"
                    >
                        Generate new code
                    </button>
                </div>
            @endif

            <div class="mt-4">
                <button
                    type="button"
                    wire:click="cancelPairing"
                    class="min-h-[44px] w-full rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900
                           hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                           dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100"
                >
                    Cancel pairing
                </button>
            </div>
        </div>
    @endif

    {{-- ===== Step 2b: enter a code (D-05) ===== --}}
    @if ($step === 'enter_code')
        <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Enter the code</h3>
        <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">Step 2 of 3</p>

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
                id="word-code-input"
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
                Cancel pairing
            </button>
        </div>
    @endif

    {{-- ===== Step 3: confirm safety numbers (the trust gate, D-07/D-08) ===== --}}
    @if ($step === 'confirm')
        <div wire:poll.3s="checkPairingState">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Compare these words with the other device</h3>
            <p class="mb-4 text-xs text-slate-500 dark:text-slate-400">Step 3 of 3</p>

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
                Both devices must show the exact same words. If they differ, tap Cancel pairing — a man-in-the-middle attack may be in progress.
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
                    Cancel pairing
                </button>
            </div>
        </div>
    @endif

    {{-- ===== Step 4: success ===== --}}
    @if ($step === 'success')
        <div class="space-y-3 text-center">
            <svg class="mx-auto h-6 w-6 text-emerald-600 dark:text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Device paired</h3>
            <p class="mx-auto max-w-xs text-sm text-slate-500 dark:text-slate-400">
                This device is now trusted. Your data will sync once you connect.
            </p>
            {{-- WR-03: success close uses closeModal() — it must NOT expire the
                 just-confirmed token the way the in-flow cancel does. --}}
            <button
                type="button"
                wire:click="closeModal"
                class="w-full min-h-[44px] rounded-md bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white
                       hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                       dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
            >
                Done
            </button>
        </div>
    @endif
</div>
</flux:modal>
</div>
