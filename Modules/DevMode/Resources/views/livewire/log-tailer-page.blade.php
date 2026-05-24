<div class="p-8 space-y-4" data-testid="log-tailer-page">
    <header class="space-y-1">
        <h1 class="text-xl font-semibold text-[var(--color-text)]">Logs</h1>
        <p class="text-sm text-[var(--color-text-muted)]">
            Live tail of the current day's Laravel log file with belt-and-braces on-write + on-stream redaction.
        </p>
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
            initialSeverities: @js($severityList),
            initialContains: @js($contains),
            initialChannel: @js($channel),
        })"
        x-init="start()"
        class="space-y-3"
    >
        {{-- Filter row --}}
        <div class="flex flex-wrap items-center gap-3" role="region" aria-label="Log filters">
            <div class="flex items-center gap-1" role="group" aria-label="Severity filter">
                @foreach ($allSeverities as $sev)
                    <button
                        type="button"
                        x-on:click="toggleSeverity('{{ $sev }}')"
                        x-bind:aria-pressed="severities.has('{{ $sev }}') ? 'true' : 'false'"
                        x-bind:class="severities.has('{{ $sev }}') ? 'border-slate-900 bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900 dark:border-slate-100' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800'"
                        class="inline-flex items-center rounded border px-2 py-1 text-[10.5px] font-medium uppercase tracking-wide"
                        data-severity-chip="{{ $sev }}"
                    >{{ $sev }}</button>
                @endforeach
            </div>

            <input
                type="text"
                x-model.debounce.250ms="channelFilter"
                placeholder="Channel filter…"
                aria-label="Channel filter"
                class="rounded border border-slate-200 bg-white px-2 py-1 text-xs dark:bg-slate-900 dark:border-slate-700"
                data-testid="log-channel-input"
            />

            <input
                type="text"
                x-model.debounce.250ms="contains"
                placeholder="Search visible…"
                aria-label="Contains filter"
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
                <span x-text="paused ? 'Resume' : 'Pause'"></span>
            </button>
        </div>

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
                <div class="p-3 text-xs text-slate-500">Waiting for log lines… <span class="cursor-blink">▌</span></div>
            </template>
            <template x-for="line in visibleLines" :key="line.id">
                <div
                    class="cursor-pointer border-l-2 border-b border-b-slate-800/60 hover:bg-slate-900/60"
                    x-bind:class="severityRule(line.severity)"
                    x-on:click="toggleExpand(line)"
                    x-bind:data-severity="line.severity"
                >
                    <div class="flex items-baseline gap-2 px-2 py-1 text-[11.5px]">
                        <span class="text-[10.5px] text-slate-500 shrink-0 w-[60px] tabular-nums" x-text="shortTime(line.timestamp)"></span>
                        <span class="text-[10px] font-semibold uppercase tracking-wide shrink-0 w-[64px]" x-bind:class="severityColor(line.severity)" x-text="line.severity"></span>
                        <span class="text-[10.5px] text-slate-400 shrink-0 w-[72px] truncate" x-text="line.channel || '—'" x-bind:title="line.channel"></span>
                        <span
                            class="text-slate-100 flex-1 min-w-0"
                            x-bind:class="line.expanded ? 'whitespace-pre-wrap break-words' : 'truncate'"
                            x-text="line.message || line.raw"
                        ></span>
                        <span
                            x-show="line.count > 1"
                            x-cloak
                            class="ml-2 text-[10px] text-slate-300 rounded bg-slate-700 px-1.5 py-0.5 shrink-0 tabular-nums"
                            x-text="'×' + line.count"
                        ></span>
                    </div>
                    <template x-if="line.expanded && line.continuation">
                        <div class="pl-[208px] pr-3 pb-2 text-[10.5px] text-slate-400 whitespace-pre-wrap break-words" x-text="line.continuation"></div>
                    </template>
                </div>
            </template>
        </div>

        <div class="text-xs text-[var(--color-text-muted)]">
            Showing <span x-text="visibleLines.length"></span> of <span x-text="totalReceived"></span> received lines (buffer capped at 10,000).
        </div>
    </div>
</div>

<style>
    [x-cloak] { display: none !important; }
    @keyframes cursor-blink-1s { 0%, 50% { opacity: 1; } 51%, 100% { opacity: 0; } }
    .cursor-blink { animation: cursor-blink-1s 1s steps(1) infinite; }
</style>

<script>
    /* global Alpine */
    if (typeof window !== 'undefined' && !window.__devLogTailerRegistered) {
        window.__devLogTailerRegistered = true;
        document.addEventListener('alpine:init', () => {
            Alpine.data('logTailer', (opts) => ({
                pollUrl: opts.pollUrl,
                contextUrl: opts.contextUrl,
                severities: new Set(opts.initialSeverities || []),
                contains: opts.initialContains || '',
                channelFilter: opts.initialChannel || '',
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

                start() {
                    this.pollNow();
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
                        this.statusMessage = 'Log poll interrupted. Retrying…';
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
                        this.statusMessage = 'Paused.';
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
            }));
        });
    }
</script>
