@use('Modules\Core\Public\Support\Lang')
@php
    /**
     * @var string $currentPath
     * @var string $username
     * @var string $userInitial
     * @var bool $isDeveloper
     * @var string $accountCaption
     * @var int $queueCount
     * @var int|null $workerSecondsAgo
     * @var int $unknownCount  Live count from CounterpartyTriageQueue; badge stays hidden when 0.
     *
     * Active-state helper — mirrors TopNav's `$isActive` lambda
     * (pre-rewrite analog) so route-driven highlighting reads from
     * the controller-provided `$currentPath` rather than a global
     * helper.
     */
    $isActive = static fn (string $path): string => $currentPath === $path ? 'active' : '';
    $unknownCounterpartyCount = $unknownCount;
@endphp

<aside class="side" aria-label="{{ Lang::get('core::sidebar.primary_nav') }}" style="--side-w: 248px;">
    <div class="side-brand">
        <img src="{{ Vite::asset('resources/brand/logo.svg') }}" alt="Beatrax" width="24" height="24" class="logo logo-svg" />
        <span>Beatrax</span>
        {{--
            Version chip reads from `config/nativephp.php#version`, which
            is the single source of truth for it. The `v` prefix is hard-coded since the config holds
            the bare SemVer; the chip stays the only place that prepends
            it so a future migration to a leading-zero scheme touches one
            line instead of every release artefact.
        --}}
        <span class="version-chip">v{{ config('nativephp.version') }}</span>
    </div>

    {{-- Search affordance. Click dispatches palette:open. --}}
    <div
        class="side-search"
        role="search"
        x-on:click="window.Livewire && window.Livewire.dispatch('palette:open')"
        style="cursor: pointer;"
    >
        <span class="ic" aria-hidden="true">⌕</span>
        <input
            type="text"
            placeholder="{{ Lang::get('core::sidebar.search_placeholder') }}"
            aria-label="{{ Lang::get('core::sidebar.search_aria') }}"
            readonly
            x-on:focus="window.Livewire && window.Livewire.dispatch('palette:open')"
            style="cursor: pointer;"
        />
        {{--
            Platform-aware kbd hint.
            On macOS: renders the Mac modifier key symbol (U+2318) followed by K.
            On Windows/Linux: renders Ctrl+K.
            On touch devices: hidden entirely via .hidden-touch (pointer:coarse).

            The Mac glyph is written as the JS string escape backslash-u2318
            directly in the Alpine expression — no PHP interpolation at all — so
            the raw U+2318 character never appears in the server-rendered HTML,
            satisfying the AppSidebarKbdTest `not->toContain('⌘K')` assertion.
            Do NOT swap this for Js::from(): Laravel 13's Js::from uses
            JSON_UNESCAPED_UNICODE and emits the raw glyph. Alpine evaluates the
            x-text expression client-side; SSR fallback text is Ctrl+K.
        --}}
        <span
            class="kbd hidden-touch max-lg:hidden"
            aria-hidden="true"
            x-text="$store.platform.isMac ? '\u2318K' : 'Ctrl+K'"
        >Ctrl+K</span>
    </div>

    <div class="side-section-label">{{ Lang::get('core::sidebar.section_overview') }}</div>
    @php
        // Cached per-user nav counts (NavCountsService). Compact-format large
        // counts so a four-digit badge never stretches the rail.
        $navCounts = $navCounts ?? [];
        $navCount = static function (string $key) use ($navCounts): string {
            $n = (int) ($navCounts[$key] ?? 0);

            return $n >= 1000 ? round($n / 1000, 1).'k' : (string) $n;
        };
    @endphp

    <a href="{{ route('dashboard') }}" class="side-item {{ $isActive('/') }}">
        <span class="ic" aria-hidden="true">◆</span>
        {{ Lang::get('core::sidebar.nav.dashboard') }}
    </a>
    <a href="{{ route('transactions.index') }}" class="side-item {{ $isActive('/transactions') }}">
        <span class="ic" aria-hidden="true">≡</span>
        {{ Lang::get('core::sidebar.nav.transactions') }}
        @if (($navCounts['transactions'] ?? 0) > 0)
            {{-- role="img" on every count badge in this sidebar: a bare <span>
                 is a generic role, which takes no accessible name, so the
                 aria-label saying what the number counts was dropped unread. --}}
            <span role="img" class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.transactions', ['count' => $navCounts['transactions']]) }}">{{ $navCount('transactions') }}</span>
        @endif
    </a>
    {{-- Forecasts — .side-badge.alert, not the default fill: a projected
         shortfall window is a problem state the user cannot clear by working
         through a queue, which is the same reason Drift Alerts and Unusual
         charges carry the rose variant. Count merged into navCounts by the
         Forecasting nav-badge composer; hidden at zero. --}}
    <a href="{{ route('forecast.index') }}" class="side-item {{ $isActive('/forecast') }}">
        <span class="ic" aria-hidden="true">↗</span>
        {{ Lang::get('core::sidebar.nav.forecasts') }}
        @if (($navCounts['forecast'] ?? 0) > 0)
            <span role="img" class="side-badge alert" aria-label="{{ Lang::get('core::sidebar.badge.forecast', ['count' => $navCounts['forecast']]) }}">{{ $navCount('forecast') }}</span>
        @endif
    </a>
    <a href="{{ route('calendar.index') }}" class="side-item {{ $isActive('/calendar') }}">
        <span class="ic" aria-hidden="true">▦</span>
        {{ Lang::get('core::sidebar.nav.calendar') }}
    </a>
    {{-- Notifications — the unified inbox. It sits in
         Overview rather than beside the alert surfaces: an inbox is a thing
         you check, like the dashboard, not a category of money. Default
         .side-badge (NOT .side-badge.alert) — an unread count is an
         actionable-count-to-clear, not a problem state, per
         component-library.md's badge-intensity taxonomy. --}}
    <a href="{{ route('notifications.index') }}" class="side-item {{ $isActive('/notifications') }}">
        <span class="ic" aria-hidden="true">◈</span>
        {{ Lang::get('core::sidebar.nav.notifications') }}
        @if (($navCounts['notifications'] ?? 0) > 0)
            <span role="img" class="side-badge" aria-label="{{ Lang::get('core::sidebar.badge.notifications', ['count' => $navCounts['notifications']]) }}">{{ $navCount('notifications') }}</span>
        @endif
    </a>

    {{-- Commitments: the money-leak pillar. Everything here answers "what is
         charging me on repeat, and has it changed?" — the app's reason for
         existing, and previously scattered through a fourteen-item MONEY
         section that had become a dumping ground.

         Named for the obligations rather than for their frequency, so the
         heading does not simply repeat the name of the first item under it. --}}
    <div class="side-section-label">{{ Lang::get('core::sidebar.section_recurring') }}</div>
    <a href="{{ route('recurring.index') }}" class="side-item {{ $isActive('/recurring') }}">
        <span class="ic" aria-hidden="true">↻</span>
        {{ Lang::get('core::sidebar.nav.recurring') }}
        @if (($navCounts['recurring'] ?? 0) > 0)
            <span role="img" class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.recurring', ['count' => $navCounts['recurring']]) }}">{{ $navCount('recurring') }}</span>
        @endif
    </a>
    <a href="{{ route('drift.watch') }}" class="side-item {{ $isActive('/drift/watch') }}">
        <span class="ic" aria-hidden="true">↗</span>
        {{ Lang::get('core::sidebar.nav.subscriptions') }}
        @if (($navCounts['subscriptions'] ?? 0) > 0)
            <span role="img" class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.subscriptions', ['count' => $navCounts['subscriptions']]) }}">{{ $navCount('subscriptions') }}</span>
        @endif
    </a>
    {{-- The sidebar entry points at the /chains overview (all chains)
         rather than the /chains/review queue (candidates only) so the
         primary discovery surface is "what chains do I have?" rather
         than "what's awaiting triage?". The overview header links to
         the review queue and the hints surface in turn. --}}
    {{-- The badge counts the review queue, not the chains the overview lists:
         the number worth interrupting for is the one the user can clear.
         Default .side-badge (NOT .muted, NOT .alert) — an awaiting-review
         count is exactly the actionable-count-to-clear the badge-intensity
         taxonomy reserves the inverted fill for. --}}
    <a href="{{ route('chains.index') }}" class="side-item {{ $isActive('/chains') }}">
        <span class="ic" aria-hidden="true">⇉</span>
        {{ Lang::get('core::sidebar.nav.chains') }}
        @if (($navCounts['chains'] ?? 0) > 0)
            <span role="img" class="side-badge" aria-label="{{ Lang::get('core::sidebar.badge.chains', ['count' => $navCounts['chains']]) }}">{{ $navCount('chains') }}</span>
        @endif
    </a>
    {{-- Unusual charges — the anomaly section of the /drift alerts home.
         Links to ?type=anomaly; amber .side-badge.alert
         shows the open anomaly count (revival-aware, merged into
         navCounts by the Anomaly nav-badge composer) and hides at zero. --}}
    <a href="{{ route('drift.index', ['type' => 'anomaly']) }}" class="side-item">
        <span class="ic" aria-hidden="true">◬</span>
        {{ Lang::get('core::sidebar.nav.unusual_charges') }}
        @if (($navCounts['anomaly'] ?? 0) > 0)
            <span role="img" class="side-badge alert" aria-label="{{ Lang::get('core::sidebar.badge.anomaly', ['count' => $navCounts['anomaly']]) }}">{{ $navCount('anomaly') }}</span>
        @endif
    </a>
    <a href="{{ route('drift.index') }}" class="side-item {{ $isActive('/drift') }}">
        <span class="ic" aria-hidden="true">⚠</span>
        {{ Lang::get('core::sidebar.nav.drift_alerts') }}
        @if (($navCounts['drift'] ?? 0) > 0)
            <span role="img" class="side-badge alert" aria-label="{{ Lang::get('core::sidebar.badge.drift', ['count' => $navCounts['drift']]) }}">{{ $navCount('drift') }}</span>
        @endif
    </a>

    {{-- Planning: money you are steering on purpose, as opposed to money
         that is leaving on its own above. --}}
    <div class="side-section-label">{{ Lang::get('core::sidebar.section_planning') }}</div>
    <a href="{{ route('budgets.index') }}" class="side-item {{ $isActive('/budgets') }}">
        <span class="ic" aria-hidden="true">⊙</span>
        {{ Lang::get('core::sidebar.nav.budgets') }}
        @if (($navCounts['budgets'] ?? 0) > 0)
            <span role="img" class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.budgets', ['count' => $navCounts['budgets']]) }}">{{ $navCount('budgets') }}</span>
        @endif
    </a>
    {{-- Tax tagging + per-year export. The muted side-badge shows the
         lifetime tagged item count when > 0; hidden when zero for calm posture. --}}
    <a href="{{ route('tax.index') }}" class="side-item {{ $isActive('/tax') }}">
        <span class="ic" aria-hidden="true">⊞</span>
        {{ Lang::get('core::sidebar.nav.tax') }}
        @if (($navCounts['tax_tagged'] ?? 0) > 0)
            <span role="img" class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.tax', ['count' => $navCounts['tax_tagged']]) }}">{{ $navCount('tax_tagged') }}</span>
        @endif
    </a>
    <a href="{{ route('goals.index') }}" class="side-item {{ $isActive('/goals') }}">
        <span class="ic" aria-hidden="true">◎</span>
        {{ Lang::get('core::sidebar.nav.goals') }}
    </a>
    <a href="{{ route('pots.index') }}" class="side-item {{ $isActive('/pots') }}">
        <span class="ic" aria-hidden="true">◫</span>
        {{ Lang::get('core::sidebar.nav.pots') }}
    </a>

    {{-- Insights: surfaces you go to in order to look, not to change
         anything. Both used to sit inside MONEY, where a report builder
         read as a kind of budget. --}}
    <div class="side-section-label">{{ Lang::get('core::sidebar.section_insights') }}</div>
    {{-- Reports — the user-composable report builder + saved-reports
         library. $isActive matches both /reports (the
         builder) and /reports/library (the saved-report index) since
         both share the "Reports" nav identity. --}}
    <a href="{{ route('reports.index') }}" class="side-item {{ str_starts_with($currentPath, '/reports') ? 'active' : '' }}">
        <span class="ic" aria-hidden="true">▤</span>
        {{ Lang::get('core::sidebar.nav.reports') }}
    </a>
    {{-- Reconcile — the standalone statement-balance confirmation surface;
         no account-detail page exists in the app. --}}
    <a href="{{ route('reconcile.index') }}" class="side-item {{ $isActive('/reconcile') }}">
        <span class="ic" aria-hidden="true">✓</span>
        {{ Lang::get('core::sidebar.nav.reconcile') }}
    </a>

    <div class="side-section-label">{{ Lang::get('core::sidebar.section_ingestion') }}</div>
    <a href="{{ route('imports.new') }}" class="side-item {{ $isActive('/imports/new') }}">
        <span class="ic" aria-hidden="true">⊕</span>
        {{ Lang::get('core::sidebar.nav.imports') }}
        @if (($navCounts['imports'] ?? 0) > 0)
            <span role="img" class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.imports', ['count' => $navCounts['imports']]) }}">{{ $navCount('imports') }}</span>
        @endif
    </a>
    {{-- Two entries are deliberately absent here.

         Migrating from another app is a once-ever errand, so it does not earn
         a permanent nav slot — and naming the competitors in the sidebar put
         two other products in front of the user on every screen. It is reached
         from the Imports page now, which is where someone looking to bring
         data in already is.

         Receipts had no destination to offer: the module registers no web
         routes at all, only console ones, so the row sat here as
         `<a href="#">` and swallowed every tap — highlighting on press and
         going nowhere, which reads as a broken app rather than an absent
         feature. It comes back when there is a page to point at. --}}
    <a href="{{ route('cashbook.index') }}" class="side-item {{ $isActive('/cash') }}">
        <span class="ic" aria-hidden="true">€</span>
        {{ Lang::get('core::sidebar.nav.cashbook') }}
    </a>
    {{-- Senders waiting to be promoted or dismissed, plus inboxes whose token
         expired and need reconnecting — both are errands with a done state, so
         this takes the default inverted .side-badge rather than the rose alert
         variant. Nothing here is wrong with the user's money. --}}
    <a href="{{ route('inboxes.index') }}" class="side-item {{ $isActive('/inboxes') }}">
        <span class="ic" aria-hidden="true">✉</span>
        {{ Lang::get('core::sidebar.nav.email') }}
        @if (($navCounts['inboxes'] ?? 0) > 0)
            <span role="img" class="side-badge" aria-label="{{ Lang::get('core::sidebar.badge.inboxes', ['count' => $navCounts['inboxes']]) }}">{{ $navCount('inboxes') }}</span>
        @endif
    </a>

    {{-- Organise: telling Beatrax what a transaction actually is — who the
         counterparty is, which category it belongs to, and the shared
         knowledge the community maintains. All four are the same errand,
         and none of them is a setting. --}}
    <div class="side-section-label">{{ Lang::get('core::sidebar.section_organise') }}</div>
    {{--
        Counterparties index — the type-aware "who am I transacting
        with?" surface. Resolves to the named route
        `counterparties.index` shipped with 17-06b.
    --}}
    <a href="{{ route('counterparties.index') }}" class="side-item {{ $isActive('/counterparties') }}">
        <span class="ic" aria-hidden="true">◉</span>
        {{ Lang::get('core::sidebar.nav.counterparties') }}
        @if (($navCounts['counterparties'] ?? 0) > 0)
            <span role="img" class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.counterparties', ['count' => $navCounts['counterparties']]) }}">{{ $navCount('counterparties') }}</span>
        @endif
    </a>
    {{--
        Counterparty triage queue — focused single-card surface for
        labelling unknown counterparties. Carries an amber count badge
        when there are unknowns to identify; hidden when zero so the
        sidebar stays calm. Count populated from the injected
        CounterpartyTriageQueue read query.
    --}}
    <a href="{{ route('counterparties.triage') }}" class="side-item {{ $isActive('/counterparties/triage') }}">
        <span class="ic" aria-hidden="true">❋</span>
        {{ Lang::get('core::sidebar.nav.triage') }}
        @if ($unknownCounterpartyCount > 0)
            <span
                role="img"
                class="side-badge"
                style="background: var(--color-amber-bg); color: var(--color-amber); font-weight: 600;"
                aria-label="{{ Lang::get('core::sidebar.badge.triage', ['count' => $unknownCounterpartyCount]) }}"
            >{{ $unknownCounterpartyCount }}</span>
        @endif
    </a>
    <a href="{{ route('uncategorized') }}" class="side-item {{ $isActive('/uncategorized') }}">
        <span class="ic" aria-hidden="true">⌕</span>
        {{ Lang::get('core::sidebar.nav.categorization') }}
    </a>
    <a href="{{ route('community.index') }}" class="side-item {{ $isActive('/community') }}">
        <span class="ic" aria-hidden="true">◇</span>
        {{ Lang::get('core::sidebar.nav.community') }}
    </a>

    <div class="side-section-label">{{ Lang::get('core::sidebar.section_settings') }}</div>
    {{-- /sync status surface. Icon is a hand-copied
         outline stroke-1.5 circular-arrows/refresh glyph (matches the
         existing stroke-1.5 SVG icon convention used elsewhere in the app,
         e.g. Modules/Sync/Resources/views/livewire/pairing-flow-modal.blade.php)
         — deliberately NOT Flux's device-phone-mobile icon, which is
         reserved for device-type indicators (UI-SPEC §3). --}}
    <a href="{{ route('data-devices.index') }}" class="side-item {{ $isActive('/data-devices') }}">
        <span class="ic" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
        </span>
        {{ Lang::get('core::sidebar.nav.data_devices') }}
    </a>
    <a href="{{ route('settings') }}" class="side-item {{ $isActive('/settings') }}">
        <span class="ic" aria-hidden="true">⚙</span>
        {{ Lang::get('core::sidebar.nav.settings') }}
    </a>

    <div class="side-foot">
        @if ($isDeveloper)
            {{--
                Dev block. Server-side gated on
                `users.is_developer` — non-developers never receive
                this DOM so the Dev Console's existence is never
                disclosed via the rendered HTML. Pulsing emerald
                dot is a static box-shadow ring (no JS). The
                live-data numbers below come from the
                wire:poll.5s sub-tree.
            --}}
            <div class="side-dev-block">
                <div class="heading">
                    <span class="dot-live" aria-hidden="true"></span>
                    {{ Lang::get('core::sidebar.dev.heading') }}
                </div>
                <a href="/dev" class="side-item">
                    <span class="ic" aria-hidden="true">›_</span>
                    {{ Lang::get('core::sidebar.dev.open_console') }}
                    {{--
                        Platform-aware dev-console kbd hint.
                        Follows the same Js::from() pattern as the palette hint above
                        to keep raw Mac glyphs out of the server-rendered HTML.
                        Alpine evaluates x-text client-side; SSR fallback text is Ctrl+.
                        .hidden-touch for the same reason the palette hint carries it:
                        a phone has no Ctrl key, so advertising the chord is noise.
                    --}}
                    <span
                        class="kbd hidden-touch max-lg:hidden"
                        aria-hidden="true"
                        x-text="$store.platform.isMac ? '\u2318.' : 'Ctrl+.'"
                    >Ctrl+.</span>
                </a>
                {{--
                    Live Dev-block pulse. wire:poll.5s refreshes
                    only this subtree so the heartbeat + queue-count
                    indicators stay fresh without re-rendering the
                    whole sidebar. The queue count is jobs.count()
                    (pending only); the worker delta is null when
                    no heartbeat exists in cache.

                    NOT on mobile. The sidebar is mounted on every page
                    (inside the drawer), and the mobile shell serialises
                    every request through one persistent PHP interpreter,
                    so this poll queues ahead of the taps the user is
                    making and the app reads as frozen mid-navigation.
                    The values still refresh on any page load.
                --}}
                <div class="dev-pulse" @if ($pollsLiveData) wire:poll.5s @endif>
                    {{ Lang::get('core::sidebar.dev.pulse', ['queue' => $queueCount, 'worker' => $workerSecondsAgo !== null ? $workerSecondsAgo . 's ago' : '—']) }}
                </div>
            </div>
        @endif

        {{--
            No role or tabindex: this block displays who is signed in and has
            no action behind it. Announcing it as a button promised assistive
            technology something that was never wired up, and the tab stop was
            a dead end on the way to Sign out.
        --}}
        <div class="side-account">
            <div class="avatar" aria-hidden="true">{{ $userInitial }}</div>
            <div>
                <span class="name">{{ $username }}</span>
                <span class="caption">{{ $accountCaption }}</span>
            </div>
            <span class="ic right" aria-hidden="true">⋯</span>
        </div>

        <form method="POST" action="{{ route('logout') }}" x-data x-on:submit.prevent="beatraxSubmitPostForm($el, $event.submitter)">
            @csrf
            <button type="submit" class="side-item" style="width: 100%;">
                {{-- U+23FB POWER SYMBOL has no glyph in the Android system font
                     stack and rendered as tofu on device. Every other row's
                     codepoint is Arrows or Geometric Shapes and is covered; this
                     one is Miscellaneous Technical. Drawn instead, the same way
                     the sync row already sidesteps the problem. --}}
                <span class="ic" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" />
                    </svg>
                </span>
                {{ Lang::get('core::sidebar.sign_out') }}
            </button>
        </form>
    </div>
</aside>
