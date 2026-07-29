<div class="mx-auto max-w-md px-8 py-12 space-y-12">
    <header class="space-y-1">
        <h1 class="text-3xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">Manage {{ $partnerUsername }}</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">View, reset, or regenerate codes for this user.</p>
    </header>

    @if ($flashMessage !== '')
        <p class="text-sm text-slate-700 dark:text-slate-300">{{ $flashMessage }}</p>
    @endif

    <section class="space-y-2" x-data="{ open: false }">
        <h2 class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Set new password for this user</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">Their next sign-in will ask them to choose a password.</p>

        <button
            type="button"
            x-on:click="open = true"
            class="rounded-md bg-slate-100 px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-100"
        >
            Set new password for this user
        </button>

        <div x-show="open" x-cloak class="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700">
            <p id="set-password-body" class="text-sm text-slate-700 dark:text-slate-300">Set a new password for {{ $partnerUsername }}. Their next sign-in will ask them to choose a password.</p>

            <div class="space-y-1">
                <label for="new-partner-password" class="block text-sm text-slate-900 dark:text-slate-100">New password</label>
                <input
                    type="password"
                    id="new-partner-password"
                    wire:model="newPartnerPassword"
                    autocomplete="new-password"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
                />
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    wire:click="setPartnerPassword"
                    x-on:click="open = false"
                    aria-describedby="set-password-body"
                    class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
                >
                    Set password
                </button>
                <button
                    type="button"
                    x-on:click="open = false"
                    class="rounded-md px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:text-slate-100 dark:text-slate-400"
                >
                    Cancel
                </button>
            </div>
        </div>
    </section>

    <section class="space-y-2" x-data="{ open: false, typed: '' }">
        <h2 class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Regenerate recovery codes for this user</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">Old codes will be invalidated.</p>

        <button
            type="button"
            x-on:click="open = true"
            class="rounded-md bg-slate-100 px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-100"
        >
            Regenerate recovery codes for this user
        </button>

        <div x-show="open" x-cloak class="space-y-3 rounded-md border border-rose-200 bg-rose-50 p-4 dark:bg-rose-950">
            <p id="regenerate-body" class="text-sm text-rose-900 dark:text-rose-200">Their existing unused codes will stop working. You will see the 10 new codes once and can hand them off.</p>

            <div class="space-y-1">
                <label for="confirm-username" class="block text-sm text-slate-900 dark:text-slate-100">Type the username to continue</label>
                <input
                    type="text"
                    id="confirm-username"
                    x-model="typed"
                    autocomplete="off"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
                />
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    wire:click="regenerateCodes"
                    x-on:click="open = false"
                    x-bind:disabled="typed !== @js($partnerUsername)"
                    aria-describedby="regenerate-body"
                    class="rounded-md bg-rose-600 px-3 py-2 text-sm font-medium text-white hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-rose-600/50 dark:hover:bg-rose-400 dark:bg-rose-500"
                >
                    Regenerate codes
                </button>
                <button
                    type="button"
                    x-on:click="open = false"
                    class="rounded-md px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:text-slate-100 dark:text-slate-400"
                >
                    Keep current codes
                </button>
            </div>
        </div>

        @if ($regeneratedCodes !== [])
            <div class="space-y-3 pt-2">
                <div aria-live="polite" class="grid grid-cols-2 gap-3">
                    @foreach ($regeneratedCodes as $code)
                        <div class="bg-slate-50 border border-slate-200 rounded-md px-4 py-3 text-lg font-semibold font-mono tabular-nums tracking-wider text-slate-900 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700" style="font-variant-numeric: tabular-nums;">
                            {{ $code }}
                        </div>
                    @endforeach
                </div>

                <a
                    href="data:text/plain;charset=utf-8,{{ rawurlencode(implode("\n", $regeneratedCodes)) }}"
                    download="beatrax-recovery-codes-{{ $partnerUsername }}.txt"
                    class="inline-block rounded-md bg-slate-100 px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-100"
                >
                    Download as .txt
                </a>
            </div>
        @endif
    </section>
</div>
