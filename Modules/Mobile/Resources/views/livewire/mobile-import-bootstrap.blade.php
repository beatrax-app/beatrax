<div class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-md mx-auto px-6 space-y-6">

        {{-- ===== Step: collect_pin ===== --}}
        @if ($step === 'collect_pin')
            <header class="space-y-1">
                <h1 class="text-3xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">Import from another device</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Set up this phone with its own account and lock, then pair it with your other device to pull in your history.
                </p>
            </header>

            <form wire:submit="submit" class="space-y-4">
                <div class="space-y-1">
                    <label for="username" class="block text-sm text-slate-900 dark:text-slate-100">Username</label>
                    <input
                        type="text"
                        id="username"
                        wire:model="username"
                        autocomplete="username"
                        autofocus
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                    />
                </div>

                <div class="space-y-1">
                    <label for="password" class="block text-sm text-slate-900 dark:text-slate-100">Password</label>
                    <input
                        type="password"
                        id="password"
                        wire:model="password"
                        autocomplete="new-password"
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                    />
                    <p class="text-xs text-slate-500 dark:text-slate-400">At least 12 characters — there is no password reset, only recovery codes.</p>
                </div>

                <div class="space-y-1">
                    <label for="password-confirmation" class="block text-sm text-slate-900 dark:text-slate-100">Confirm password</label>
                    <input
                        type="password"
                        id="password-confirmation"
                        wire:model="passwordConfirmation"
                        autocomplete="new-password"
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                    />
                </div>

                <div class="space-y-1">
                    <label for="pin" class="block text-sm text-slate-900 dark:text-slate-100">App-lock PIN</label>
                    <input
                        type="password"
                        inputmode="numeric"
                        id="pin"
                        wire:model="pin"
                        autocomplete="off"
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                    />
                    <p class="text-xs text-slate-500 dark:text-slate-400">4-10 digits — unlocks this device.</p>
                </div>

                <div class="space-y-1">
                    <label for="confirm-pin" class="block text-sm text-slate-900 dark:text-slate-100">Confirm PIN</label>
                    <input
                        type="password"
                        inputmode="numeric"
                        id="confirm-pin"
                        wire:model="confirmPin"
                        autocomplete="off"
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                    />
                </div>

                @if ($flashMessage !== '')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $flashMessage }}</p>
                @endif

                <button
                    type="submit"
                    class="w-full min-h-[44px] bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
                >
                    Continue
                </button>
            </form>
        @endif

        {{-- ===== Step: provisioning_failed ===== --}}
        @if ($step === 'provisioning_failed')
            <div class="space-y-3 text-center">
                <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Setup didn't finish</h1>
                <p class="mx-auto max-w-xs text-sm text-slate-500 dark:text-slate-400">
                    Your account was created, but we couldn't finish setting up this device. You can safely try again.
                </p>

                @if ($flashMessage !== '')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $flashMessage }}</p>
                @endif

                <button
                    type="button"
                    wire:click="retryProvisioning"
                    class="w-full min-h-[44px] rounded-md bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white
                           hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                           dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
                >
                    Try again
                </button>
            </div>
        @endif

        {{-- ===== Step: recovery_codes ===== --}}
        @if ($step === 'recovery_codes')
            <div
                class="space-y-6"
                x-data="{ confirmed: false }"
            >
                <header class="space-y-1">
                    <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">Save these recovery codes</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Print these or save them somewhere safe. They will not be shown again.</p>
                </header>

                <div role="region" aria-live="polite" class="grid grid-cols-2 gap-3">
                    @foreach ($codes as $code)
                        <div class="bg-slate-50 border border-slate-200 rounded-md px-4 py-3 text-lg font-semibold font-mono tabular-nums tracking-wider text-slate-900 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700" style="font-variant-numeric: tabular-nums;">
                            {{ $code }}
                        </div>
                    @endforeach
                </div>

                <label class="flex items-start gap-2">
                    <input type="checkbox" x-model="confirmed" class="mt-1 rounded border-slate-300 dark:border-slate-600">
                    <span class="text-sm text-slate-700 dark:text-slate-300">I have saved these codes somewhere safe.</span>
                </label>

                <button
                    type="button"
                    wire:click="continueToPairing"
                    x-bind:disabled="! confirmed"
                    x-bind:aria-disabled="confirmed ? 'false' : 'true'"
                    :class="confirmed
                        ? 'bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-500 dark:hover:bg-emerald-400'
                        : 'bg-emerald-600/50 cursor-not-allowed dark:bg-emerald-500/40'"
                    class="w-full min-h-[44px] rounded-md py-2 text-sm font-medium text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:focus-visible:ring-emerald-500"
                >
                    Continue to pairing
                </button>
            </div>
        @endif

    </div>
</div>
