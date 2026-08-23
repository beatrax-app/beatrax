@use('Modules\Core\Public\Support\Lang')
{{-- OAuth-client-registration wizard modal.

     Renders the Google variant when $provider === 'gmail' and the
     Microsoft 365 variant when $provider === 'microsoft'. The user
     pastes their per-install OAuth client_id + client_secret obtained
     from Google Cloud Console or the Microsoft Entra admin center; on
     submit the credentials are written atomically to the chmod-600
     JSON repository and the user is redirected directly into the
     per-inbox consent flow.

     The Google variant carries six numbered steps + a mandatory
     publishedConfirmed checkbox; the Microsoft variant carries six
     numbered steps and no extra checkbox (Azure has no equivalent
     "push to production" step for personal accounts). --}}

<div>
    @if ($provider === null)
        {{-- The modal mounts globally in app layouts before the user has
             picked a provider. We must NOT render the flux:modal with a
             trailing-hyphen "oauth-client-wizard-" name on initial render
             — Flux UI registers modals at mount time by name, so a later
             change to the name (oauth-client-wizard-gmail) after the
             open() listener fires never matches the registered modal and
             modal-show silently no-ops. Skipping the render entirely
             keeps the registration deferred until $provider is set. --}}
    @else
    {{-- wire:key forces Livewire's morph to fully unmount + remount the
         modal element when $provider changes (e.g. user clicked Gmail
         then Microsoft). Without it, the same DOM element survives
         re-render with just the `name` attribute swapping, but Flux's
         Alpine factory caches the modal name from x-data init time —
         so a later modal-show with the new name silently misses the
         registered modal. Re-mounting forces fluxModal('...') to
         re-init with the new name. --}}
    <flux:modal wire:key="oauth-modal-{{ $provider }}" name="oauth-client-wizard-{{ $provider }}" class="md:max-w-2xl" :dismissible="false">
        <div class="space-y-6">
            <header>
                @if ($provider === \Modules\EmailScan\Public\Enums\MailProvider::Gmail->value)
                    <flux:heading size="lg">{{ Lang::get('email-scan::wizard.gmail_title') }}</flux:heading>
                @else
                    <flux:heading size="lg">{{ Lang::get('email-scan::wizard.microsoft_title') }}</flux:heading>
                @endif
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('email-scan::wizard.intro') }}
                </p>
            </header>

            @if ($provider === \Modules\EmailScan\Public\Enums\MailProvider::Gmail->value)
                <ol class="space-y-6">
                    <li class="flex items-start gap-4">
                        <x-email-scan::step-number lead>1</x-email-scan::step-number>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::wizard.gmail.step1_title') }}</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('email-scan::wizard.gmail.step1_body') }}</p>
                            <x-core::secondary-button
                                href="https://console.cloud.google.com/"
                                size="sm"
                                class="gap-1"
                                target="_blank"
                                rel="noopener"
                            >{{ Lang::get('email-scan::wizard.gmail.step1_link') }}</x-core::secondary-button>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <x-email-scan::step-number>2</x-email-scan::step-number>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::wizard.gmail.step2_title') }}</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('email-scan::wizard.gmail.step2_body') }}</p>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <x-email-scan::step-number>3</x-email-scan::step-number>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::wizard.gmail.step3_title') }}</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('email-scan::wizard.gmail.step3_body') }}</p>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <x-email-scan::step-number>4</x-email-scan::step-number>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::wizard.gmail.step4_title') }}</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('email-scan::wizard.gmail.step4_body') }}</p>
                            <x-core::checkbox-field
                                class="pt-1"
                                :label="Lang::get('email-scan::wizard.gmail.step4_checkbox')"
                                wire:model.live="publishedConfirmed"
                            />
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <x-email-scan::step-number>5</x-email-scan::step-number>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::wizard.gmail.step5_title') }}</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('email-scan::wizard.gmail.step5_body') }}</p>
                            {{-- The URI is one unbreakable token, and a shrink-to-fit button
                                 takes its min-content width as a floor. Inside a
                                 <dialog> sized to its content that floor became the
                                 dialog's width: 441px on a 411px phone, with every
                                 line of body text clipped and the close button half
                                 off-screen. --}}
                            <x-core::secondary-button
                                size="sm"
                                class="gap-1 font-mono max-w-full"
                                x-data
                                x-on:click="(async () => { const label = $el.querySelector('span'); const was = label.textContent; if (await window.beatraxCopy(was)) { label.textContent = '{{ Lang::get('email-scan::wizard.copied') }}'; setTimeout(() => label.textContent = '{{ $redirectUri }}', 2000); } })()"
                            ><span class="min-w-0 break-all">{{ $redirectUri }}</span></x-core::secondary-button>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <x-email-scan::step-number>6</x-email-scan::step-number>
                        <div class="space-y-3 flex-1">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::wizard.gmail.step6_title') }}</p>
                            <div class="space-y-3">
                                <x-core::form-field
                                    field-id="gmail-client-id"
                                    name="clientId"
                                    :label="Lang::get('email-scan::wizard.gmail.client_id_label')"
                                    wire:model.live="clientId"
                                    placeholder="123456789-abcdef.apps.googleusercontent.com"
                                />
                                <x-core::form-field
                                    field-id="gmail-client-secret"
                                    name="clientSecret"
                                    type="password"
                                    :label="Lang::get('email-scan::wizard.gmail.client_secret_label')"
                                    :hint="Lang::get('email-scan::wizard.secret_help')"
                                    wire:model.blur="clientSecret"
                                    placeholder="GOCSPX-..."
                                />
                            </div>
                        </div>
                    </li>
                </ol>
            @elseif ($provider === \Modules\EmailScan\Public\Enums\MailProvider::Microsoft->value)
                <ol class="space-y-6">
                    <li class="flex items-start gap-4">
                        <x-email-scan::step-number lead>1</x-email-scan::step-number>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::wizard.microsoft.step1_title') }}</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('email-scan::wizard.microsoft.step1_body') }}</p>
                            <x-core::secondary-button
                                href="https://entra.microsoft.com/"
                                size="sm"
                                class="gap-1"
                                target="_blank"
                                rel="noopener"
                            >{{ Lang::get('email-scan::wizard.microsoft.step1_link') }}</x-core::secondary-button>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <x-email-scan::step-number>2</x-email-scan::step-number>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::wizard.microsoft.step2_title') }}</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('email-scan::wizard.microsoft.step2_body') }}</p>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <x-email-scan::step-number>3</x-email-scan::step-number>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::wizard.microsoft.step3_title') }}</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('email-scan::wizard.microsoft.step3_body') }}</p>
                            <x-core::secondary-button
                                size="sm"
                                class="gap-1 font-mono max-w-full"
                                x-data
                                x-on:click="(async () => { const label = $el.querySelector('span'); const was = label.textContent; if (await window.beatraxCopy(was)) { label.textContent = '{{ Lang::get('email-scan::wizard.copied') }}'; setTimeout(() => label.textContent = '{{ $redirectUri }}', 2000); } })()"
                            ><span class="min-w-0 break-all">{{ $redirectUri }}</span></x-core::secondary-button>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <x-email-scan::step-number>4</x-email-scan::step-number>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::wizard.microsoft.step4_title') }}</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('email-scan::wizard.microsoft.step4_body') }}</p>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <x-email-scan::step-number>5</x-email-scan::step-number>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::wizard.microsoft.step5_title') }}</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('email-scan::wizard.microsoft.step5_body') }}</p>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <x-email-scan::step-number>6</x-email-scan::step-number>
                        <div class="space-y-3 flex-1">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('email-scan::wizard.microsoft.step6_title') }}</p>
                            <div class="space-y-3">
                                <x-core::form-field
                                    field-id="microsoft-client-id"
                                    name="clientId"
                                    :label="Lang::get('email-scan::wizard.microsoft.client_id_label')"
                                    wire:model.live="clientId"
                                    placeholder="12345678-1234-1234-1234-123456789abc"
                                />
                                <x-core::form-field
                                    field-id="microsoft-client-secret"
                                    name="clientSecret"
                                    type="password"
                                    :label="Lang::get('email-scan::wizard.microsoft.client_secret_label')"
                                    :hint="Lang::get('email-scan::wizard.secret_help')"
                                    wire:model.blur="clientSecret"
                                    placeholder="..."
                                />
                            </div>
                        </div>
                    </li>
                </ol>
            @endif

            @if ($errorMessage !== '')
                <p class="text-xs text-rose-600 dark:text-rose-500">{{ $errorMessage }}</p>
            @endif

            <footer class="flex items-center justify-end gap-3">
                <x-core::secondary-button wire:click="cancel">{{ Lang::get('email-scan::wizard.cancel') }}</x-core::secondary-button>
                <button
                    type="button"
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-emerald-500 dark:hover:bg-emerald-400"
                >{{ Lang::get('email-scan::wizard.save_connect') }}</button>
            </footer>
        </div>
    </flux:modal>
    @endif
</div>
