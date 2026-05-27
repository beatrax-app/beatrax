@php
    /**
     * @var string $currentPath
     * @var string $username
     * @var string $userInitial
     * @var bool $isDeveloper
     * @var string $accountCaption
     * @var int $queueCount
     * @var int|null $workerSecondsAgo
     *
     * Active-state helper — mirrors TopNav's `$isActive` lambda
     * (pre-rewrite analog) so route-driven highlighting reads from
     * the controller-provided `$currentPath` rather than a global
     * helper.
     */
    $isActive = static fn (string $path): string => $currentPath === $path ? 'active' : '';
@endphp

<aside class="side" aria-label="Primary" style="--side-w: 248px;">
    <div class="side-brand">
        <img src="{{ Vite::asset('resources/brand/logo.svg') }}" alt="beatrax" width="24" height="24" class="logo logo-svg" />
        <span>beatrax</span>
        <span class="version-chip">v2.0.0-dev</span>
    </div>

    <div class="side-search" role="search">
        <span class="ic" aria-hidden="true">⌕</span>
        <input
            type="text"
            placeholder="Search or jump to…"
            aria-label="Search or jump to"
            disabled
        />
        <span class="kbd" aria-hidden="true">⌘K</span>
    </div>

    <div class="side-section-label">THIS MONTH</div>
    <a href="{{ route('dashboard') }}" class="side-item {{ $isActive('/') }}">
        <span class="ic" aria-hidden="true">◆</span>
        Dashboard
    </a>
    <a href="{{ route('transactions.index') }}" class="side-item {{ $isActive('/transactions') }}">
        <span class="ic" aria-hidden="true">≡</span>
        Transactions
    </a>
    <a href="{{ route('forecast.index') }}" class="side-item {{ $isActive('/forecast') }}">
        <span class="ic" aria-hidden="true">↗</span>
        Forecasts
    </a>

    <div class="side-section-label">MONEY</div>
    <a href="{{ route('recurring.index') }}" class="side-item {{ $isActive('/recurring') }}">
        <span class="ic" aria-hidden="true">↻</span>
        Recurring
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
    </a>

    <div class="side-section-label">INGESTION</div>
    <a href="{{ route('imports.new') }}" class="side-item {{ $isActive('/imports/new') }}">
        <span class="ic" aria-hidden="true">⊕</span>
        Imports
    </a>
    <a href="#" class="side-item">
        <span class="ic" aria-hidden="true">⌗</span>
        Receipts
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
                    <span class="kbd" aria-hidden="true">⌘.</span>
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
