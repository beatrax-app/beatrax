<div
    class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950
            px-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)]
            pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)]
            motion-reduce:transition-none"
    x-data="{}"
    x-on:keydown.window="
        const k = $event.key;
        if (k >= '0' && k <= '9') {
            $wire.pressDigit(k);
        } else if (k === 'Backspace') {
            $wire.backspace();
        } else if (k === 'Enter') {
            $wire.submit();
        }
    "
>
    <div class="w-full max-w-sm px-6 space-y-6">

        {{-- App mark --}}
        <div class="flex justify-center">
            <img
                src="/icon.png"
                width="48"
                height="48"
                alt="beatrax"
                class="rounded-xl"
                aria-hidden="true"
            />
        </div>

        {{-- PIN pad (includes dot display + flash) --}}
        @include('auth::livewire.partials.pin-pad')

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

        {{-- Sign out (D-03). Forgot-PIN recovery (D-11/D-21): sign out →
             password login (primes the session, WR-03) → Settings →
             "Forgot your PIN?" resets it via the password recovery wrap. --}}
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button
                type="submit"
                class="w-full text-center text-sm text-rose-600 dark:text-rose-400
                       hover:text-rose-700 dark:hover:text-rose-300
                       focus:outline-none focus-visible:underline
                       py-2"
            >
                Sign out
            </button>
        </form>

    </div>
</div>
