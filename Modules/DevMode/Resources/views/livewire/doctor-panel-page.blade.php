{{-- D-20 / UI-SPEC §19: overflow-x-auto wrapper ensures the doctor panel
     probe rows scroll horizontally at phone width. --}}
<div class="p-6 space-y-6 overflow-x-auto" data-testid="doctor-panel-page">
    <header class="flex items-center justify-between gap-4">
        <div class="space-y-1">
            <h1 class="text-xl font-semibold text-[var(--color-text)]">Doctor</h1>
            <p class="text-sm text-[var(--color-text-muted)]">
                Run the operational probe suite and review pass / warn / fail rows.
            </p>
        </div>
        <button
            type="button"
            class="pill-btn primary"
            data-testid="doctor-rerun-button"
            x-data="{ running: false }"
            x-on:click="
                running = true;
                fetch('/dev/artisan/spawn', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']').getAttribute('content')
                    },
                    body: JSON.stringify({ command: 'beatrax:doctor', args: {} })
                }).then(r => r.json()).then(d => {
                    if (d.run_id) {
                        const es = new EventSource('/dev/artisan/stream/' + d.run_id);
                        es.addEventListener('done', () => { es.close(); window.location.reload(); });
                        es.onerror = () => { es.close(); window.location.reload(); };
                    } else {
                        running = false;
                    }
                }).catch(() => { running = false; });
            "
            x-bind:disabled="running"
        >
            <span x-show="!running">Re-run</span>
            <span x-show="running" x-cloak>Running…</span>
        </button>
    </header>

    @if ($probeRows === [])
        <div class="card p-6 text-center" data-testid="doctor-empty-state">
            <p class="text-sm text-[var(--color-text-muted)]">
                No probe output captured yet. Press
                <span class="font-semibold">Re-run</span>
                to invoke <code class="font-mono">beatrax:doctor</code>.
            </p>
        </div>
    @else
        <div class="card p-4" data-testid="doctor-results-card">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold">Latest probe output</h2>
                @if ($finishedAt !== null)
                    <span class="text-xs text-[var(--color-text-muted)]">{{ $finishedAt }}</span>
                @endif
            </div>
            <ul class="space-y-1.5">
                @foreach ($probeRows as $row)
                    <li
                        class="flex items-baseline gap-3 text-sm"
                        data-probe-status="{{ $row['status'] }}"
                    >
                        @switch($row['status'])
                            @case('pass')
                                <span class="text-emerald-600" aria-label="pass">✓</span>
                                @break
                            @case('warn')
                                <span class="text-amber-500" aria-label="warning">⚠</span>
                                @break
                            @case('fail')
                                <span class="text-rose-600" aria-label="fail">✗</span>
                                @break
                            @default
                                <span class="text-slate-500" aria-label="info">ℹ</span>
                        @endswitch
                        <span class="font-mono text-xs w-48 truncate">{{ $row['label'] }}</span>
                        <span class="text-[var(--color-text-muted)] flex-1">{{ $row['detail'] }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($exitCode !== null && $exitCode !== 0)
                <p class="mt-4 text-xs text-rose-600">
                    Exit code: {{ $exitCode }}
                </p>
            @endif
        </div>
    @endif
</div>
