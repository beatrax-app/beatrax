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
        <img src="{{ Vite::asset('resources/brand/logo.svg') }}" alt="beatrax" width="24" height="24" class="logo logo-svg" />
        <span>beatrax</span>
        {{--
            Version chip reads from `config/nativephp.php#version`, which
            is the single source of truth Plan 17-01 (versioning baseline)
            locked in. The `v` prefix is hard-coded since the config holds
            the bare SemVer; the chip stays the only place that prepends
            it so a future migration to a leading-zero scheme touches one
            line instead of every release artefact.
        --}}
        <span class="version-chip">v{{ config('nativephp.version') }}</span>
    </div>

    {{-- Search affordance (D-25, Phase 8 Plan 05). Click dispatches palette:open. --}}
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
            Platform-aware kbd hint (D-04, Phase 4 Plan 03).
            On macOS: renders the Mac modifier key symbol (U+2318) followed by K.
            On Windows/Linux: renders Ctrl+K.
            On touch devices: hidden entirely via .hidden-touch (D-13, pointer:coarse).

            The Mac glyph is written as the JS string escape backslash-u2318
            directly in the Alpine expression — no PHP interpolation at all — so
            the raw U+2318 character never appears in the server-rendered HTML,
            satisfying the AppSidebarKbdTest `not->toContain('⌘K')` assertion
            (D-04). Do NOT swap this for Js::from(): Laravel 13's Js::from uses
            JSON_UNESCAPED_UNICODE and emits the raw glyph. Alpine evaluates the
            x-text expression client-side; SSR fallback text is Ctrl+K.
        --}}
        <span
            class="kbd hidden-touch max-lg:hidden"
            aria-hidden="true"
            x-text="$store.platform.isMac ? '\u2318K' : 'Ctrl+K'"
        >Ctrl+K</span>
    </div>

    <div class="side-section-label">{{ Lang::get('core::sidebar.section_this_month') }}</div>
    @php
        // Cached per-user nav counts (NavCountsService). Compact-format large
        // counts so a four-digit badge never stretches the rail.
        $navCounts = $navCounts ?? [];
        $navCount = static function (string $key) use ($navCounts): string {
            $n = (int) ($navCounts[$key] ?? 0);

            return $n >= 1000 ? round($n / 1000, 1).'k' : (string) $n;
        };
    @endphp

    <a href="{{ route('dashboard') }}" wire:navigate class="side-item {{ $isActive('/') }}">
        <span class="ic" aria-hidden="true">◆</span>
        {{ Lang::get('core::sidebar.nav.dashboard') }}
    </a>
    <a href="{{ route('transactions.index') }}" wire:navigate class="side-item {{ $isActive('/transactions') }}">
        <span class="ic" aria-hidden="true">≡</span>
        {{ Lang::get('core::sidebar.nav.transactions') }}
        @if (($navCounts['transactions'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.transactions', ['count' => $navCounts['transactions']]) }}">{{ $navCount('transactions') }}</span>
        @endif
    </a>
    <a href="{{ route('forecast.index') }}" wire:navigate class="side-item {{ $isActive('/forecast') }}">
        <span class="ic" aria-hidden="true">↗</span>
        {{ Lang::get('core::sidebar.nav.forecasts') }}
    </a>
    <a href="{{ route('calendar.index') }}" wire:navigate class="side-item {{ $isActive('/calendar') }}">
        <span class="ic" aria-hidden="true">▦</span>
        {{ Lang::get('core::sidebar.nav.calendar') }}
    </a>

    <div class="side-section-label">{{ Lang::get('core::sidebar.section_money') }}</div>
    <a href="{{ route('recurring.index') }}" wire:navigate class="side-item {{ $isActive('/recurring') }}">
        <span class="ic" aria-hidden="true">↻</span>
        {{ Lang::get('core::sidebar.nav.recurring') }}
        @if (($navCounts['recurring'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.recurring', ['count' => $navCounts['recurring']]) }}">{{ $navCount('recurring') }}</span>
        @endif
    </a>
    {{--
        Counterparties index — the type-aware "who am I transacting
        with?" surface. Resolves to the named route
        `counterparties.index` shipped with 17-06b.
    --}}
    <a href="{{ route('counterparties.index') }}" wire:navigate class="side-item {{ $isActive('/counterparties') }}">
        <span class="ic" aria-hidden="true">◉</span>
        {{ Lang::get('core::sidebar.nav.counterparties') }}
        @if (($navCounts['counterparties'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.counterparties', ['count' => $navCounts['counterparties']]) }}">{{ $navCount('counterparties') }}</span>
        @endif
    </a>
    {{--
        Counterparty triage queue — focused single-card surface for
        labelling unknown counterparties. Carries an amber count badge
        when there are unknowns to identify; hidden when zero so the
        sidebar stays calm. Count populated from the injected
        CounterpartyTriageQueue read query.
    --}}
    <a href="{{ route('counterparties.triage') }}" wire:navigate class="side-item {{ $isActive('/counterparties/triage') }}">
        <span class="ic" aria-hidden="true">❋</span>
        {{ Lang::get('core::sidebar.nav.triage') }}
        @if ($unknownCounterpartyCount > 0)
            <span
                class="side-badge"
                style="background: var(--color-amber-bg); color: var(--color-amber); font-weight: 600;"
                aria-label="{{ Lang::get('core::sidebar.badge.triage', ['count' => $unknownCounterpartyCount]) }}"
            >{{ $unknownCounterpartyCount }}</span>
        @endif
    </a>
    {{-- The sidebar entry points at the /chains overview (all chains)
         rather than the /chains/review queue (candidates only) so the
         primary discovery surface is "what chains do I have?" rather
         than "what's awaiting triage?". The overview header links to
         the review queue and the hints surface in turn. --}}
    <a href="{{ route('chains.index') }}" wire:navigate class="side-item {{ $isActive('/chains') }}">
        <span class="ic" aria-hidden="true">⇉</span>
        {{ Lang::get('core::sidebar.nav.chains') }}
    </a>
    <a href="{{ route('drift.index') }}" wire:navigate class="side-item {{ $isActive('/drift') }}">
        <span class="ic" aria-hidden="true">⚠</span>
        {{ Lang::get('core::sidebar.nav.drift_alerts') }}
        @if (($navCounts['drift'] ?? 0) > 0)
            <span class="side-badge alert" aria-label="{{ Lang::get('core::sidebar.badge.drift', ['count' => $navCounts['drift']]) }}">{{ $navCount('drift') }}</span>
        @endif
    </a>
    {{-- Unusual charges — the anomaly section of the /drift alerts home
         (D-02/D-03). Links to ?type=anomaly; amber .side-badge.alert
         shows the open anomaly count (revival-aware, merged into
         navCounts by the Anomaly nav-badge composer) and hides at zero. --}}
    <a href="{{ route('drift.index', ['type' => 'anomaly']) }}" wire:navigate class="side-item">
        <span class="ic" aria-hidden="true">◬</span>
        {{ Lang::get('core::sidebar.nav.unusual_charges') }}
        @if (($navCounts['anomaly'] ?? 0) > 0)
            <span class="side-badge alert" aria-label="{{ Lang::get('core::sidebar.badge.anomaly', ['count' => $navCounts['anomaly']]) }}">{{ $navCount('anomaly') }}</span>
        @endif
    </a>
    {{-- Notifications — the unified inbox (18-12, D-01/D-03). Placed in the
         alerts-adjacent cluster, right after Unusual charges and before
         Budgets. Default/inverted .side-badge (NOT .side-badge.alert) —
         an unread count is an actionable-count-to-clear, not a problem
         state, per component-library.md's badge-intensity taxonomy. --}}
    <a href="{{ route('notifications.index') }}" wire:navigate class="side-item {{ $isActive('/notifications') }}">
        <span class="ic" aria-hidden="true">◈</span>
        {{ Lang::get('core::sidebar.nav.notifications') }}
        @if (($navCounts['notifications'] ?? 0) > 0)
            <span class="side-badge" aria-label="{{ Lang::get('core::sidebar.badge.notifications', ['count' => $navCounts['notifications']]) }}">{{ $navCount('notifications') }}</span>
        @endif
    </a>
    <a href="{{ route('budgets.index') }}" wire:navigate class="side-item {{ $isActive('/budgets') }}">
        <span class="ic" aria-hidden="true">⊙</span>
        {{ Lang::get('core::sidebar.nav.budgets') }}
        @if (($navCounts['budgets'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.budgets', ['count' => $navCounts['budgets']]) }}">{{ $navCount('budgets') }}</span>
        @endif
    </a>
    {{-- Tax tagging + per-year export (D-17). The muted side-badge shows the
         lifetime tagged item count when > 0; hidden when zero for calm posture. --}}
    <a href="{{ route('tax.index') }}" wire:navigate class="side-item {{ $isActive('/tax') }}">
        <span class="ic" aria-hidden="true">⊞</span>
        {{ Lang::get('core::sidebar.nav.tax') }}
        @if (($navCounts['tax_tagged'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.tax', ['count' => $navCounts['tax_tagged']]) }}">{{ $navCount('tax_tagged') }}</span>
        @endif
    </a>
    <a href="{{ route('goals.index') }}" wire:navigate class="side-item {{ $isActive('/goals') }}">
        <span class="ic" aria-hidden="true">◎</span>
        {{ Lang::get('core::sidebar.nav.goals') }}
    </a>
    <a href="{{ route('pots.index') }}" wire:navigate class="side-item {{ $isActive('/pots') }}">
        <span class="ic" aria-hidden="true">◫</span>
        {{ Lang::get('core::sidebar.nav.pots') }}
    </a>
    {{-- Reports — the user-composable report builder + saved-reports
         library (999.6, Req 1/9). $isActive matches both /reports (the
         builder) and /reports/library (the saved-report index) since
         both share the "Reports" nav identity. --}}
    <a href="{{ route('reports.index') }}" wire:navigate class="side-item {{ str_starts_with($currentPath, '/reports') ? 'active' : '' }}">
        <span class="ic" aria-hidden="true">▤</span>
        {{ Lang::get('core::sidebar.nav.reports') }}
    </a>
    {{-- Reconcile — standalone SC-2 statement-balance confirmation surface
         (D-05, no account-detail page exists in the app). --}}
    <a href="{{ route('reconcile.index') }}" wire:navigate class="side-item {{ $isActive('/reconcile') }}">
        <span class="ic" aria-hidden="true">✓</span>
        {{ Lang::get('core::sidebar.nav.reconcile') }}
    </a>
    <a href="{{ route('drift.watch') }}" wire:navigate class="side-item {{ $isActive('/drift/watch') }}">
        <span class="ic" aria-hidden="true">↗</span>
        {{ Lang::get('core::sidebar.nav.subscriptions') }}
        @if (($navCounts['subscriptions'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.subscriptions', ['count' => $navCounts['subscriptions']]) }}">{{ $navCount('subscriptions') }}</span>
        @endif
    </a>

    <div class="side-section-label">{{ Lang::get('core::sidebar.section_ingestion') }}</div>
    <a href="{{ route('imports.new') }}" wire:navigate class="side-item {{ $isActive('/imports/new') }}">
        <span class="ic" aria-hidden="true">⊕</span>
        {{ Lang::get('core::sidebar.nav.imports') }}
        @if (($navCounts['imports'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ Lang::get('core::sidebar.badge.imports', ['count' => $navCounts['imports']]) }}">{{ $navCount('imports') }}</span>
        @endif
    </a>
    {{-- 13.5-08 UI-SPEC Copywriting Contract: locked nav-entry copy, placed
         beside the existing Import entry (planner's call on exact placement). --}}
    <a href="{{ route('migrations.index') }}" wire:navigate class="side-item {{ $isActive('/migrations') }}">
        <span class="ic" aria-hidden="true">⇄</span>
        {{ Lang::get('core::sidebar.nav.migrations') }}
    </a>
    <a href="#" class="side-item">
        <span class="ic" aria-hidden="true">⌗</span>
        {{ Lang::get('core::sidebar.nav.receipts') }}
    </a>
    <a href="{{ route('cashbook.index') }}" wire:navigate class="side-item {{ $isActive('/cash') }}">
        <span class="ic" aria-hidden="true">€</span>
        {{ Lang::get('core::sidebar.nav.cashbook') }}
    </a>
    <a href="{{ route('inboxes.index') }}" wire:navigate class="side-item {{ $isActive('/inboxes') }}">
        <span class="ic" aria-hidden="true">✉</span>
        {{ Lang::get('core::sidebar.nav.email') }}
    </a>
    <a href="{{ route('uncategorized') }}" wire:navigate class="side-item {{ $isActive('/uncategorized') }}">
        <span class="ic" aria-hidden="true">⌕</span>
        {{ Lang::get('core::sidebar.nav.categorization') }}
    </a>

    <div class="side-section-label">{{ Lang::get('core::sidebar.section_settings') }}</div>
    {{-- /sync status surface (D-05, Phase 15 Plan 10). Icon is a hand-copied
         outline stroke-1.5 circular-arrows/refresh glyph (matches the
         existing stroke-1.5 SVG icon convention used elsewhere in the app,
         e.g. Modules/Sync/Resources/views/livewire/pairing-flow-modal.blade.php)
         — deliberately NOT Flux's device-phone-mobile icon, which is
         reserved for device-type indicators (15-UI-SPEC.md §3). --}}
    <a href="{{ route('sync.index') }}" wire:navigate class="side-item {{ $isActive('/sync') }}">
        <span class="ic" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
        </span>
        {{ Lang::get('core::sidebar.nav.sync') }}
    </a>
    <a href="{{ route('settings') }}" wire:navigate class="side-item {{ $isActive('/settings') }}">
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
                        Platform-aware dev-console kbd hint (IN-03, Phase 4).
                        Follows the same Js::from() pattern as the palette hint above
                        (CR-02) to keep raw Mac glyphs out of the server-rendered HTML.
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
                --}}
                <div class="dev-pulse" wire:poll.5s>
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

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="side-item" style="width: 100%;">
                <span class="ic" aria-hidden="true">⏻</span>
                {{ Lang::get('core::sidebar.sign_out') }}
            </button>
        </form>
    </div>
</aside>
