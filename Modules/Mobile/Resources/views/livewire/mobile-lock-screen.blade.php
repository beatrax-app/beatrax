{{--
    Biometric-primary mobile app-lock unlock screen (R6, MOBILE-01,
    15-UI-SPEC.md §4). Structurally identical to
    Modules/Auth/Resources/views/livewire/lock-screen.blade.php — the naked
    full-screen safe-area chrome (lines 1-5) and the 48x48 /icon.png app-mark
    block are reused verbatim, and the PIN-pad markup below is duplicated
    from Modules/Auth/Resources/views/livewire/partials/pin-pad.blade.php the
    same way Modules/Mobile/Resources/views/livewire/setup-progress-screen
    .blade.php already duplicated the safe-area chrome (a cross-module Blade
    @include was deliberately avoided).

    UI-SPEC §4: "the existing lock-screen layout (PIN pad first, biometric
    button below) is kept" — no layout change vs the Auth lock screen beyond
    the mount-time auto-invoke below. The biometric button is accent-styled
    (unlike the desktop/web neutral biometric button) per UI-SPEC's Accent
    color reservation, which explicitly lists "biometric-unlock button"
    alongside the other primary CTAs.

    Only behavioral difference: on mount, when a biometric credential is
    enrolled ($biometricAvailable), the platform biometric prompt
    auto-invokes via x-init (R6/D-03 Claude's Discretion: biometric-primary
    means AUTO-INVOKED, not just visually-first) — no tap required. Tapping
    the biometric button retries the prompt manually.
--}}
@use('Modules\Core\Public\Support\Lang')
<div
    class="beatrax-shell min-h-screen flex items-center justify-center
            px-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)]
            pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)]
            motion-reduce:transition-none"
    {{--
        WR-10 / T-05-12 (same discipline as the Auth lock screen): PIN
        digits accumulate in this transient Alpine state, never in a
        Livewire property.
    --}}
    x-data="{
        pin: '',
        press(d) {
            if (this.pin.length < 10) {
                this.pin += d;
            }
        },
        back() {
            this.pin = this.pin.slice(0, -1);
        },
        submitPin() {
            const p = this.pin;
            this.pin = '';
            $wire.submit(p);
        },
    }"
    @if ($biometricAvailable)
        x-init="$wire.biometricPrompt()"
    @endif
    x-on:keydown.window="
        const k = $event.key;
        if (k >= '0' && k <= '9') {
            press(k);
        } else if (k === 'Backspace') {
            back();
        } else if (k === 'Enter') {
            submitPin();
        }
    "
