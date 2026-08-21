{{--
    Pairing-flow modal — UI-SPEC Surface B.
    Nested Livewire component hosted inside the Devices & Sync section's
    <flux:modal>. A step-based bidirectional pairing flow:

      Step 1  choose_direction — two equal cards: Show my code / Enter a code
      Step 2a show_code        — 240px QR + word-code + live countdown
      Step 2b enter_code       — monospace uppercase input + inline error
      Step 3  confirm          — 6-word safety-number, mandatory both-screen
                                 confirmation; the sole gate to confirmed_at
      Step 4  success          — "Device paired"

    wire:poll.3s="checkPairingState" runs only on the show_code and confirm steps
    (it advances the flow when the peer acts). Calm-slate (sketch-findings-beatrax),
    weights 400/600 only, min-h-[44px] buttons, JetBrains Mono identifiers.
--}}

@use('Modules\Core\Public\Support\Lang')
<div>
<flux:modal wire:model="open" class="md:max-w-md" @close="$wire.cancelPairing()">
{{-- Flux forwards only class/style/autofocus to the <dialog>; anything else
     lands on the <ui-modal> wrapper, where a name does nothing. So the step's
     own heading is bound to the dialog from inside it. --}}
<div
    class="space-y-4 p-6"
    wire:key="pairing-step-{{ $step }}"
    x-data
    x-init="$el.closest('dialog')?.setAttribute('aria-labelledby', 'pairing-modal-title')"
