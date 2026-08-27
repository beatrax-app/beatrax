@use('Modules\Core\Public\Support\Lang')
@use('Modules\Mobile\Internal\Http\PairingEntryUrl')
@use('Modules\Mobile\Internal\Identity\ImportBootstrapStep')
{{-- Anchored to the top rather than centred: ten recovery codes are taller
     than a phone viewport, and centring pushed the heading up off the top of
     it, clipping the one instruction on the screen. --}}
{{-- The system bars are painted over this screen, not beside it: the button
     that ends each step sat under the Android navigation bar, tappable only
     in its upper half. The insets come from the --safe-* seam in app.css,
     never a bare env() — Android leaves that at zero and pads by nothing. --}}
{{-- .safe-screen, because a first run reaches every step of this page in the
     markup layouts.app produced for a signed-out reader — the account is made
     mid-flow, but Livewire re-renders this component and never the layout, so
     no .top-bar ever appears. A bar's height was standing in for the status
     bar here and is neither the same measurement nor on the same screen. --}}
<div class="safe-screen min-h-screen flex items-start justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-md mx-auto px-6 space-y-6 pb-10 pt-6">

        {{-- ===== Step: collect_pin ===== --}}
        @if ($bootstrapStep === ImportBootstrapStep::CollectPin && ! $alreadyProvisioned)
            {{-- Only on this step: nothing has been created yet, so leaving is
                 free. Past it the device holds an identity, and the screens
                 that follow lead forward on purpose. --}}
            <p class="text-sm">
                <a
                    href="{{ route('mobile.welcome') }}"
                    class="tap-link inline-flex items-center gap-1 text-slate-500 underline-offset-2 hover:underline dark:text-slate-400"
                    wire:navigate
                >
                    <span aria-hidden="true">←</span>
                    {{ Lang::get('core::components.topbar.back') }}
                </a>
            </p>

            <header class="space-y-1">
                <x-core::page-heading level="section">{{ Lang::get('mobile::import.heading') }}</x-core::page-heading>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('mobile::import.subtitle') }}
                </p>
            </header>

            <form wire:submit="submit" class="space-y-4">
                <x-core::form-field
                    :label="Lang::get('mobile::import.username')"
                    name="username"
                    wire:model="username"
                    autocomplete="username"
                    autofocus
                />

                {{-- The live checklist below describes this field better than the
                     hint line does, and a named descriptor replaces the hint id
                     rather than joining it. The error id is appended either way. --}}
                <x-core::form-field
                    :label="Lang::get('mobile::import.password')"
                    name="password"
                    type="password"
                    :hint="Lang::get('mobile::import.password_help')"
                    wire:model="password"
                    autocomplete="new-password"
                    aria-describedby="password-requirements"
                />

                {{-- field-id keeps the control's existing id: the wire property
                     is camelCase and the id on the page is hyphenated, and a
                     password manager that remembered this field remembers the id. --}}
                <x-core::form-field
                    :label="Lang::get('mobile::import.confirm_password')"
                    name="passwordConfirmation"
                    field-id="password-confirmation"
                    type="password"
                    wire:model="passwordConfirmation"
                    autocomplete="new-password"
                />

                {{-- Between the password pair and the PIN, because the one
                     message this form used to show was the password rule
                     printed under the PIN's own "6-10 digits" hint. --}}
                <x-core::password-requirements
                    :aria-label="Lang::get('mobile::import.requirements_aria')"
                    :length-label="Lang::get('mobile::import.req_length')"
                    :match-label="Lang::get('mobile::import.req_match')"
                    :met="Lang::get('mobile::import.req_met')"
                    :unmet="Lang::get('mobile::import.req_unmet')"
                />

                <x-core::form-field
                    :label="Lang::get('mobile::import.pin')"
                    name="pin"
                    type="password"
                    :hint="Lang::get('mobile::import.pin_help')"
                    inputmode="numeric"
                    wire:model="pin"
                    autocomplete="off"
                />

                <x-core::form-field
                    :label="Lang::get('mobile::import.confirm_pin')"
                    name="confirmPin"
                    field-id="confirm-pin"
                    type="password"
                    inputmode="numeric"
                    wire:model="confirmPin"
                    autocomplete="off"
                />

                {{-- Below the credentials, not above them: those five boxes are
                     one group and the checklist inside it describes the pair it
                     sits under. The placeholder stays choosable, because naming
                     no country is a real answer on this screen too. --}}
                <x-core::form-field
                    field-id="import-country"
                    name="country"
                    type="select"
                    :label="Lang::get('core::settings.country.label')"
                    :hint="Lang::get('core::settings.country.help')"
                    wire:model="country"
                >
                    <x-core::country-options :options="$countryOptions" :selected="$country" />
                </x-core::form-field>

                @if ($flashMessage !== '')
                    <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
                @endif

                <button
                    type="submit"
                    class="w-full min-h-[44px] bg-emerald-700 hover:bg-emerald-800 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:hover:bg-emerald-800 dark:bg-emerald-700"
                >
                    {{ Lang::get('mobile::import.continue') }}
                </button>
            </form>
        @endif

        {{-- ===== Step: provisioning_failed ===== --}}
        @if ($bootstrapStep === ImportBootstrapStep::ProvisioningFailed)
            <div class="space-y-3 text-center">
                <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::import.failed_heading') }}</h1>
                <p class="mx-auto max-w-xs text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('mobile::import.failed_body') }}
                </p>

                @if ($flashMessage !== '')
                    <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
                @endif

                <x-core::neutral-button
                    block="full"
                    class="min-h-[44px]"
                    wire:click="retryProvisioning"
                >
                    {{ Lang::get('mobile::import.try_again') }}
                </x-core::neutral-button>
            </div>
        @endif

        {{-- Already registered on this device: the signup form below would
         fail on a duplicate account, and a back navigation used to land here
         showing it as though nothing had happened. --}}
    @if ($alreadyProvisioned && $bootstrapStep === ImportBootstrapStep::CollectPin)
        <div class="space-y-4" data-testid="import-already-provisioned">
            <x-core::page-heading>{{ Lang::get('mobile::import.already_heading') }}</x-core::page-heading>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('mobile::import.already_body') }}</p>

            <a
                href="{{ PairingEntryUrl::importing() }}"
                class="flex w-full min-h-[44px] items-center justify-center rounded-md bg-emerald-700 py-2 text-sm font-medium text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:bg-emerald-700 dark:hover:bg-emerald-800"
            >{{ Lang::get('mobile::import.continue_to_pairing') }}</a>
        </div>
    @endif

    {{-- ===== Step: recovery_codes ===== --}}
        @if ($bootstrapStep === ImportBootstrapStep::RecoveryCodes)
            <div
                class="space-y-6"
                x-data="{
                    ...copyToClipboard(@js($codes).join('\n')),
                    confirmed: false,
                    saved: false,
                    saveFailed: false,
                    codes: @js($codes),
                    async save() {
                        this.saveFailed = false;

                        // On a phone the OS share sheet is the only route that
                        // works: the Android webview drops a blob download
                        // without an error, a console entry or a file, and the
                        // screen used to report success regardless.
                        if (@js($nativeExport)) {
                            try {
                                const response = await fetch(@js($exportUrl), { headers: { 'Accept': 'application/json' } });
                                const result = await response.json();

                                // No success line here: the share sheet is
                                // the OS telling the user, and claiming it
                                // went to downloads would be untrue — they
                                // have not chosen where it goes yet.
                                this.saveFailed = result.saved !== true;
                            } catch (e) {
                                this.saveFailed = true;
                            }

                            return;
                        }

                        const url = URL.createObjectURL(new Blob([this.codes.join('\n')], { type: 'text/plain' }));
                        const link = document.createElement('a');
                        link.href = url;
                        link.download = @js($downloadFilename);
                        document.body.appendChild(link);
                        link.click();
                        link.remove();
                        setTimeout(() => URL.revokeObjectURL(url), 1000);
                        this.saved = true;
                    },
                }"
            >
                <header class="space-y-1">
                    <x-core::page-heading>{{ Lang::get('mobile::import.recovery_heading') }}</x-core::page-heading>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('mobile::import.recovery_body') }}</p>
                </header>

                {{-- Denser than the desktop card: a 24-character code at text-lg
                     with wide tracking wrapped to three lines, so ten of them
                     pushed Save and Continue below the fold on a phone. --}}
                <div aria-live="polite" class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($codes as $code)
                        <div class="bg-slate-50 border border-slate-200 rounded-md px-2 py-2 text-sm sm:text-xs font-semibold font-mono tabular-nums tracking-tight text-slate-900 break-all dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700" style="font-variant-numeric: tabular-nums;">
                            {{ $code }}
                        </div>
                    @endforeach
                </div>

                {{-- Save/Copy are pure client-side: these codes are shown once
                     and a Livewire round-trip here can 419, which would take
                     them with it. A Blob download needs no server at all. --}}
                <div class="space-y-2">
                    <div class="flex flex-col gap-2">
                        <button
                            type="button"
                            x-on:click="save()"
                            data-testid="mobile-recovery-download"
                            class="min-h-[44px] rounded-md bg-slate-100 px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                        >{{ Lang::get('mobile::import.recovery_download') }}</button>

                        <button
                            type="button"
                            x-on:click="copy()"
                            class="min-h-[44px] rounded-md bg-slate-100 px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                            x-text="copied ? @js(Lang::get('mobile::import.recovery_copied')) : @js(Lang::get('mobile::import.recovery_copy'))"
                        >{{ Lang::get('mobile::import.recovery_copy') }}</button>
                    </div>

                    <p x-show="saved" x-cloak aria-live="polite" class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('mobile::import.recovery_saved') }}</p>
                    <p x-show="failed" x-cloak role="alert" aria-live="assertive" class="text-sm text-rose-600 dark:text-rose-400">{{ Lang::get('mobile::import.recovery_copy_failed') }}</p>
                    <p x-show="saveFailed" x-cloak role="alert" aria-live="assertive" class="text-sm text-rose-600 dark:text-rose-400">{{ Lang::get('mobile::import.recovery_save_failed') }}</p>
                </div>

                <x-core::checkbox-field
                    align="start"
                    :label="Lang::get('mobile::import.recovery_confirm')"
                    x-model="confirmed"
                />

                {{-- A plain link, not wire:click. The Livewire round-trip this
                     replaced returned 419 on device, and the reload that
                     followed re-mounted the component at step one — landing
                     the user back on signup with the codes already gone. A GET
                     carries no CSRF token, so it cannot fail that way. --}}
                <a
                    href="{{ $pairingUrl }}"
                    x-bind:aria-disabled="confirmed ? 'false' : 'true'"
                    x-bind:tabindex="confirmed ? '0' : '-1'"
                    x-on:click="if (! confirmed) { $event.preventDefault(); }"
                    :class="confirmed
                        ? 'bg-emerald-700 hover:bg-emerald-800 dark:bg-emerald-700 dark:hover:bg-emerald-800'
                        : 'bg-emerald-700/50 cursor-not-allowed dark:bg-emerald-700/40'"
                    class="flex w-full min-h-[44px] items-center justify-center rounded-md py-2 text-sm font-medium text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:focus-visible:ring-emerald-500"
                >
                    {{ Lang::get('mobile::import.continue_to_pairing') }}
                </a>
            </div>
        @endif

    </div>
</div>
