@use('Modules\Core\Public\Enums\OAuthAlertKind')
@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\DevMode\Internal\Enums\CommandTier')
{{-- UI-SPEC §19: overflow-x-auto at the page root ensures no surface
     overflows the viewport at phone width. The dark console pane preserves
     its fixed dark styling (localized dark exception per SKILL). --}}
<div class="p-6 space-y-6 overflow-x-auto" data-testid="dev-overview-page">
    <header class="space-y-1">
        <h1 class="text-xl font-semibold text-[var(--color-text)]">{{ Lang::get('dev::overview.heading') }}</h1>
        <p class="text-sm text-[var(--color-text-muted)]">{{ Lang::get('dev::overview.subtitle') }}</p>
    </header>

    {{--
        Console pane — UI-SPEC § /dev overview surfaces.
        Theme-locked dark inset (#0b1220 bg / #f1f5f9 text regardless of
        the active light/dark theme). The pane is the page's primary
        visual anchor and never participates in theme switching.
    --}}
    <section
        class="console-pane rounded-md p-4"
        data-testid="console-pane"
        wire:poll.5s.keep-alive
    >
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-start">
            {{-- Worker heartbeat tile --}}
            <div data-testid="console-pane-heartbeat">
                <div class="text-[11px] uppercase tracking-wide text-slate-600 dark:text-slate-400">{{ Lang::get('dev::overview.worker_heartbeat') }}</div>
                @if ($workerHeartbeat['secondsAgo'] === null)
                    <div class="mt-1 text-sm font-mono text-rose-300">{{ Lang::get('dev::overview.not_running') }}</div>
                @else
                    <div class="mt-1 text-sm font-mono">{{ $workerHeartbeat['label'] }}</div>
                @endif
                {{-- Sparkline SVG: static server-rendered 220×32 emerald polyline.
                     Currently a placeholder polyline so the SVG primitive lands.
                     A future plan can replace the points list with real heartbeat-history data. --}}
                <svg
                    width="220" height="32"
                    viewBox="0 0 220 32" xmlns="http://www.w3.org/2000/svg"
                    class="mt-2"
                    aria-hidden="true"
                >
                    <polyline
                        fill="none" stroke="#10b981" stroke-width="1.5"
                        points="0,24 20,18 40,22 60,12 80,16 100,10 120,14 140,8 160,12 180,6 200,10 220,4"
                    />
                </svg>
            </div>

            {{-- Queue counts tile --}}
            <div>
                <div class="text-[11px] uppercase tracking-wide text-slate-600 dark:text-slate-400">{{ Lang::get('dev::overview.queue') }}</div>
                <div class="mt-1 flex items-baseline gap-3 font-mono text-sm">
                    <span data-testid="queue-tile-pending">
                        <span class="text-[10px] text-slate-600 dark:text-slate-400">{{ Lang::get('dev::overview.pending') }}</span>
                        <span class="ml-1 text-[var(--color-text-inverse,_#f1f5f9)]" style="font-variant-numeric: tabular-nums;">{{ $queueCounts['pending'] }}</span>
                    </span>
                    <span data-testid="queue-tile-failed">
                        <span class="text-[10px] text-slate-600 dark:text-slate-400">{{ Lang::get('dev::overview.failed') }}</span>
                        <span class="ml-1 {{ $queueCounts['failed'] > 0 ? 'text-rose-300' : '' }}" style="font-variant-numeric: tabular-nums;">{{ $queueCounts['failed'] }}</span>
                    </span>
                    <span data-testid="queue-tile-batches">
                        <span class="text-[10px] text-slate-600 dark:text-slate-400">{{ Lang::get('dev::overview.batches') }}</span>
                        <span class="ml-1" style="font-variant-numeric: tabular-nums;">{{ $queueCounts['batches'] }}</span>
                    </span>
                </div>
                <div class="mt-2 text-[11px] text-slate-600 dark:text-slate-400">
                    {{ Lang::get('dev::overview.queue_summary', ['failed' => Lang::choice('dev::overview.queue_summary_failed', $queueCounts['failed']), 'batches' => Lang::choice('dev::overview.queue_summary_batches', $queueCounts['batches'])]) }}
                </div>
            </div>

            {{-- Last command tile --}}
            <div>
                <div class="text-[11px] uppercase tracking-wide text-slate-600 dark:text-slate-400">{{ Lang::get('dev::overview.last_command') }}</div>
                <div class="mt-1 text-sm font-mono truncate">
                    {{ $lastCommand ?? '—' }}
                </div>
            </div>
        </div>

        {{-- Latest 5 structured log entries from today's daily file.
             Replaces the prior 8-line raw <pre> tail with a compact
             clickable list — each row drills into /dev/logs with
             severity + contains filters pre-applied so the operator
             lands on the source entry in context. --}}
        <div class="mt-4 space-y-1" data-testid="console-pane-tail">
            @if ($recentLogEntries === [])
                <p class="text-xs text-slate-600 dark:text-slate-400">{{ Lang::get('dev::overview.waiting_for_logs') }}</p>
            @else
                @foreach ($recentLogEntries as $entry)
                    @php
                        $severity = strtoupper($entry['severity']);
                        $sevClass = match ($severity) {
                            'WARNING' => 'text-amber-300',
                            'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' => 'text-rose-300',
                            'NOTICE' => 'text-slate-200',
                            default => 'text-slate-600',
                        };
                    @endphp
                    <a
                        href="{{ $entry['href'] }}"
                        class="flex items-baseline gap-2 px-1 py-1 rounded text-xs font-mono hover:bg-slate-800/60"
                        data-testid="recent-log-entry-row"
                        style="font-variant-numeric: tabular-nums;"
                    >
                        <span class="text-slate-600 flex-shrink-0 dark:text-slate-400">{{ $entry['timestamp'] }}</span>
                        <span class="{{ $sevClass }} flex-shrink-0 uppercase">{{ $severity }}</span>
                        <span class="text-slate-200 truncate">{{ $entry['message'] }}</span>
                    </a>
                @endforeach
            @endif
        </div>
    </section>

    {{--
        Two-column grid below the console pane: Recent runs + Open alerts.

        `items-stretch` is the grid default but is restated here next to
        `h-full` on each card so the intent is explicit: both cards must
        match heights regardless of how many list rows each one carries.
        Without `h-full` the cards stretch but their inner content (the
        `<ul>`) anchors to the top with empty space below — and the
        empty-state copy in each card uses identical left-aligned typo so
        the two surfaces never visually disagree when one is empty.
    --}}
    {{--
        App-lock data-key gate probe.

        Shows 'released' + fingerprint when the data key is held in the
        session (unlocked) and 'withheld' when locked. The raw key is never
        rendered — only a truncated SHA-256 fingerprint proves presence.

        The lock gates the per-session UI release only; background workers
        retain the data key independently of the session lock state.

        Developer-gated surface: this page is already behind the
        `developer` middleware — the probe does not add a second gate.
    --}}
    <div class="mt-4">
        @livewire('auth.app-lock-key-probe')
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-stretch">
        {{-- Recent runs card --}}
        <div class="card p-4 h-full" data-testid="recent-runs-card">
            <h2 class="text-sm font-semibold mb-3">{{ Lang::get('dev::overview.recent_runs') }}</h2>
            @if (count($recentRuns) === 0)
                <p class="text-sm text-[var(--color-text-muted)]">{{ $recentRunsEmptyCopy }}</p>
            @else
                <ul class="space-y-2">
                    @foreach ($recentRuns as $run)
                        <li data-testid="recent-run-row">
                            <a
                                href="{{ $run['href'] }}"
                                class="flex flex-wrap items-baseline justify-between gap-2 hover:underline"
                            >
                                <span class="text-sm font-mono truncate">{{ $run['command'] }}</span>
                                <span
                                    class="text-[10px] uppercase px-1.5 py-0.5 rounded {{ $run['tier'] === CommandTier::Destructive ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700' }}"
                                >{{ $run['tier']->value }}</span>
                                <span class="text-[11px] text-[var(--color-text-muted)] flex-shrink-0">
                                    @if ($run['createdAt'])
                                        {{ $run['createdAt'] }}
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Open alerts card --}}
        <div class="card p-4 h-full" data-testid="open-alerts-card">
            <h2 class="text-sm font-semibold mb-3">{{ Lang::get('dev::overview.open_alerts') }}</h2>
            @if ($openAlerts->isEmpty())
                <p class="text-sm text-[var(--color-text-muted)]">{{ $openAlertsEmptyCopy }}</p>
            @else
                <ul class="space-y-2">
                    @foreach ($openAlerts as $alert)
                        <li class="text-sm flex items-start justify-between gap-2">
                            <span>{{ $alert->message }}</span>
                            @if (OAuthAlertKind::promptsReauthorisation($alert->kind))
                                <a href="{{ Destination::Email->url() }}" class="text-blue-600 hover:underline text-xs">
                                    {{ Lang::get('dev::overview.reauth') }}
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