>

    {{-- ===== Step 1: choose direction ===== --}}
    @if ($step === 'choose_direction')
        <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100" id="pairing-modal-title">{{ Lang::get('sync::pairing.title') }}</h3>
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('sync::pairing.step_1_of_3') }}</p>

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
                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('sync::pairing.show_my_code') }}</span>
                <span class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('sync::pairing.show_my_code_help') }}</span>
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
                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('sync::pairing.enter_a_code') }}</span>
                <span class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('sync::pairing.enter_a_code_help') }}</span>
            </button>
        </div>
    @endif

    {{-- ===== Step 2a: show my code (QR + word-code + countdown) ===== --}}
    @if ($step === 'show_code')
        <div wire:poll.3s="checkPairingState">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100" id="pairing-modal-title">{{ Lang::get('sync::pairing.show_this_code') }}</h3>
            <p class="mb-4 text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('sync::pairing.step_2_of_3') }}</p>

            @if ($expiresInSeconds > 0)
                {{-- 240px QR on a white tile (QR needs a white background in dark mode too) --}}
                {{-- $qrSvg is raw-echoed by necessity (inline SVG). SAFE because
                     the QR payload is built ENTIRELY from server-side identity + a CSPRNG
                     token (QrPayloadBuilder) — NO user input ever reaches it, and the
                     property is #[Locked] so the client cannot rehydrate markup into it.
                     Do not feed any user-controlled string into the QR payload, and do
                     not remove the #[Locked], or this becomes XSS. --}}
                <div class="mx-auto w-fit rounded-xl bg-white dark:bg-white p-4">
                    <div class="h-[240px] w-[240px]">{!! $qrSvg !!}</div>
                </div>

                <p class="mt-4 select-all text-center font-mono text-sm uppercase tracking-widest text-slate-900 dark:text-slate-100">
                    {{ $wordCode }}
                </p>
                <p class="mt-2 text-center text-xs text-slate-500 dark:text-slate-400">
                    {{ Lang::get('sync::pairing.enter_on_other') }}
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
                    {{ Lang::get('sync::pairing.expires_in') }} <span x-text="label">{{ floor($expiresInSeconds / 60) }}:{{ str_pad((string) ($expiresInSeconds % 60), 2, '0', STR_PAD_LEFT) }}</span>
                </p>
            @else
                {{-- Expired state --}}
                {{-- See note above — $qrSvg is server-generated from CSPRNG +
                     identity only, never from user input, and #[Locked] against
                     client rehydration. --}}
                <div class="mx-auto w-fit rounded-xl bg-white dark:bg-white p-4 opacity-30">
                    <div class="h-[240px] w-[240px]">{!! $qrSvg !!}</div>
                </div>
                <p class="mt-4 text-center text-sm text-amber-700 dark:text-amber-400" role="alert" aria-live="polite">
                    {{ Lang::get('sync::pairing.code_expired') }}
                </p>
                <div class="mt-2 text-center">
                    <button
                        type="button"
                        wire:click="regenerateCode"
                        class="text-sm text-slate-500 underline-offset-2 hover:underline
                               focus:outline-none focus-visible:underline dark:text-slate-400"
                    >
                        {{ Lang::get('sync::pairing.generate_new_code') }}
                    </button>
                </div>
            @endif

            <div class="mt-4">
                <x-core::secondary-button
                    block="full"
                    class="min-h-[44px]"
                    wire:click="cancelPairing"
                >
                    {{ Lang::get('sync::pairing.cancel_pairing') }}
                </x-core::secondary-button>
            </div>
        </div>
    @endif

    {{-- ===== Step 2b: enter a code ===== --}}
    @if ($step === 'enter_code')
        <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100" id="pairing-modal-title">{{ Lang::get('sync::pairing.enter_the_code') }}</h3>
        <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('sync::pairing.step_2_of_3') }}</p>

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
                aria-label="{{ Lang::get('sync::pairing.enter_code_aria') }}"
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
                class="min-h-[44px]"
                wire:click="submitCode"
            >
                {{ Lang::get('sync::pairing.submit_code') }}
            </x-core::neutral-button>
            <x-core::secondary-button
                block="flex"
                class="min-h-[44px]"
                wire:click="cancelPairing"
            >
                {{ Lang::get('sync::pairing.cancel_pairing') }}
            </x-core::secondary-button>
        </div>
    @endif

    {{-- ===== Step 3: confirm safety numbers (the trust gate) ===== --}}
    @if ($step === 'confirm')
        <div wire:poll.3s="checkPairingState">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100" id="pairing-modal-title">{{ Lang::get('sync::pairing.compare_words') }}</h3>
            <p class="mb-4 text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('sync::pairing.step_3_of_3') }}</p>

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
                aria-label="{{ Lang::get('sync::pairing.safety_number_words') }} {{ strtoupper(implode(' ', $safetyWords)) }}"
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
                {{ Lang::get('sync::pairing.compare_help') }}
            </p>

            @if ($awaitingPeer)
                <p class="mt-4 flex items-center justify-center gap-2 text-sm text-slate-500 dark:text-slate-400" aria-live="polite">
                    <x-core::spinner />
                    {{ Lang::get('sync::pairing.waiting_for_peer') }}
                </p>
            @endif

            <div class="mt-4 flex gap-3">
                <x-core::neutral-button
                    block="flex"
                    :class="'min-h-[44px]' . ' ' . ($awaitingPeer ? 'opacity-50 cursor-wait' : '')"
                    :disabled="$awaitingPeer"
                    wire:click="confirmMatch"
                >
                    {{ Lang::get('sync::pairing.confirm_match') }}
                </x-core::neutral-button>
                <x-core::secondary-button
                    block="flex"
                    class="min-h-[44px]"
                    wire:click="cancelPairing"
                >
                    {{ Lang::get('sync::pairing.cancel_pairing') }}
                </x-core::secondary-button>
            </div>
        </div>
    @endif

    {{-- ===== Step 4: success ===== --}}
    @if ($step === 'success')
        <div class="space-y-3 text-center">
            <svg class="mx-auto h-6 w-6 text-emerald-600 dark:text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100" id="pairing-modal-title">{{ Lang::get('sync::pairing.device_paired') }}</h3>
            <p class="mx-auto max-w-xs text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('sync::pairing.device_paired_help') }}
            </p>
            {{-- Success close uses closeModal() — it must NOT expire the
                 just-confirmed token the way the in-flow cancel does. --}}
            <x-core::neutral-button
                block="full"
                class="min-h-[44px]"
                wire:click="closeModal"
            >
                {{ Lang::get('sync::pairing.done') }}
            </x-core::neutral-button>
        </div>
    @endif
</div>
</flux:modal>
</div>