>
    <div class="w-full max-w-sm px-6 space-y-6">

        {{-- App mark --}}
        <div class="flex justify-center">
            <img
                src="{{ Vite::asset('resources/brand/logo.svg') }}"
                width="48"
                height="48"
                alt="Beatrax"
                class="rounded-xl"
                aria-hidden="true"
            />
        </div>

        {{-- PIN pad — dot display --}}
        <div
            class="flex justify-center gap-2 py-3"
            aria-live="polite"
            x-bind:aria-label="pin.length + ' {{ Lang::get('mobile::lock.digits_entered') }}'"
        >
            <template x-for="i in 10" :key="i">
                <span
                    class="h-3 w-3 rounded-full transition-colors duration-150 motion-reduce:transition-none"
                    x-bind:class="i <= pin.length
                        ? 'bg-slate-900 dark:bg-slate-100'
                        : 'bg-slate-200 dark:bg-slate-700'"
                ></span>
            </template>
        </div>

        {{-- Backoff / flash label --}}
        @if ($flashMessage !== '')
            <div
                class="text-center text-sm text-rose-600 dark:text-rose-400 py-1"
                aria-live="assertive"
                role="alert"
            >
                {{ $flashMessage }}
            </div>
        @else
            <div class="py-1" aria-live="assertive"></div>
        @endif

        {{-- PIN pad grid --}}
        <div class="grid grid-cols-3 gap-2" role="group" aria-label="{{ Lang::get('mobile::lock.pin_pad') }}">

            @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
                <button
                    type="button"
                    x-on:click="press('{{ $digit }}')"
                    aria-label="{{ Lang::get('mobile::lock.digit', ['digit' => $digit]) }}"
                    class="flex h-16 w-full sm:h-14 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800
                           text-xl font-semibold text-slate-900 dark:text-slate-100
                           transition-colors duration-[80ms] motion-reduce:transition-none
                           hover:bg-slate-200 dark:hover:bg-slate-700
                           active:bg-slate-300 dark:active:bg-slate-600
                           focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100 focus-visible:ring-offset-2"
                >
                    {{ $digit }}
                </button>
            @endforeach

            {{-- Bottom row: Backspace | 0 | Confirm --}}
            <button
                type="button"
                x-on:click="back()"
                aria-label="{{ Lang::get('mobile::lock.backspace') }}"
                class="flex h-16 w-full sm:h-14 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800
                       text-slate-600 dark:text-slate-400
                       transition-colors duration-[80ms] motion-reduce:transition-none
                       hover:bg-slate-200 dark:hover:bg-slate-700
                       active:bg-slate-300 dark:active:bg-slate-600
                       focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100 focus-visible:ring-offset-2"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     class="h-5 w-5" aria-hidden="true">
                    <path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/>
                    <line x1="18" y1="9" x2="12" y2="15"/>
                    <line x1="12" y1="9" x2="18" y2="15"/>
                </svg>
            </button>

            <button
                type="button"
                x-on:click="press('0')"
                aria-label="{{ Lang::get('mobile::lock.digit', ['digit' => 0]) }}"
                class="flex h-16 w-full sm:h-14 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800
                       text-xl font-semibold text-slate-900 dark:text-slate-100
                       transition-colors duration-[80ms] motion-reduce:transition-none
                       hover:bg-slate-200 dark:hover:bg-slate-700
                       active:bg-slate-300 dark:active:bg-slate-600
                       focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100 focus-visible:ring-offset-2"
            >
                0
            </button>

            <button
                type="button"
                x-on:click="submitPin()"
                aria-label="{{ Lang::get('mobile::lock.ok_aria') }}"
                class="flex h-16 w-full sm:h-14 items-center justify-center rounded-xl bg-slate-900 dark:bg-slate-100
                       text-white dark:text-slate-900
                       text-sm font-semibold
                       transition-colors duration-[80ms] motion-reduce:transition-none
                       hover:bg-slate-700 dark:hover:bg-slate-200
                       active:bg-slate-600 dark:active:bg-slate-300
                       focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100 focus-visible:ring-offset-2"
            >
                {{ Lang::get('mobile::lock.ok') }}
            </button>

        </div>

        {{-- Biometric button (only shown when a credential is enrolled AND
             the native bridge reports the mobile runtime present) — the
             mobile PRIMARY unlock action, auto-invoked above on mount;
             tapping retries manually. Accent-styled per UI-SPEC's
             biometric-unlock-button reservation. --}}
        @if ($biometricAvailable)
            <button
                type="button"
                wire:click="biometricPrompt"
                class="w-full flex items-center justify-center gap-2 rounded-xl bg-slate-900 dark:bg-slate-100
                       py-3 text-sm font-semibold text-white dark:text-slate-900
                       hover:bg-slate-700 dark:hover:bg-slate-200
                       focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100 focus-visible:ring-offset-2
                       transition-colors duration-[80ms] motion-reduce:transition-none"
            >
                {{ $biometricLabel }}
            </button>
        @endif

        {{-- Sign out (D-03). Forgot-PIN recovery mirrors the Auth lock
             screen exactly — sign out leads back to password login. --}}
        <form method="POST" action="{{ route('logout') }}" x-data x-on:submit.prevent="beatraxSubmitPostForm($el, $event.submitter)">
            @csrf
            <button
                type="submit"
                class="w-full text-center text-sm text-rose-600 dark:text-rose-400
                       hover:text-rose-700 dark:hover:text-rose-300
                       focus:outline-none focus-visible:underline
                       py-2"
            >
                {{ Lang::get('mobile::lock.sign_out') }}
            </button>
        </form>

    </div>
</div>
