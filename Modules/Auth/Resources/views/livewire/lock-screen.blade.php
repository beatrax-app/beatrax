@use('Modules\Core\Public\Support\Lang')
<div
    class="beatrax-shell min-h-screen flex items-center justify-center
            px-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)]
            pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)]
            motion-reduce:transition-none"
    {{--
        PIN digits accumulate in this transient Alpine state,
        never in a Livewire property — no per-keypress round-trips, and the
        PIN never enters the wire:snapshot attribute or update responses. The
        full PIN crosses the wire exactly once as $wire.submit(pin); the local
        copy is cleared before the call resolves.
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

        {{-- PIN pad (includes dot display + flash) --}}
        @include('auth::livewire.partials.pin-pad')

        {{-- OS-gated unlock (Touch ID / enclave). Unlike the WebAuthn button
             below it returns the data key itself, so it unlocks outright. --}}
        @if ($nativeUnlockAvailable)
            <button
                type="button"
                wire:click="nativeUnlock()"
                class="w-full flex items-center justify-center gap-2 rounded-xl bg-slate-900 dark:bg-slate-100
                       py-3 text-sm font-semibold text-white dark:text-slate-900
                       hover:bg-slate-700 dark:hover:bg-slate-200
                       focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100 focus-visible:ring-offset-2
                       transition-colors duration-[80ms] motion-reduce:transition-none"
            >
                {{ $biometricLabel }}
            </button>
        @endif

        {{-- Biometric button (only shown when a credential is enrolled — 05-05) --}}
        @if ($biometricAvailable)
            <button
                type="button"
                wire:click="biometricPrompt()"
                class="w-full flex items-center justify-center gap-2 rounded-xl border border-slate-200 dark:border-slate-700
                       py-3 text-sm font-medium text-slate-700 dark:text-slate-300
                       hover:bg-slate-50 dark:hover:bg-slate-900
                       focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100 focus-visible:ring-offset-2
                       transition-colors duration-[80ms] motion-reduce:transition-none"
            >
                {{ $biometricLabel }}
            </button>
        @endif

        {{-- Sign out. Forgot-PIN recovery: sign out →
             password login (primes the session) → Settings →
             "Forgot your PIN?" resets it via the password recovery wrap. --}}
        <form method="POST" action="{{ route('logout') }}" x-data x-on:submit.prevent="beatraxSubmitPostForm($el, $event.submitter)">
            @csrf
            <button
                type="submit"
                class="w-full text-center text-sm text-rose-600 dark:text-rose-400
                       hover:text-rose-700 dark:hover:text-rose-300
                       focus:outline-none focus-visible:underline
                       py-2"
            >
                {{ Lang::get('auth::lock_screen.sign_out') }}
            </button>
        </form>

    </div>
</div>
