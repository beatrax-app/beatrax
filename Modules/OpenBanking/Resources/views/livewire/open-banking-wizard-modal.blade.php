@use('Modules\Core\Public\Support\Lang')
{{-- Open Banking connect wizard modal (19-06, UI-SPEC Surface B3).

     Numbered-step chrome cloned from EmailScan's
     oauth-client-wizard-modal.blade.php: circled step badges, mono
     copy-to-clipboard chips for secrets-adjacent values, footer
     [Cancel] [primary action] layout. Unlike that component (a static
     instructional list), this wizard genuinely advances through
     $step server-side — each step's action performs real work
     (keypair generation, a disk write, the consent hand-off) so only
     the current step's content and controls render. --}}

<div>
    <flux:modal wire:key="open-banking-wizard" name="open-banking-wizard" class="md:max-w-2xl" :dismissible="false">
        <div class="space-y-6">
            <header>
                <flux:heading size="lg">{{ Lang::get('openbanking::messages.wizard.heading') }}</flux:heading>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('openbanking::messages.wizard.intro') }}
                </p>
            </header>

            {{-- Step progress: 5 circled badges, current + completed steps
                 dark, upcoming steps muted. --}}
            <ol class="flex items-center gap-2">
                @for ($n = 1; $n <= 5; $n++)
                    <li
                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold
                            {{ $step >= $n
                                ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900'
                                : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}"
                    >{{ $n }}</li>
                @endfor
            </ol>

            @if ($step === 1)
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('openbanking::messages.wizard.step1_title') }}</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.wizard.step1_body') }}</p>

                    @if ($publicKeyPem === '')
                        <button
                            type="button"
                            wire:click="generateKeypair"
                            wire:loading.attr="disabled"
                            class="inline-flex min-h-[44px] items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-emerald-500 dark:hover:bg-emerald-400"
                        >{{ Lang::get('openbanking::messages.wizard.generate_keypair') }}</button>
                    @else
                        <div class="space-y-2">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400" for="ob-public-key">{{ Lang::get('openbanking::messages.wizard.public_key_label') }}</label>
                            <textarea
                                id="ob-public-key"
                                readonly
                                class="block w-full rounded-md border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                                rows="6"
                                onclick="this.select()"
                            >{{ $publicKeyPem }}</textarea>
                            <x-core::secondary-button
                                size="sm"
                                class="gap-1"
                                x-data
                                aria-label="{{ Lang::get('openbanking::messages.wizard.copy_public_key') }}"
                                x-on:click="(async () => { const label = $el.querySelector('span'); const was = label.textContent; if (await window.beatraxCopy(document.getElementById('ob-public-key').value)) { label.textContent = '{{ Lang::get('openbanking::messages.wizard.copied') }}'; setTimeout(() => label.textContent = was, 2000); } })()"
                            ><span>{{ Lang::get('openbanking::messages.wizard.copy_public_key') }}</span></x-core::secondary-button>

                            {{-- Not a label: what follows is a copy button, not a
                                 form control, so there is nothing for a label to
                                 associate with. --}}
                            <p class="block text-xs font-medium text-slate-500 mt-3 dark:text-slate-400">{{ Lang::get('openbanking::messages.wizard.redirect_uri_label') }}</p>
                            {{-- The redirect URI is carried on a
                                 data-* attribute (HTML-escaped by Blade) and
                                 read via $el.dataset — never string-baked into
                                 the inline JS handler. --}}
                            <x-core::secondary-button
                                size="sm"
                                class="gap-1 font-mono"
                                x-data
                                data-redirect-uri="{{ $redirectUri }}"
                                aria-label="{{ Lang::get('openbanking::messages.wizard.copy_redirect_uri') }}"
                                x-on:click="(async () => { const label = $el.querySelector('span'); const was = label.textContent; if (await window.beatraxCopy(was)) { label.textContent = '{{ Lang::get('openbanking::messages.wizard.copied') }}'; setTimeout(() => label.textContent = $el.dataset.redirectUri, 2000); } })()"
                            ><span>{{ $redirectUri }}</span></x-core::secondary-button>
                        </div>
                    @endif
                </div>
            @elseif ($step === 2)
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('openbanking::messages.wizard.step2_title') }}</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.wizard.step2_body') }}</p>
                    <x-core::secondary-button
                        href="https://enablebanking.com/cp/applications"
                        size="sm"
                        class="gap-1"
                        target="_blank"
                        rel="noopener"
                    >{{ Lang::get('openbanking::messages.wizard.open_portal') }}</x-core::secondary-button>
                </div>
            @elseif ($step === 3)
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('openbanking::messages.wizard.step3_title') }}</p>
                    {{-- The aria-label that used to sit on this input repeated
                         the visible label word for word, and aria-label wins,
                         so the <label for> was announced to nobody. One name
                         now, carried by the association. --}}
                    <x-core::form-field
                        :label="Lang::get('openbanking::messages.wizard.application_id_label')"
                        :hint="Lang::get('openbanking::messages.wizard.step3_help')"
                        name="applicationId"
                        field-id="ob-application-id"
                        wire:model.live="applicationId"
                        class="font-mono"
                    />
                </div>
            @elseif ($step === 4)
                {{-- Without a shared name= the browser never treated these three
                     as one group: arrow keys did not move between them, and two
                     could sit checked until the Livewire round trip corrected it.
                     The step title was a <p> floating above them, so the group
                     had no accessible name either. --}}
                <fieldset class="space-y-3">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('openbanking::messages.wizard.step4_title') }}</legend>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex cursor-pointer flex-col gap-1 rounded-md border border-slate-300 p-3 has-[:checked]:border-slate-900 dark:border-slate-700 dark:has-[:checked]:border-slate-100">
                            <span class="flex items-center gap-2">
                                <input type="radio" name="bankChoice" wire:click="chooseBank('asn')" @checked($bankChoice === 'asn') class="h-4 w-4 border-slate-300 text-emerald-600 focus:ring-emerald-600 dark:border-slate-700">
                                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">ASN Bank</span>
                            </span>
                            <span class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.wizard.via_enable_banking') }}</span>
                        </label>
                        <label class="flex cursor-pointer flex-col gap-1 rounded-md border border-slate-300 p-3 has-[:checked]:border-slate-900 dark:border-slate-700 dark:has-[:checked]:border-slate-100">
                            <span class="flex items-center gap-2">
                                <input type="radio" name="bankChoice" wire:click="chooseBank('sns')" @checked($bankChoice === 'sns') class="h-4 w-4 border-slate-300 text-emerald-600 focus:ring-emerald-600 dark:border-slate-700">
                                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">SNS (de Volksbank)</span>
                            </span>
                            <span class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.wizard.via_enable_banking') }}</span>
                        </label>
                    </div>
                    <label class="flex items-center gap-2 pt-1">
                        <input type="radio" name="bankChoice" wire:click="chooseBank('other')" @checked($bankChoice === 'other') class="h-4 w-4 border-slate-300 text-emerald-600 focus:ring-emerald-600 dark:border-slate-700">
                        <span class="text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('openbanking::messages.wizard.other_institution') }}</span>
                    </label>
                    @if ($bankChoice === 'other')
                        <input
                            type="text"
                            wire:model.live="otherInstitutionId"
                            placeholder="{{ Lang::get('openbanking::messages.wizard.institution_id_placeholder') }}"
                            aria-label="{{ Lang::get('openbanking::messages.wizard.institution_id_placeholder') }}"
                            class="block w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                        >
                    @endif
                </fieldset>
            @elseif ($step === 5)
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('openbanking::messages.wizard.step5_title') }}</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.wizard.step5_body') }}</p>
                </div>
            @endif

            @if ($errorMessage !== '')
                <p class="text-xs text-rose-600 dark:text-rose-500">{{ $errorMessage }}</p>
            @endif

            <footer class="flex items-center justify-end gap-3">
                <x-core::secondary-button
                    class="min-h-[44px]"
                    wire:click="cancel"
                >{{ Lang::get('openbanking::messages.wizard.cancel') }}</x-core::secondary-button>

                @if ($step === 2)
                    <x-core::neutral-button
                        class="min-h-[44px]"
                        wire:click="continueToApplicationId"
                    >{{ Lang::get('openbanking::messages.wizard.continue') }}</x-core::neutral-button>
                @elseif ($step === 3)
                    <x-core::neutral-button
                        class="min-h-[44px] disabled:opacity-50 disabled:cursor-not-allowed"
                        :disabled="trim($applicationId) === ''"
                        wire:click="saveApplicationId"
                        wire:loading.attr="disabled"
                    >{{ Lang::get('openbanking::messages.wizard.continue') }}</x-core::neutral-button>
                @elseif ($step === 4)
                    <x-core::neutral-button
                        class="min-h-[44px] disabled:opacity-50 disabled:cursor-not-allowed"
                        :disabled="$bankChoice === '' || ($bankChoice === 'other' && trim($otherInstitutionId) === '')"
                        wire:click="continueToConsent"
                    >{{ Lang::get('openbanking::messages.wizard.continue') }}</x-core::neutral-button>
                @elseif ($step === 5)
                    {{-- Same-tab redirect (no target="_blank") so the
                         callback re-mounts THIS page and the return-flash
                         enable fires. The href fallback (middle/cmd-click or
                         no-JS) carries institution_id, which the connect
                         controller requires. --}}
                    <x-core::neutral-button
                        :href="route('oauth.open-banking.connect', $consentInstitutionId !== null ? ['institution_id' => $consentInstitutionId] : [])"
                        class="min-h-[44px]"
                        wire:click.prevent="connect"
                    >{{ Lang::get('openbanking::messages.wizard.continue_to_bank', ['bank' => $bankName]) }}</x-core::neutral-button>
                @endif
            </footer>
        </div>
    </flux:modal>
</div>
