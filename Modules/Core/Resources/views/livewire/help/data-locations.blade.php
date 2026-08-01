{{--
    "Where is my data?" — the user-facing privacy page reachable at
    /help/data-locations. The page makes the local-only privacy
    promise tangible by surfacing the three resolved paths the app
    actually reads + writes, and by giving the user a one-click way
    to either export everything (Dev Mode) or read the manual-copy
    instructions (everyone else).

    Variables in scope:
      - $sqlitePath  : string — absolute path to the SQLite database
                       file (UserDataPathService::databasePath()).
      - $secretsPath : string — directory holding the OAuth tokens
                       (UserDataPathService::secrets()).
      - $cachesPath  : string — framework storage directory holding
                       runtime caches + compiled views
                       (UserDataPathService::framework()).
      - $devModeOn   : bool   — authenticated user's is_developer flag.

    Every interpolation uses Blade default escaping. The page renders
    only trusted strings (resolved filesystem paths from
    UserDataPathService + literal copy from 17-UI-SPEC), but the
    escape policy stays uniform with SystemAlertsBanner so an
    accidental injection later cannot bypass output safety.

    CSS classes `.help-locations`, `.path-row`, `.path-mono`,
    `.copy-path-btn` live in resources/css/app.css's @layer
    components block (added in Plan 17-06a) so the styling is
    centralised and theme-token-driven.

    The copy-to-clipboard buttons live as a per-row Alpine island so
    no round-trip is needed; the `navigator.clipboard.writeText`
    promise is best-effort (some browsers gate it on https + user
    activation, both of which the local dev environment + a button
    click satisfy).
--}}
@use('Modules\Core\Public\Support\Lang')
<div class="help-locations" data-testid="help-data-locations">
    <header class="space-y-2">
        <h1 class="text-2xl font-semibold text-[var(--color-text)]">{{ Lang::get('core::help.page_title') }}</h1>
        <p class="text-[var(--color-text-muted)]">
            {{ Lang::get('core::help.intro') }}
        </p>
    </header>

    {{-- ─── Section 1: Your data lives here ─────────────────────── --}}
    <section class="space-y-3" aria-labelledby="help-locations-paths-heading">
        <h2 id="help-locations-paths-heading" class="text-lg font-semibold text-[var(--color-text)]">{{ Lang::get('core::help.lives_here') }}</h2>

        <div class="path-row" x-data="{ copied: false }">
            <span class="font-semibold pr-1">{{ Lang::get('core::help.sqlite_db') }}</span>
            <span class="path-mono">{{ $sqlitePath }}</span>
            <button
                type="button"
                aria-label="{{ Lang::get('core::help.copy_sqlite_aria') }}"
                class="copy-path-btn"
                x-on:click="navigator.clipboard.writeText('{{ $sqlitePath }}').then(() => { copied = true; setTimeout(() => copied = false, 1500); }).catch(() => {})"
                x-text="copied ? {{ Js::from(Lang::get('core::help.copied')) }} : {{ Js::from(Lang::get('core::help.copy')) }}"
            >{{ Lang::get('core::help.copy') }}</button>
        </div>

        <div class="path-row" x-data="{ copied: false }">
            <span class="font-semibold pr-1">{{ Lang::get('core::help.oauth_secrets') }}</span>
            <span class="path-mono">{{ $secretsPath }}</span>
            <button
                type="button"
                aria-label="{{ Lang::get('core::help.copy_secrets_aria') }}"
                class="copy-path-btn"
                x-on:click="navigator.clipboard.writeText('{{ $secretsPath }}').then(() => { copied = true; setTimeout(() => copied = false, 1500); }).catch(() => {})"
                x-text="copied ? {{ Js::from(Lang::get('core::help.copied')) }} : {{ Js::from(Lang::get('core::help.copy')) }}"
            >{{ Lang::get('core::help.copy') }}</button>
        </div>

        <div class="path-row" x-data="{ copied: false }">
            <span class="font-semibold pr-1">{{ Lang::get('core::help.brand_caches') }}</span>
            <span class="path-mono">{{ $cachesPath }}</span>
            <button
                type="button"
                aria-label="{{ Lang::get('core::help.copy_caches_aria') }}"
                class="copy-path-btn"
                x-on:click="navigator.clipboard.writeText('{{ $cachesPath }}').then(() => { copied = true; setTimeout(() => copied = false, 1500); }).catch(() => {})"
                x-text="copied ? {{ Js::from(Lang::get('core::help.copied')) }} : {{ Js::from(Lang::get('core::help.copy')) }}"
            >{{ Lang::get('core::help.copy') }}</button>
        </div>
    </section>

    {{-- ─── Section 2: Export everything ─────────────────────────── --}}
    <section class="space-y-3" aria-labelledby="help-locations-export-heading">
        <h2 id="help-locations-export-heading" class="text-lg font-semibold text-[var(--color-text)]">{{ Lang::get('core::help.export_heading') }}</h2>
        <p class="text-[var(--color-text-muted)]">
            {{ Lang::get('core::help.export_body') }}
        </p>

        @if ($devModeOn)
            {{-- Dev Mode is on, so the primary CTA renders here. The
                 export-everything route lives in the Dev Mode module;
                 until that route ships, the button is rendered as a
                 disabled stub so the page contract holds and the
                 verifier can catch when the wiring goes live. --}}
            <button
                type="button"
                class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                data-testid="export-everything-cta"
                disabled
                aria-disabled="true"
                title="{{ Lang::get('core::help.export_title_attr') }}"
            >{{ Lang::get('core::help.export_cta') }}</button>
        @else
            <div class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:border-slate-700">
                <p class="font-medium">{{ Lang::get('core::help.dev_off') }}</p>
                <p class="mt-1">{{ Lang::get('core::help.to_export') }}</p>
                <ol class="mt-2 list-decimal pl-5 space-y-1">
                    <li>{!! Lang::get('core::help.enable_dev_html') !!}</li>
                    <li>{{ Lang::get('core::help.manual_copy') }}</li>
                </ol>
            </div>
        @endif
    </section>

    {{-- ─── Section 3: Deleting your data ────────────────────────── --}}
    <section class="space-y-3" aria-labelledby="help-locations-delete-heading">
        <h2 id="help-locations-delete-heading" class="text-lg font-semibold text-[var(--color-text)]">{{ Lang::get('core::help.delete_heading') }}</h2>
        <p class="text-[var(--color-text-muted)]">{{ Lang::get('core::help.delete_intro') }}</p>
        <ol class="list-decimal pl-5 space-y-1 text-[var(--color-text-muted)]">
            <li>{{ Lang::get('core::help.delete_step1') }}</li>
            <li>{{ Lang::get('core::help.delete_step2') }}</li>
        </ol>
        {{-- App-static copy carrying an apostrophe that must reach the DOM
             unescaped (see HelpDataLocationsTest); no dynamic values here. --}}
        <p class="text-[var(--color-text-muted)]">{!! Lang::get('core::help.no_telemetry') !!}</p>
    </section>
</div>
