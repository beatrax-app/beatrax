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
    <flux:modal wire:key="oauth-modal-{{ $provider }}" name="oauth-client-wizard-{{ $provider }}" class="md:max-w-2xl" dismissible="false">
        <div class="space-y-6">
            <header>
                @if ($provider === \Modules\EmailScan\Public\Enums\MailProvider::Gmail->value)
                    <flux:heading size="lg">Set up your Gmail OAuth client</flux:heading>
                @else
                    <flux:heading size="lg">Set up your Microsoft 365 OAuth client</flux:heading>
                @endif
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    beatrax uses your own Google Cloud project / Azure app registration so your credentials never touch a shared server. This is a one-time setup per provider.
                </p>
            </header>

            @if ($provider === \Modules\EmailScan\Public\Enums\MailProvider::Gmail->value)
                <ol class="space-y-6">
                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white dark:bg-slate-100 dark:text-slate-900">1</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Open Google Cloud Console</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Open the Google Cloud Console in a new tab. Sign in with the Google account you want to scan, then create a new project (or select an existing personal project).</p>
                            <a
                                href="https://console.cloud.google.com/"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-900"
                            >Open Google Cloud Console</a>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">2</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Enable the Gmail API</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">In the new project, search for "Gmail API" in the API Library and click Enable. This grants the project the ability to call Gmail on your behalf.</p>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">3</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Configure the OAuth consent screen</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Open APIs & Services → OAuth consent screen. Choose User type "External", enter "beatrax" as the app name, and your own email as the support contact and developer contact. Add the scope https://www.googleapis.com/auth/gmail.readonly. Click Save and continue, then Back to Dashboard.</p>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">4</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Push the consent screen to "In production"</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">On the OAuth consent screen page, click Publish App and confirm. This is required — without it, the refresh tokens beatrax receives expire after 7 days. Publishing requires no Google review when the only user is you.</p>
                            <label class="flex items-center gap-2 pt-1">
                                <input
                                    type="checkbox"
                                    wire:model.live="publishedConfirmed"
                                    class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600 dark:border-slate-700 dark:bg-slate-900 dark:text-emerald-500"
                                >
                                <span class="text-sm text-slate-900 dark:text-slate-100">I've published the OAuth consent screen to In production</span>
                            </label>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">5</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Create the OAuth Client ID</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Open Credentials → Create Credentials → OAuth Client ID. Choose application type "Web application". Set name "beatrax". Under "Authorized redirect URIs" paste the URI below exactly.</p>
                            <button
                                type="button"
                                x-data
                                x-on:click="navigator.clipboard.writeText($el.querySelector('span').textContent); $el.querySelector('span').textContent = 'Copied'; setTimeout(() => $el.querySelector('span').textContent = '{{ $redirectUri }}', 2000);"
                                class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-mono text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-900"
                            ><span>{{ $redirectUri }}</span></button>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">6</span>
                        <div class="space-y-3 flex-1">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Paste your client ID and client secret</p>
                            <div class="space-y-3">
                                <div>
                                    <label for="gmail-client-id" class="block text-xs font-medium text-slate-500 mb-1 dark:text-slate-400">Client ID</label>
                                    <input
                                        id="gmail-client-id"
                                        type="text"
                                        wire:model.live="clientId"
                                        placeholder="123456789-abcdef.apps.googleusercontent.com"
                                        class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                                    >
                                </div>
                                <div>
                                    <label for="gmail-client-secret" class="block text-xs font-medium text-slate-500 mb-1 dark:text-slate-400">Client secret</label>
                                    <input
                                        id="gmail-client-secret"
                                        type="password"
                                        wire:model.blur="clientSecret"
                                        placeholder="GOCSPX-..."
                                        class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                                    >
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">These are stored in a local config file outside the database with restrictive permissions and never leave your machine.</p>
                                </div>
                            </div>
                        </div>
                    </li>
                </ol>
            @elseif ($provider === \Modules\EmailScan\Public\Enums\MailProvider::Microsoft->value)
                <ol class="space-y-6">
                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white dark:bg-slate-100 dark:text-slate-900">1</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Open Azure Portal</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Open the Microsoft Entra admin center in a new tab. Sign in with the Microsoft account you want to scan.</p>
                            <a
                                href="https://entra.microsoft.com/"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-900"
                            >Open Azure Portal</a>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">2</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Register a new application</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Open App registrations → New registration. Name it "beatrax". Under "Supported account types" choose "Accounts in any organizational directory and personal Microsoft accounts" (this lets you connect personal Outlook.com and work Microsoft 365 inboxes with the same app).</p>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">3</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Add the redirect URI</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">In the same registration form, under "Redirect URI", choose platform "Web" and paste the URI below exactly.</p>
                            <button
                                type="button"
                                x-data
                                x-on:click="navigator.clipboard.writeText($el.querySelector('span').textContent); $el.querySelector('span').textContent = 'Copied'; setTimeout(() => $el.querySelector('span').textContent = '{{ $redirectUri }}', 2000);"
                                class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-mono text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-900"
                            ><span>{{ $redirectUri }}</span></button>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">4</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Grant Mail.Read permission</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Open API permissions → Add a permission → Microsoft Graph → Delegated permissions. Select Mail.Read and offline_access. Click Add permissions. You do not need to grant admin consent for a personal account.</p>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">5</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Create a client secret</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Open Certificates & secrets → New client secret. Set description "beatrax" and an expiry of 24 months. Copy the secret value immediately — Azure shows it only once.</p>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">6</span>
                        <div class="space-y-3 flex-1">
                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Paste your application (client) ID and secret</p>
                            <div class="space-y-3">
                                <div>
                                    <label for="microsoft-client-id" class="block text-xs font-medium text-slate-500 mb-1 dark:text-slate-400">Application (client) ID</label>
                                    <input
                                        id="microsoft-client-id"
                                        type="text"
                                        wire:model.live="clientId"
                                        placeholder="12345678-1234-1234-1234-123456789abc"
                                        class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                                    >
                                </div>
                                <div>
                                    <label for="microsoft-client-secret" class="block text-xs font-medium text-slate-500 mb-1 dark:text-slate-400">Client secret value</label>
                                    <input
                                        id="microsoft-client-secret"
                                        type="password"
                                        wire:model.blur="clientSecret"
                                        placeholder="..."
                                        class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                                    >
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">These are stored in a local config file outside the database with restrictive permissions and never leave your machine.</p>
                                </div>
                            </div>
                        </div>
                    </li>
                </ol>
            @endif

            @if ($errorMessage !== '')
                <p class="text-xs text-rose-600 dark:text-rose-500">{{ $errorMessage }}</p>
            @endif

            <footer class="flex items-center justify-end gap-3">
                <button
                    type="button"
                    wire:click="cancel"
                    class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-900"
                >Cancel</button>
                <button
                    type="button"
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-emerald-500 dark:hover:bg-emerald-400"
                >Save and connect</button>
            </footer>
        </div>
    </flux:modal>
    @endif
</div>
