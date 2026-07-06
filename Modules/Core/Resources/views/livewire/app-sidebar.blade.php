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

<aside class="side" aria-label="Primary" style="--side-w: 248px;">
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
            placeholder="Search…"
            aria-label="Open search palette"
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
            class="kbd hidden-touch"
            aria-hidden="true"
            x-text="$store.platform.isMac ? '\u2318K' : 'Ctrl+K'"
        >Ctrl+K</span>
    </div>

    <div class="side-section-label">THIS MONTH</div>
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
        Dashboard
    </a>
    <a href="{{ route('transactions.index') }}" class="side-item {{ $isActive('/transactions') }}">
        <span class="ic" aria-hidden="true">≡</span>
        Transactions
        @if (($navCounts['transactions'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ $navCounts['transactions'] }} transactions">{{ $navCount('transactions') }}</span>
        @endif
    </a>
    <a href="{{ route('forecast.index') }}" class="side-item {{ $isActive('/forecast') }}">
        <span class="ic" aria-hidden="true">↗</span>
        Forecasts
    </a>
    <a href="{{ route('calendar.index') }}" class="side-item {{ $isActive('/calendar') }}">
        <span class="ic" aria-hidden="true">▦</span>
        Calendar
    </a>

    <div class="side-section-label">MONEY</div>
    <a href="{{ route('recurring.index') }}" class="side-item {{ $isActive('/recurring') }}">
        <span class="ic" aria-hidden="true">↻</span>
        Recurring
        @if (($navCounts['recurring'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ $navCounts['recurring'] }} recurring series">{{ $navCount('recurring') }}</span>
        @endif
    </a>
    {{--
        Counterparties index — the type-aware "who am I transacting
        with?" surface. Resolves to the named route
        `counterparties.index` shipped with 17-06b.
    --}}
    <a href="{{ route('counterparties.index') }}" class="side-item {{ $isActive('/counterparties') }}">
        <span class="ic" aria-hidden="true">◉</span>
        Counterparties
        @if (($navCounts['counterparties'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ $navCounts['counterparties'] }} counterparties">{{ $navCount('counterparties') }}</span>
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
        Triage
        @if ($unknownCounterpartyCount > 0)
            <span
                class="side-badge"
                style="background: var(--color-amber-bg); color: var(--color-amber); font-weight: 600;"
                aria-label="{{ $unknownCounterpartyCount }} unknown counterparties awaiting triage"
            >{{ $unknownCounterpartyCount }}</span>
        @endif
    </a>
    {{-- The sidebar entry points at the /chains overview (all chains)
         rather than the /chains/review queue (candidates only) so the
         primary discovery surface is "what chains do I have?" rather
         than "what's awaiting triage?". The overview header links to
         the review queue and the hints surface in turn. --}}
    <a href="{{ route('chains.index') }}" class="side-item {{ $isActive('/chains') }}">
        <span class="ic" aria-hidden="true">⇉</span>
        Chains
    </a>
    <a href="{{ route('drift.index') }}" class="side-item {{ $isActive('/drift') }}">
        <span class="ic" aria-hidden="true">⚠</span>
        Drift Alerts
        @if (($navCounts['drift'] ?? 0) > 0)
            <span class="side-badge alert" aria-label="{{ $navCounts['drift'] }} open drift alerts">{{ $navCount('drift') }}</span>
        @endif
    </a>
    {{-- Unusual charges — the anomaly section of the /drift alerts home
         (D-02/D-03). Links to ?type=anomaly; amber .side-badge.alert
         shows the open anomaly count (revival-aware, merged into
         navCounts by the Anomaly nav-badge composer) and hides at zero. --}}
    <a href="{{ route('drift.index', ['type' => 'anomaly']) }}" class="side-item">
        <span class="ic" aria-hidden="true">◬</span>
        Unusual charges
        @if (($navCounts['anomaly'] ?? 0) > 0)
            <span class="side-badge alert" aria-label="{{ $navCounts['anomaly'] }} open unusual charges">{{ $navCount('anomaly') }}</span>
        @endif
    </a>
    <a href="{{ route('budgets.index') }}" class="side-item {{ $isActive('/budgets') }}">
        <span class="ic" aria-hidden="true">⊙</span>
        Budgets
        @if (($navCounts['budgets'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ $navCounts['budgets'] }} budgets">{{ $navCount('budgets') }}</span>
        @endif
    </a>
    {{-- Tax tagging + per-year export (D-17). The muted side-badge shows the
         lifetime tagged item count when > 0; hidden when zero for calm posture. --}}
    <a href="{{ route('tax.index') }}" class="side-item {{ $isActive('/tax') }}">
        <span class="ic" aria-hidden="true">⊞</span>
        Tax
        @if (($navCounts['tax_tagged'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ $navCounts['tax_tagged'] }} items tagged as tax-relevant">{{ $navCount('tax_tagged') }}</span>
        @endif
    </a>
    <a href="{{ route('goals.index') }}" class="side-item {{ $isActive('/goals') }}">
        <span class="ic" aria-hidden="true">◎</span>
        Goals
    </a>
    <a href="{{ route('pots.index') }}" class="side-item {{ $isActive('/pots') }}">
        <span class="ic" aria-hidden="true">◫</span>
        Pots
    </a>
    {{-- Reconcile — standalone SC-2 statement-balance confirmation surface
         (D-05, no account-detail page exists in the app). --}}
    <a href="{{ route('reconcile.index') }}" class="side-item {{ $isActive('/reconcile') }}">
        <span class="ic" aria-hidden="true">✓</span>
        Reconcile
    </a>
    <a href="{{ route('drift.watch') }}" class="side-item {{ $isActive('/drift/watch') }}">
        <span class="ic" aria-hidden="true">↗</span>
        Subscriptions
        @if (($navCounts['subscriptions'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ $navCounts['subscriptions'] }} subscriptions">{{ $navCount('subscriptions') }}</span>
        @endif
    </a>

    <div class="side-section-label">INGESTION</div>
    <a href="{{ route('imports.new') }}" class="side-item {{ $isActive('/imports/new') }}">
        <span class="ic" aria-hidden="true">⊕</span>
        Imports
        @if (($navCounts['imports'] ?? 0) > 0)
            <span class="side-badge muted" aria-label="{{ $navCounts['imports'] }} imports">{{ $navCount('imports') }}</span>
        @endif
    </a>
    {{-- 13.5-08 UI-SPEC Copywriting Contract: locked nav-entry copy, placed
         beside the existing Import entry (planner's call on exact placement). --}}
    <a href="{{ route('migrations.index') }}" class="side-item {{ $isActive('/migrations') }}">
        <span class="ic" aria-hidden="true">⇄</span>
        Import from YNAB / Actual
    </a>
    <a href="#" class="side-item">
        <span class="ic" aria-hidden="true">⌗</span>
        Receipts
    </a>
    <a href="{{ route('cashbook.index') }}" class="side-item {{ $isActive('/cash') }}">
        <span class="ic" aria-hidden="true">€</span>
        Cash book
    </a>
    <a href="{{ route('inboxes.index') }}" class="side-item {{ $isActive('/inboxes') }}">
        <span class="ic" aria-hidden="true">✉</span>
        Email
    </a>
    <a href="{{ route('uncategorized') }}" class="side-item {{ $isActive('/uncategorized') }}">
        <span class="ic" aria-hidden="true">⌕</span>
        Categorization
    </a>

    <div class="side-section-label">SETTINGS</div>
    <a href="{{ route('settings') }}" class="side-item {{ $isActive('/settings') }}">
        <span class="ic" aria-hidden="true">⚙</span>
        Settings
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
                    Developer
                </div>
                <a href="/dev" class="side-item">
                    <span class="ic" aria-hidden="true">›_</span>
                    Open Dev Console
                    {{--
                        Platform-aware dev-console kbd hint (IN-03, Phase 4).
                        Follows the same Js::from() pattern as the palette hint above
                        (CR-02) to keep raw Mac glyphs out of the server-rendered HTML.
                        Alpine evaluates x-text client-side; SSR fallback text is Ctrl+.
                    --}}
                    <span
                        class="kbd"
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
                    Queue {{ $queueCount }} · Worker {{ $workerSecondsAgo !== null ? $workerSecondsAgo . 's ago' : '—' }}
                </div>
            </div>
        @endif

        <div class="side-account" role="button" tabindex="0">
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
                Sign out
            </button>
        </form>
    </div>
</aside>
