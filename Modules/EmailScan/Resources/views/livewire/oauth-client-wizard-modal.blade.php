{{-- OAuth-client-registration wizard modal (D-114).

     First production non-flyout flux:modal in the codebase. The user
     pastes their per-install OAuth client_id + client_secret obtained
     from Google Cloud Console; on submit the credentials are written
     atomically to the chmod-600 JSON repository and the user is
     redirected directly into the per-inbox consent flow.

     All copy is locked verbatim against 06-UI-SPEC.md §
     OAuth-client wizard modal. --}}

<div>
    <flux:modal name="oauth-client-wizard-{{ $provider }}" class="md:max-w-2xl" dismissible="false">
        <div class="space-y-6">
            <header>
                @if ($provider === 'gmail')
                    <flux:heading size="lg">Set up your Gmail OAuth client</flux:heading>
                @else
                    <flux:heading size="lg">Set up your Microsoft 365 OAuth client</flux:heading>
                @endif
                <p class="mt-2 text-sm text-slate-500">
                    diederik uses your own Google Cloud project / Azure app registration so your credentials never touch a shared server. This is a one-time setup per provider.
                </p>
            </header>

            @if ($provider === 'gmail')
                <ol class="space-y-6">
                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white">1</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900">Open Google Cloud Console</p>
                            <p class="text-sm text-slate-500">Open the Google Cloud Console in a new tab. Sign in with the Google account you want to scan, then create a new project (or select an existing personal project).</p>
                            <a
                                href="https://console.cloud.google.com/"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                            >Open Google Cloud Console</a>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500">2</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900">Enable the Gmail API</p>
                            <p class="text-sm text-slate-500">In the new project, search for "Gmail API" in the API Library and click Enable. This grants the project the ability to call Gmail on your behalf.</p>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500">3</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900">Configure the OAuth consent screen</p>
                            <p class="text-sm text-slate-500">Open APIs & Services → OAuth consent screen. Choose User type "External", enter "diederik" as the app name, and your own email as the support contact and developer contact. Add the scope https://www.googleapis.com/auth/gmail.readonly. Click Save and continue, then Back to Dashboard.</p>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500">4</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900">Push the consent screen to "In production"</p>
                            <p class="text-sm text-slate-500">On the OAuth consent screen page, click Publish App and confirm. This is required — without it, the refresh tokens diederik receives expire after 7 days. Publishing requires no Google review when the only user is you.</p>
                            <label class="flex items-center gap-2 pt-1">
                                <input
                                    type="checkbox"
                                    wire:model.live="publishedConfirmed"
                                    class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600"
                                >
                                <span class="text-sm text-slate-900">I've published the OAuth consent screen to In production</span>
                            </label>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500">5</span>
                        <div class="space-y-2">
                            <p class="text-sm font-semibold text-slate-900">Create the OAuth Client ID</p>
                            <p class="text-sm text-slate-500">Open Credentials → Create Credentials → OAuth Client ID. Choose application type "Web application". Set name "diederik". Under "Authorized redirect URIs" paste the URI below exactly.</p>
                            <button
                                type="button"
                                x-data
                                x-on:click="navigator.clipboard.writeText($el.querySelector('span').textContent); $el.querySelector('span').textContent = 'Copied'; setTimeout(() => $el.querySelector('span').textContent = '{{ $redirectUri }}', 2000);"
                                class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-mono text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900"
                            ><span>{{ $redirectUri }}</span></button>
                        </div>
                    </li>

                    <li class="flex items-start gap-4">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500">6</span>
                        <div class="space-y-3 flex-1">
                            <p class="text-sm font-semibold text-slate-900">Paste your client ID and client secret</p>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 mb-1">Client ID</label>
                                    <input
                                        type="text"
                                        wire:model.live="clientId"
                                        placeholder="123456789-abcdef.apps.googleusercontent.com"
                                        class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900"
                                    >
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 mb-1">Client secret</label>
                                    <input
                                        type="password"
                                        wire:model.live="clientSecret"
                                        placeholder="GOCSPX-..."
                                        class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 focus-visible:ring-2 focus-visible:ring-slate-900"
                                    >
                                    <p class="mt-1 text-xs text-slate-500">These are stored in a local config file outside the database with restrictive permissions and never leave your machine.</p>
                                </div>
                            </div>
                        </div>
                    </li>
                </ol>
            @else
                <p class="text-sm text-slate-500">Microsoft setup is available in the next plan.</p>
            @endif

            @if ($errorMessage !== '')
                <p class="text-xs text-rose-600">{{ $errorMessage }}</p>
            @endif

            <footer class="flex items-center justify-end gap-3">
                <button
                    type="button"
                    wire:click="cancel"
                    class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                >Cancel</button>
                <button
                    type="button"
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                >Save and connect</button>
            </footer>
        </div>
    </flux:modal>
</div>
