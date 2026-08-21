@use('Modules\Core\Public\Support\Lang')
{{-- UI-SPEC §19: overflow-x-auto wrapper ensures the dense log pane
     scrolls horizontally at phone width without breaking the page layout. --}}
<div class="p-8 space-y-4 overflow-x-auto" data-testid="log-tailer-page">
    <header class="flex items-start justify-between gap-4">
        <div class="space-y-1">
            <h1 class="text-xl font-semibold text-[var(--color-text)]">{{ Lang::get('dev::logs.heading') }}</h1>
            <p class="text-sm text-[var(--color-text-muted)]">
                {{ Lang::get('dev::logs.subtitle') }}
            </p>
        </div>
        <button
            type="button"
            wire:click="truncate"
            wire:confirm="{{ Lang::get('dev::logs.truncate_confirm') }}"
            class="shrink-0 inline-flex items-center gap-1.5 rounded border border-rose-200 bg-white px-3 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-50 dark:bg-slate-900 dark:border-rose-900 dark:text-rose-400 dark:hover:bg-rose-950"
            data-testid="log-truncate-button"
            title="{{ Lang::get('dev::logs.truncate_title') }}"
        >
            <span aria-hidden="true">⌫</span>
            <span>{{ Lang::get('dev::logs.truncate') }}</span>
        </button>
    </header>

    @php
        $allSeverities = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
        // Severity colour: DEBUG/INFO muted, WARNING amber,
        // ERROR/CRITICAL/ALERT/EMERGENCY rose, NOTICE neutral.
        $severityClass = [
            'DEBUG' => 'text-slate-400',
            'INFO' => 'text-slate-400',
            'NOTICE' => 'text-slate-600 dark:text-slate-300',
            'WARNING' => 'text-amber-700 dark:text-amber-400',
            'ERROR' => 'text-rose-700 dark:text-rose-400',
            'CRITICAL' => 'text-rose-800 dark:text-rose-400',
            'ALERT' => 'text-rose-800 dark:text-rose-400',
            'EMERGENCY' => 'text-rose-900 dark:text-rose-300',
        ];
    @endphp

    <div
        x-data="logTailer({
            pollUrl: '{{ route('dev.logs.poll') }}',
            contextUrl: '{{ route('dev.logs.context') }}',
            statsUrl: '{{ route('dev.logs.stats') }}',
            initialSeverities: @js($severityList),
            initialContains: @js($contains),
            initialChannel: @js($channel),
            initialStats: @js($initialStats),
            labels: {
                pollInterrupted: @js(Lang::get('dev::logs.status.poll_interrupted')),
                paused: @js(Lang::get('dev::logs.status.paused')),
                copyFailedPrefix: @js(Lang::get('dev::logs.status.copy_failed_prefix')),
                clipboardUnavailable: @js(Lang::get('dev::logs.status.clipboard_unavailable')),
            },
        })"
        x-init="start()"
        x-on:logs-truncated.window="handleTruncated()"
        class="space-y-3"
    >
        {{-- Filter row --}}
        <section class="flex flex-wrap items-center gap-3" aria-label="{{ Lang::get('dev::logs.filters_aria') }}">
            <div class="flex items-center gap-1" role="group" aria-label="{{ Lang::get('dev::logs.severity_aria') }}">
                @foreach ($allSeverities as $sev)
                    <button
                        type="button"
                        x-on:click="toggleSeverity('{{ $sev }}')"
                        x-bind:aria-pressed="severities.has('{{ $sev }}') ? 'true' : 'false'"
                        x-bind:class="severities.has('{{ $sev }}') ? 'border-slate-900 bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900 dark:border-slate-100' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800'"
                        class="inline-flex items-center gap-1.5 rounded border px-2 py-1 text-[10.5px] font-medium uppercase tracking-wide"
                        data-severity-chip="{{ $sev }}"
                    >
                        <span>{{ $sev }}</span>
                        <span
                            class="tabular-nums opacity-70"
                            x-text="formatCount(stats.today.perSeverity['{{ $sev }}'] ?? 0)"
                            data-testid="severity-count-{{ $sev }}"
                        ></span>
                    </button>
                @endforeach
            </div>

            <input
                type="text"
                x-model.debounce.250ms="channelFilter"
                placeholder="{{ Lang::get('dev::logs.channel_placeholder') }}"
                aria-label="{{ Lang::get('dev::logs.channel_aria') }}"
                class="rounded border border-slate-200 bg-white px-2 py-1 text-xs dark:bg-slate-900 dark:border-slate-700"
                data-testid="log-channel-input"
            />

            <input
                type="text"
                x-model.debounce.250ms="contains"
                placeholder="{{ Lang::get('dev::logs.contains_placeholder') }}"
                aria-label="{{ Lang::get('dev::logs.contains_aria') }}"
                class="flex-1 min-w-40 rounded border border-slate-200 bg-white px-2 py-1 text-xs dark:bg-slate-900 dark:border-slate-700"
                data-testid="log-contains-input"
            />

            <button
                type="button"
                x-on:click="togglePause()"
                x-bind:class="paused ? 'border-amber-700 bg-amber-50 text-amber-800 dark:bg-slate-900 dark:border-amber-400 dark:text-amber-400' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300'"
                class="inline-flex items-center rounded border px-3 py-1 text-xs font-medium"
                data-testid="log-pause-button"
            >
                <span x-text="paused ? @js(Lang::get('dev::logs.resume')) : @js(Lang::get('dev::logs.pause'))"></span>
            </button>
        </section>

        {{-- Stream-status indicator --}}
        <div class="text-xs text-[var(--color-text-muted)]" x-show="statusMessage" x-cloak>
            <span x-text="statusMessage"></span>
        </div>

        {{-- 10k-line scrollback. Structured rows replace the prior single-blob
             <pre>: each entry now renders as timestamp · severity chip ·
             channel · message with a severity-colored left rule. Continuation
             lines (stack traces, JSON exception payloads) fold into the
             previous entry instead of producing standalone rows. Identical
             back-to-back entries collapse into a "×N" counter so a 6-fire
             cascade reads as one row, not six. --}}
        <div
            class="h-[60vh] overflow-y-auto rounded border border-slate-200 bg-[#0b1220] text-slate-200 dark:border-slate-700"
            style="font-family: 'JetBrains Mono', ui-monospace, 'SF Mono', 'Menlo', monospace;"
            data-testid="log-scrollback"
        >
            <template x-if="visibleLines.length === 0 && !paused">
                <div class="p-3 text-xs text-slate-500">{{ Lang::get('dev::logs.waiting') }} <span class="cursor-blink">▌</span></div>
            </template>
            <template x-for="line in visibleLines" :key="line.id">
                <div
                    class="group cursor-pointer border-l-2 border-b border-b-slate-800/60 hover:bg-slate-900/60"
                    x-bind:class="severityRule(line.severity)"
                    x-on:click="toggleExpand(line)"
                    x-bind:data-severity="line.severity"
                >
                    <div class="flex items-baseline gap-2 px-2 py-1 text-[11.5px]">
                        <span class="text-[10.5px] text-slate-500 shrink-0 w-[60px] tabular-nums" x-text="shortTime(line.timestamp)"></span>
                        <span class="text-[10px] font-semibold uppercase tracking-wide shrink-0 w-[64px]" x-bind:class="severityColor(line.severity)" x-text="line.severity"></span>
                        <span class="text-[10.5px] text-slate-400 shrink-0 w-[72px] truncate" x-text="line.channel || '—'" x-bind:title="line.channel"></span>
                        {{-- A floor, not min-w-0. Every other cell in the row is
                             shrink-0, so the message was the only thing that could
                             give: at phone width (the dev shell keeps its 220px
                             sidebar, leaving ~127px of pane) it was squeezed to zero
                             and every row rendered blank. It holds its width now and
                             the pane scrolls sideways, as the wrapper intends. --}}
                        <span
                            class="text-slate-100 flex-1 min-w-60"
                            x-bind:class="line.expanded ? 'whitespace-pre-wrap break-words' : 'truncate'"
                            x-text="line.message || line.raw"
                        ></span>
                        <span
                            x-show="line.count > 1"
                            x-cloak
                            class="ml-2 text-[10px] text-slate-300 rounded bg-slate-700 px-1.5 py-0.5 shrink-0 tabular-nums"
                            x-text="'×' + line.count"
                        ></span>
                        {{-- Row actions: copy + dismiss. Visible on hover or
                             when the row is expanded so the row chrome stays
                             quiet at rest. stop.prevent on each click so the
                             button does not also toggle the expand handler
                             that lives on the row container. --}}
                        <div
                            class="shrink-0 ml-2 flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100"
                            x-bind:class="line.expanded ? 'opacity-100' : ''"
                        >
                            <button
                                type="button"
                                x-on:click.stop.prevent="copyLine(line)"
                                x-bind:title="line.copiedAt ? @js(Lang::get('dev::logs.copy_title_copied')) : @js(Lang::get('dev::logs.copy_title'))"
                                x-bind:aria-label="line.copiedAt ? @js(Lang::get('dev::logs.copy_aria_copied')) : @js(Lang::get('dev::logs.copy_aria'))"
                                class="rounded px-1.5 py-0.5 text-[10px] font-medium text-slate-300 hover:bg-slate-700 hover:text-slate-100"
                                data-testid="log-copy-button"
                            >
                                <span x-text="line.copiedAt ? '✓' : @js(Lang::get('dev::logs.copy'))"></span>
                            </button>
                            <button
                                type="button"
                                x-on:click.stop.prevent="dismissLine(line)"
                                title="{{ Lang::get('dev::logs.dismiss_title') }}"
                                aria-label="{{ Lang::get('dev::logs.dismiss_aria') }}"
                                class="rounded px-1.5 py-0.5 text-[10px] font-medium text-slate-400 hover:bg-rose-900/40 hover:text-rose-300"
                                data-testid="log-dismiss-button"
                            >
                                {{ Lang::get('dev::logs.dismiss') }}
                            </button>
                        </div>
                    </div>
                    <template x-if="line.expanded && line.continuation">
                        <div class="pl-[208px] pr-3 pb-2 text-[10.5px] text-slate-400 whitespace-pre-wrap break-words" x-text="line.continuation"></div>
                    </template>
                </div>
            </template>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-[var(--color-text-muted)]" data-testid="log-totals-strip">
            <span>
                {{ Lang::get('dev::logs.totals.showing') }} <span class="tabular-nums" x-text="visibleLines.length"></span> {{ Lang::get('dev::logs.totals.of') }}
                <span class="tabular-nums" x-text="totalReceived"></span> {{ Lang::get('dev::logs.totals.received') }}
            </span>
            <span aria-hidden="true">·</span>
            <span data-testid="log-total-lines">
                <span class="tabular-nums" x-text="stats.today.totalLines.toLocaleString()"></span>
                <template x-if="stats.today.capped">
                    <span class="text-amber-600 dark:text-amber-400">+</span>
                </template>
                {{ Lang::get('dev::logs.totals.lines_today') }}
            </span>
            <span aria-hidden="true">·</span>
            <span data-testid="log-today-size">
                <span x-text="humanBytes(stats.today.sizeBytes)"></span> {{ Lang::get('dev::logs.totals.today') }}
            </span>
            <span aria-hidden="true">·</span>
            <span data-testid="log-all-size">
                <span x-text="humanBytes(stats.allFiles.totalBytes)"></span> {{ Lang::get('dev::logs.totals.across') }}
                <span class="tabular-nums" x-text="stats.allFiles.count"></span> {{ Lang::get('dev::logs.totals.daily_files') }}
            </span>
        </div>
    </div>
    {{-- Inside the root: Livewire binds wire:id to the first top-level
         element, so anything beside it is unbound. The script also needs
         the CSP nonce — without it the page loads and does nothing. --}}

    <style>
        @keyframes cursor-blink-1s { 0%, 50% { opacity: 1; } 51%, 100% { opacity: 0; } }
        .cursor-blink { animation: cursor-blink-1s 1s steps(1) infinite; }
    </style>

    <script nonce="{{ Vite::cspNonce() }}">
        /* global Alpine */
        if (typeof window !== 'undefined' && !window.__devLogTailerRegistered) {
            window.__devLogTailerRegistered = true;
            document.addEventListener('alpine:init', () => {
                Alpine.data('logTailer', (opts) => ({
                    pollUrl: opts.pollUrl,
                    contextUrl: opts.contextUrl,
                    statsUrl: opts.statsUrl,
                    severities: new Set(opts.initialSeverities || []),
                    contains: opts.initialContains || '',
                    channelFilter: opts.initialChannel || '',
                    // Server-rendered status strings so the tailer's client-side
                    // notices honour the request's resolved UI language.
                    labels: opts.labels || {},
                    buffer: [],
                    paused: false,
                    statusMessage: '',
                    totalReceived: 0,
                    nextLineId: 1,
                    MAX_BUFFER: 10000,
                    // Polling cursor + rotation guard. The server returns the
                    // current inode in every response; the client passes it
                    // back next tick so the server can detect a logrotate /
                    // midnight day-rollover and signal reset.
                    offset: 0,
                    inode: null,
                    pollTimer: null,
                    pollAbort: null,
                    POLL_INTERVAL_MS: 1000,
                    // Stats polling — slower cadence because the O(N) parse
                    // server-side is not free. 3s feels live without burning
                    // the file-walker on every keystroke.
                    stats: opts.initialStats || {
                        today: { sizeBytes: 0, totalLines: 0, perSeverity: {}, capped: false },
                        allFiles: { count: 0, totalBytes: 0 },
                    },
                    statsTimer: null,
                    statsAbort: null,
                    STATS_INTERVAL_MS: 3000,

                    start() {
                        this.pollNow();
                        this.refreshStats();
                    },

                    // Alpine teardown hook — fires when the component root is
                    // removed from the DOM (wire:navigate page swap, tab close,
                    // hot reload). Stops the polling loop AND aborts any
                    // request in flight; otherwise the next tick would fetch
                    // against a torn-down component.
                    destroy() {
                        this.paused = true;
                        if (this.pollTimer) {
                            clearTimeout(this.pollTimer);
                            this.pollTimer = null;
                        }
                        if (this.pollAbort) {
                            try { this.pollAbort.abort(); } catch (e) {}
                            this.pollAbort = null;
                        }
                        if (this.statsTimer) {
                            clearTimeout(this.statsTimer);
                            this.statsTimer = null;
                        }
                        if (this.statsAbort) {
                            try { this.statsAbort.abort(); } catch (e) {}
                            this.statsAbort = null;
                        }
                    },

                    async pollNow() {
                        if (this.paused) { return; }
                        if (this.pollTimer) {
                            clearTimeout(this.pollTimer);
                            this.pollTimer = null;
                        }
                        this.pollAbort = new AbortController();
                        try {
                            const params = new URLSearchParams({ since: String(this.offset) });
                            if (this.inode !== null) { params.set('inode', String(this.inode)); }
                            const res = await fetch(`${this.pollUrl}?${params.toString()}`, {
                                headers: { 'Accept': 'application/json' },
                                signal: this.pollAbort.signal,
                            });
                            if (!res.ok) { throw new Error('poll-not-ok'); }
                            const body = await res.json();
                            if (body.reset === true) {
                                this.offset = 0;
                            }
                            if (typeof body.inode === 'number') {
                                this.inode = body.inode;
                            }
                            if (typeof body.chunk === 'string' && body.chunk !== '') {
                                this.ingest(body.chunk);
                            }
                            if (typeof body.newOffset === 'number') {
                                this.offset = body.newOffset;
                            }
                            this.statusMessage = '';
                        } catch (e) {
                            if (e && e.name === 'AbortError') { return; }
                            this.statusMessage = this.labels.pollInterrupted;
                        } finally {
                            this.pollAbort = null;
                        }
                        if (!this.paused) {
                            this.pollTimer = setTimeout(() => this.pollNow(), this.POLL_INTERVAL_MS);
                        }
                    },

                    ingest(chunk) {
                        // The chunk may contain multiple newline-separated lines.
                        const lines = chunk.split(/\r?\n/);
                        for (const raw of lines) {
                            if (raw === '') { continue; }
                            const parsed = this.parseLine(raw);
                            if (parsed === null) {
                                // Continuation line (stack trace row, JSON exception
                                // payload tail) — fold into the previous entry's
                                // expandable continuation. If the buffer is empty
                                // we have nothing to attach to; drop silently.
                                if (this.buffer.length > 0) {
                                    const tail = this.buffer[this.buffer.length - 1];
                                    tail.continuation = tail.continuation
                                        ? tail.continuation + '\n' + raw
                                        : raw;
                                }
                                this.totalReceived++;
                                continue;
                            }
                            // Collapse identical back-to-back entries (same severity
                            // + same message + no expansion yet) into a single
                            // counter row. The 30s-die LogStreamController loop and
                            // similar tight repeats would otherwise spam six near-
                            // identical lines in a row; one row + "×6" is far
                            // easier to read.
                            if (this.buffer.length > 0) {
                                const tail = this.buffer[this.buffer.length - 1];
                                if (
                                    tail.severity === parsed.severity
                                    && tail.channel === parsed.channel
                                    && tail.message === parsed.message
                                    && tail.continuation === ''
                                ) {
                                    tail.count++;
                                    tail.timestamp = parsed.timestamp;
                                    this.totalReceived++;
                                    continue;
                                }
                            }
                            this.buffer.push(parsed);
                            this.totalReceived++;
                            if (this.buffer.length > this.MAX_BUFFER) {
                                this.buffer.shift();
                            }
                        }
                    },

                    parseLine(raw) {
                        // Standard Laravel format:
                        //   "[2026-05-24 10:00:00] local.INFO: message body"
                        // Returns null for any line that does NOT match — the
                        // caller treats those as continuation lines.
                        const m = raw.match(/^\[([^\]]+)\]\s+([a-z0-9_]+)\.([A-Z]+):\s*(.*)$/i);
                        if (!m) { return null; }
                        return {
                            id: this.nextLineId++,
                            raw: raw,
                            timestamp: m[1],
                            channel: m[2],
                            severity: m[3].toUpperCase(),
                            message: m[4],
                            continuation: '',
                            count: 1,
                            expanded: false,
                            // null until the user clicks Copy — then a timestamp
                            // that the button uses to flip its label to ✓ for
                            // ~1.5s before reverting. Per-line so multiple
                            // adjacent copies don't collide.
                            copiedAt: null,
                        };
                    },

                    get visibleLines() {
                        const containsLower = this.contains.toLowerCase();
                        return this.buffer.filter((l) => {
                            if (this.severities.size > 0 && !this.severities.has(l.severity)) { return false; }
                            if (this.channelFilter && !l.channel.includes(this.channelFilter)) { return false; }
                            if (this.contains) {
                                const hay = (l.message + ' ' + l.continuation).toLowerCase();
                                if (!hay.includes(containsLower)) { return false; }
                            }
                            return true;
                        });
                    },

                    toggleSeverity(sev) {
                        if (this.severities.has(sev)) { this.severities.delete(sev); } else { this.severities.add(sev); }
                        // Re-evaluate visibleLines via Alpine reactivity (Set mutation
                        // is not reactive; trigger via re-assignment).
                        this.severities = new Set(this.severities);
                    },

                    togglePause() {
                        this.paused = !this.paused;
                        if (this.paused) {
                            if (this.pollTimer) {
                                clearTimeout(this.pollTimer);
                                this.pollTimer = null;
                            }
                            if (this.pollAbort) {
                                try { this.pollAbort.abort(); } catch (e) {}
                                this.pollAbort = null;
                            }
                            this.statusMessage = this.labels.paused;
                        } else {
                            this.statusMessage = '';
                            this.pollNow();
                        }
                    },

                    severityColor(sev) {
                        switch (sev) {
                            case 'DEBUG': case 'INFO': return 'text-slate-400';
                            case 'NOTICE': return 'text-slate-200';
                            case 'WARNING': return 'text-amber-400';
                            case 'ERROR': case 'CRITICAL': case 'ALERT': case 'EMERGENCY': return 'text-rose-400';
                            default: return 'text-slate-200';
                        }
                    },

                    severityRule(sev) {
                        // Tailwind class for the left-rule color so a glance down
                        // the column flags severity without reading text.
                        switch (sev) {
                            case 'DEBUG': case 'INFO': return 'border-slate-700';
                            case 'NOTICE': return 'border-slate-500';
                            case 'WARNING': return 'border-amber-500';
                            case 'ERROR': case 'CRITICAL': case 'ALERT': case 'EMERGENCY': return 'border-rose-500';
                            default: return 'border-slate-700';
                        }
                    },

                    shortTime(ts) {
                        // Laravel timestamps are "YYYY-MM-DD HH:MM:SS" — strip the
                        // date for the row chrome; the date is the same for every
                        // row in the daily-rotated file so showing it inline
                        // wastes column width.
                        if (typeof ts !== 'string' || ts === '') { return ''; }
                        const idx = ts.indexOf(' ');
                        return idx >= 0 ? ts.slice(idx + 1) : ts;
                    },

                    toggleExpand(line) {
                        line.expanded = !line.expanded;
                    },

                    // Build a plain-text version of the entry suitable for
                    // pasting into a chat / bug report. Includes the
                    // continuation block (stack trace, JSON payload) when
                    // present, so a single click captures the full failure
                    // context, not just the headline.
                    renderLineForClipboard(line) {
                        const head = '[' + line.timestamp + '] '
                            + line.channel + '.' + line.severity + ': '
                            + (line.message || line.raw);
                        return line.continuation
                            ? head + '\n' + line.continuation
                            : head;
                    },

                    async copyLine(line) {
                        const text = this.renderLineForClipboard(line);
                        try {
                            // This fallback used to live here alone; it is now
                            // window.beatraxCopy(), because every other copy
                            // button in the app needed the same thing and had
                            // a bare navigator.clipboard guard instead.
                            if (! await window.beatraxCopy(text)) {
                                throw new Error('clipboard unavailable');
                            }

                            line.copiedAt = Date.now();
                            // Revert the ✓ label after the user has had time
                            // to register it. Captured `line` reference is the
                            // same object Alpine renders, so the assignment
                            // triggers a re-render.
                            setTimeout(() => { line.copiedAt = null; }, 1500);
                        } catch (e) {
                            this.statusMessage = this.labels.copyFailedPrefix + (e && e.message ? e.message : this.labels.clipboardUnavailable);
                        }
                    },

                    // Hide-from-view only. The on-disk log file is untouched —
                    // this is a client-side buffer prune so a noisy row can be
                    // cleared from a screenshare without scrolling past it. A
                    // fresh poll that re-fetches the same offset will NOT
                    // resurrect the row because the tail offset advances; the
                    // row reappears only on an explicit page reload that
                    // restarts the buffer from scratch.
                    dismissLine(line) {
                        const idx = this.buffer.findIndex((l) => l.id === line.id);
                        if (idx >= 0) {
                            this.buffer.splice(idx, 1);
                        }
                    },

                    // Stats poller — runs on its own slower timer so the
                    // O(N) file parse cost does not piggy-back on the 1 Hz
                    // tail poll.
                    async refreshStats() {
                        if (this.statsTimer) {
                            clearTimeout(this.statsTimer);
                            this.statsTimer = null;
                        }
                        this.statsAbort = new AbortController();
                        try {
                            const res = await fetch(this.statsUrl, {
                                headers: { 'Accept': 'application/json' },
                                signal: this.statsAbort.signal,
                            });
                            if (!res.ok) { throw new Error('stats-not-ok'); }
                            const body = await res.json();
                            if (body && typeof body === 'object') {
                                this.stats = body;
                            }
                        } catch (e) {
                            if (e && e.name === 'AbortError') { return; }
                            // Stats failures are non-fatal — the tail keeps
                            // flowing, the chip counts simply stop updating
                            // until the next refresh succeeds. No status
                            // message: the operator would see it spam on
                            // every retry under a flaky network.
                        } finally {
                            this.statsAbort = null;
                        }
                        this.statsTimer = setTimeout(() => this.refreshStats(), this.STATS_INTERVAL_MS);
                    },

                    // Truncate-button handler. Livewire fires the server-side
                    // truncate, then dispatches a `logs:truncated` window
                    // event we listen to here. Zero the local cursor + buffer
                    // immediately so the operator sees the visual reset
                    // without waiting for the next tail-poll round-trip.
                    handleTruncated() {
                        this.buffer = [];
                        this.totalReceived = 0;
                        this.offset = 0;
                        this.inode = null;
                        this.statusMessage = '';
                        this.refreshStats();
                        if (!this.paused) {
                            this.pollNow();
                        }
                    },

                    // Compact count renderer for severity chips — keeps the
                    // chip narrow when counts grow into the thousands
                    // ("1.2k", "5.4M") rather than expanding the row.
                    formatCount(n) {
                        if (typeof n !== 'number' || !isFinite(n) || n < 0) { return '0'; }
                        if (n < 1000) { return String(n); }
                        if (n < 1000000) { return (n / 1000).toFixed(n < 10000 ? 1 : 0) + 'k'; }
                        return (n / 1000000).toFixed(1) + 'M';
                    },

                    // Mirrors the server-side LogTailerPage::humanBytes() so
                    // the truncate toast wording and the totals-strip render
                    // use the same units.
                    humanBytes(bytes) {
                        const BYTES_PER_UNIT = 1024;
                        if (typeof bytes !== 'number' || !isFinite(bytes) || bytes <= 0) { return '0 B'; }
                        if (bytes < BYTES_PER_UNIT) { return bytes + ' B'; }
                        const kb = bytes / BYTES_PER_UNIT;
                        if (kb < BYTES_PER_UNIT) { return kb.toFixed(1) + ' KB'; }
                        const mb = kb / BYTES_PER_UNIT;
                        if (mb < BYTES_PER_UNIT) { return mb.toFixed(1) + ' MB'; }
                        return (mb / BYTES_PER_UNIT).toFixed(2) + ' GB';
                    },
                }));
            });
        }
    </script>

</div>
