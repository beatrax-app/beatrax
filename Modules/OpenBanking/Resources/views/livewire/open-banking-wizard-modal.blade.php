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
    <flux:modal wire:key="open-banking-wizard" name="open-banking-wizard" class="md:max-w-2xl" dismissible="false">
        <div class="space-y-6">
            <header>
                <flux:heading size="lg">Connect your bank</flux:heading>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    beatrax uses your own Enable Banking application so your credentials never touch a shared server. This is a one-time setup per bank.
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
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Generate your local key pair</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">beatrax generates an RSA key pair on this computer. The private key never leaves your machine.</p>

                    @if ($publicKeyPem === '')
                        <button
                            type="button"
                            wire:click="generateKeypair"
                            wire:loading.attr="disabled"
                            class="inline-flex min-h-[44px] items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-emerald-500 dark:hover:bg-emerald-400"
                        >Generate keypair</button>
                    @else
                        <div class="space-y-2">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400" for="ob-public-key">Public key</label>
                            <textarea
                                id="ob-public-key"
                                readonly
                                class="block w-full rounded-md border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                                rows="6"
                                onclick="this.select()"
                            >{{ $publicKeyPem }}</textarea>
                            <button
                                type="button"
                                x-data
                                aria-label="Copy public key"
                                x-on:click="navigator.clipboard.writeText(document.getElementById('ob-public-key').value); $el.querySelector('span').textContent = 'Copied'; setTimeout(() => $el.querySelector('span').textContent = 'Copy public key', 2000);"
                                class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-900"
                            ><span>Copy public key</span></button>

                            {{-- Not a label: what follows is a copy button, not a
                                 form control, so there is nothing for a label to
                                 associate with. --}}
                            <p class="block text-xs font-medium text-slate-500 mt-3 dark:text-slate-400">Redirect URI</p>
                            {{-- IN-04: the redirect URI is carried on a
                                 data-* attribute (HTML-escaped by Blade) and
                                 read via $el.dataset — never string-baked into
                                 the inline JS handler. --}}
                            <button
                                type="button"
                                x-data
                                data-redirect-uri="{{ $redirectUri }}"
                                aria-label="Copy redirect URI"
                                x-on:click="navigator.clipboard.writeText($el.querySelector('span').textContent); $el.querySelector('span').textContent = 'Copied'; setTimeout(() => $el.querySelector('span').textContent = $el.dataset.redirectUri, 2000);"
                                class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-mono text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-900"
                            ><span>{{ $redirectUri }}</span></button>
                        </div>
                    @endif
                </div>
            @elseif ($step === 2)
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Register the application in Enable Banking</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Open the Enable Banking developer portal, create an application, and paste in the public key and redirect URI from step 1.</p>
                    <a
                        href="https://enablebanking.com/cp/applications"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-900"
                    >Open Enable Banking portal ↗</a>
                </div>
            @elseif ($step === 3)
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Paste your application ID</p>
                    <label class="block text-xs font-medium text-slate-500 mb-1 dark:text-slate-400" for="ob-application-id">Application ID</label>
                    <input
                        id="ob-application-id"
                        type="text"
                        wire:model.live="applicationId"
                        aria-label="Application ID"
                        class="block w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                    >
                    <p class="text-xs text-slate-500 dark:text-slate-400">This is stored in a local file outside the database with restrictive permissions and never leaves your machine.</p>
                </div>
            @elseif ($step === 4)
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Choose your bank</p>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex cursor-pointer flex-col gap-1 rounded-md border border-slate-300 p-3 has-[:checked]:border-slate-900 dark:border-slate-700 dark:has-[:checked]:border-slate-100">
                            <span class="flex items-center gap-2">
                                <input type="radio" wire:click="chooseBank('asn')" @checked($bankChoice === 'asn') class="h-4 w-4 border-slate-300 text-emerald-600 focus:ring-emerald-600 dark:border-slate-700">
                                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">ASN Bank</span>
                            </span>
                            <span class="text-xs text-slate-500 dark:text-slate-400">via Enable Banking</span>
                        </label>
                        <label class="flex cursor-pointer flex-col gap-1 rounded-md border border-slate-300 p-3 has-[:checked]:border-slate-900 dark:border-slate-700 dark:has-[:checked]:border-slate-100">
                            <span class="flex items-center gap-2">
                                <input type="radio" wire:click="chooseBank('sns')" @checked($bankChoice === 'sns') class="h-4 w-4 border-slate-300 text-emerald-600 focus:ring-emerald-600 dark:border-slate-700">
                                <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">SNS (de Volksbank)</span>
                            </span>
                            <span class="text-xs text-slate-500 dark:text-slate-400">via Enable Banking</span>
                        </label>
                    </div>
                    <label class="flex items-center gap-2 pt-1">
                        <input type="radio" wire:click="chooseBank('other')" @checked($bankChoice === 'other') class="h-4 w-4 border-slate-300 text-emerald-600 focus:ring-emerald-600 dark:border-slate-700">
                        <span class="text-sm text-slate-900 dark:text-slate-100">Other institution</span>
                    </label>
                    @if ($bankChoice === 'other')
                        <input
                            type="text"
                            wire:model.live="otherInstitutionId"
                            placeholder="Institution id"
                            aria-label="Institution id"
                            class="block w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                        >
                    @endif
                </div>
            @elseif ($step === 5)
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Complete consent in your browser</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Click below to open your bank's login and consent screen. Complete the login and any 2-factor step, then you'll be brought back here automatically to finish enabling Open Banking.</p>
                </div>
            @endif

            @if ($errorMessage !== '')
                <p class="text-xs text-rose-600 dark:text-rose-500">{{ $errorMessage }}</p>
            @endif

            <footer class="flex items-center justify-end gap-3">
                <button
                    type="button"
                    wire:click="cancel"
                    class="inline-flex min-h-[44px] items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-900"
                >Cancel</button>

                @if ($step === 2)
                    <button
                        type="button"
                        wire:click="continueToApplicationId"
                        class="inline-flex min-h-[44px] items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                    >Continue →</button>
                @elseif ($step === 3)
                    <button
                        type="button"
                        wire:click="saveApplicationId"
                        wire:loading.attr="disabled"
                        @disabled(trim($applicationId) === '')
                        class="inline-flex min-h-[44px] items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                    >Continue →</button>
                @elseif ($step === 4)
                    <button
                        type="button"
                        wire:click="continueToConsent"
                        @disabled($bankChoice === '' || ($bankChoice === 'other' && trim($otherInstitutionId) === ''))
                        class="inline-flex min-h-[44px] items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                    >Continue →</button>
                @elseif ($step === 5)
                    {{-- WR-10: same-tab redirect (no target="_blank") so the
                         callback re-mounts THIS page and the return-flash
                         enable fires. The href fallback (middle/cmd-click or
                         no-JS) carries institution_id, which the connect
                         controller requires. --}}
                    <a
                        href="{{ route('oauth.open-banking.connect', $consentInstitutionId !== null ? ['institution_id' => $consentInstitutionId] : []) }}"
                        wire:click.prevent="connect"
                        class="inline-flex min-h-[44px] items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                    >Continue to {{ $bankName }} →</a>
                @endif
            </footer>
        </div>
    </flux:modal>
</div>
