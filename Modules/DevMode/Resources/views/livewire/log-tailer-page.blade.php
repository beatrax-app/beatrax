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
            streamUrl: '{{ route('dev.logs.stream') }}',
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

        {{-- 10k-line scrollback. Alpine maintains the ring buffer client-side. --}}
        <pre
            class="h-[60vh] overflow-y-auto rounded border border-slate-200 bg-[#0b1220] p-3 font-mono text-xs leading-relaxed text-slate-200 dark:border-slate-700"
            style="font-family: 'JetBrains Mono', ui-monospace, 'SF Mono', 'Menlo', monospace; font-size: 12px;"
            data-testid="log-scrollback"
        >
            <template x-if="visibleLines.length === 0 && !paused">
                <span class="text-slate-500">Waiting for log lines… <span class="cursor-blink">▌</span></span>
            </template>
            <template x-for="line in visibleLines" :key="line.id">
                <span
                    class="block whitespace-pre-wrap break-words"
                    x-on:click="expandContext(line)"
                >
                    <span
                        x-bind:class="severityColor(line.severity)"
                        x-text="line.formatted"
                    ></span>
                    <template x-if="line.context">
                        <span class="mt-1 block border-l-2 border-slate-600 pl-2 text-slate-400">
                            <template x-for="ctx in line.context" :key="ctx.index">
                                <span class="block" x-text="'   ' + ctx.text"></span>
                            </template>
                        </span>
                    </template>
                </span>
            </template>
        </pre>

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
                streamUrl: opts.streamUrl,
                contextUrl: opts.contextUrl,
                severities: new Set(opts.initialSeverities || []),
                contains: opts.initialContains || '',
                channelFilter: opts.initialChannel || '',
                buffer: [],
                paused: false,
                statusMessage: '',
                totalReceived: 0,
                source: null,
                nextLineId: 1,
                retryDelay: 250,
                MAX_BUFFER: 10000,

                start() {
                    this.openStream();
                },

                openStream() {
                    if (this.source) {
                        try { this.source.close(); } catch (e) {}
                    }
                    this.statusMessage = '';
                    try {
                        this.source = new EventSource(this.streamUrl);
                    } catch (e) {
                        this.statusMessage = 'Log stream interrupted. Reconnecting…';
                        this.scheduleReconnect();
                        return;
                    }
                    this.source.onmessage = (ev) => {
                        let payload;
                        try { payload = JSON.parse(ev.data); } catch (e) { return; }
                        if (!payload || typeof payload.line !== 'string') { return; }
                        this.ingest(payload.line);
                    };
                    this.source.onerror = () => {
                        this.statusMessage = 'Log stream interrupted. Reconnecting…';
                        try { this.source.close(); } catch (e) {}
                        this.source = null;
                        this.scheduleReconnect();
                    };
                    this.retryDelay = 250;
                },

                scheduleReconnect() {
                    if (this.paused) { return; }
                    setTimeout(() => { if (!this.paused) { this.openStream(); } }, this.retryDelay);
                    this.retryDelay = Math.min(this.retryDelay * 2, 5000);
                },

                ingest(chunk) {
                    // The chunk may contain multiple newline-separated lines.
                    const lines = chunk.split(/\r?\n/);
                    for (const raw of lines) {
                        if (raw === '') { continue; }
                        const parsed = this.parseLine(raw);
                        this.buffer.push(parsed);
                        this.totalReceived++;
                        if (this.buffer.length > this.MAX_BUFFER) {
                            this.buffer.shift();
                        }
                    }
                },

                parseLine(raw) {
                    // Standard Laravel format: "[2026-05-24 10:00:00] local.INFO: message"
                    const m = raw.match(/^\[([^\]]+)\]\s+([a-z0-9_]+)\.([A-Z]+):/i);
                    return {
                        id: this.nextLineId++,
                        raw: raw,
                        formatted: raw,
                        timestamp: m ? m[1] : '',
                        channel: m ? m[2] : '',
                        severity: m ? m[3].toUpperCase() : 'INFO',
                        context: null,
                    };
                },

                get visibleLines() {
                    return this.buffer.filter((l) => {
                        if (this.severities.size > 0 && !this.severities.has(l.severity)) { return false; }
                        if (this.channelFilter && !l.channel.includes(this.channelFilter)) { return false; }
                        if (this.contains && !l.raw.toLowerCase().includes(this.contains.toLowerCase())) { return false; }
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
                        if (this.source) {
                            try { this.source.close(); } catch (e) {}
                            this.source = null;
                        }
                        this.statusMessage = 'Paused.';
                    } else {
                        this.openStream();
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

                async expandContext(line) {
                    if (line.context) { line.context = null; return; }
                    const lineIndex = this.buffer.indexOf(line);
                    if (lineIndex < 0) { return; }
                    // Use the line's index within the visible buffer as a
                    // surrogate for the file offset — the controller's
                    // SplFileObject computes the real line-by-line cursor.
                    try {
                        const url = `${this.contextUrl}?line=${encodeURIComponent(lineIndex)}&radius=10`;
                        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) { return; }
                        const body = await res.json();
                        line.context = body.lines || [];
                    } catch (e) {
                        line.context = [];
                    }
                },
            }));
        });
    }
</script>
