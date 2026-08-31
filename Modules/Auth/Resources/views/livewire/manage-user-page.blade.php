@use('Modules\Core\Public\Support\Lang')
<div class="mx-auto max-w-md px-4 py-6 space-y-12 sm:px-8">
    <x-core::page-header
        :title="Lang::get('auth::manage_user.heading', ['name' => $partnerUsername])"
        :subtitle="Lang::get('auth::manage_user.subtitle')"
    />

    @if ($flashMessage !== '')
        <p class="text-sm text-slate-700 dark:text-slate-300">{{ $flashMessage }}</p>
    @endif

    <section class="space-y-2" x-data="{ open: false }">
        <h2 class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('auth::manage_user.set_password.heading') }}</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('auth::manage_user.set_password.description') }}</p>

        <button
            type="button"
            x-on:click="open = true"
            class="rounded-md bg-slate-100 px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-100"
        >
            {{ Lang::get('auth::manage_user.set_password.open') }}
        </button>

        <div x-show="open" x-cloak class="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700">
            <p id="set-password-body" class="text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('auth::manage_user.set_password.body', ['name' => $partnerUsername]) }}</p>

            <x-core::form-field
                field-id="new-partner-password"
                name="newPartnerPassword"
                type="password"
                :label="Lang::get('auth::manage_user.set_password.label')"
                wire:model="newPartnerPassword"
                autocomplete="new-password"
            />

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    wire:click="setPartnerPassword"
                    x-on:click="open = false"
                    aria-describedby="set-password-body"
                    class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:hover:bg-emerald-800 dark:bg-emerald-700"
                >
                    {{ Lang::get('auth::manage_user.set_password.submit') }}
                </button>
                <button
                    type="button"
                    x-on:click="open = false"
                    class="rounded-md px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:text-slate-100 dark:text-slate-400"
                >
                    {{ Lang::get('auth::manage_user.set_password.cancel') }}
                </button>
            </div>
        </div>
    </section>

    <section class="space-y-2" x-data="{ open: false, typed: '' }">
        <h2 class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('auth::manage_user.regenerate.heading') }}</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('auth::manage_user.regenerate.description') }}</p>

        <button
            type="button"
            x-on:click="open = true"
            class="rounded-md bg-slate-100 px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-100"
        >
            {{ Lang::get('auth::manage_user.regenerate.open') }}
        </button>

        <x-core::alert tone="danger" class="space-y-3" x-show="open" x-cloak>
            <p id="regenerate-body" class="text-sm text-rose-900 dark:text-rose-200">{{ Lang::get('auth::manage_user.regenerate.body') }}</p>

            {{-- x-model, not wire:model: the typed name never leaves the
                 browser, it only unlocks the button next to it. --}}
            <x-core::form-field
                field-id="confirm-username"
                name="confirmUsername"
                :label="Lang::get('auth::manage_user.regenerate.confirm_label')"
                x-model="typed"
                autocomplete="off"
            />

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    wire:click="regenerateCodes"
                    x-on:click="open = false"
                    x-bind:disabled="typed !== @js($partnerUsername)"
                    aria-describedby="regenerate-body"
                    class="rounded-md bg-rose-600 px-3 py-2 text-sm font-medium text-white hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-rose-600/50 dark:hover:bg-rose-400 dark:bg-rose-500"
                >
                    {{ Lang::get('auth::manage_user.regenerate.submit') }}
                </button>
                <button
                    type="button"
                    x-on:click="open = false"
                    class="rounded-md px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:text-slate-100 dark:text-slate-400"
                >
                    {{ Lang::get('auth::manage_user.regenerate.keep') }}
                </button>
            </div>
        </x-core::alert>

        @if ($regeneratedCodes !== [])
            <div class="space-y-3 pt-2">
                <div aria-live="polite" class="grid grid-cols-1 gap-2 sm:grid-cols-2 sm:gap-3">
                    @foreach ($regeneratedCodes as $code)
                        <div class="bg-slate-50 border border-slate-200 rounded-md px-2 py-2 sm:px-4 sm:py-3 text-sm sm:text-lg font-semibold font-mono tabular-nums tracking-tight sm:tracking-wider text-slate-900 break-all dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700" style="font-variant-numeric: tabular-nums;">
                            {{ $code }}
                        </div>
                    @endforeach
                </div>

                {{-- A data: URL on an <a download> is dropped by a shell whose
                     WebView has no download listener, with no file and no
                     error. There the same codes leave through the OS share
                     sheet, which needs a round-trip rather than a link. --}}
                @if ($nativeExport)
                    <button
                        type="button"
                        wire:click="downloadCodes"
                        class="inline-block rounded-md bg-slate-100 px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-100"
                    >
                        {{ Lang::get('auth::manage_user.regenerate.download') }}
                    </button>
                @else
                    <a
                        href="data:text/plain;charset=utf-8,{{ rawurlencode(implode("\n", $regeneratedCodes)) }}"
                        download="beatrax-recovery-codes-{{ $partnerUsername }}.txt"
                        class="tap-chip inline-block rounded-md bg-slate-100 px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-100"
                    >
                        {{ Lang::get('auth::manage_user.regenerate.download') }}
                    </a>
                @endif
            </div>
        @endif
    </section>
</div>
