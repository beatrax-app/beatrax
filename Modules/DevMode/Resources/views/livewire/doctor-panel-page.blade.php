@use('Modules\Core\Public\Support\Lang')
{{-- UI-SPEC §19: overflow-x-auto wrapper ensures the doctor panel
     probe rows scroll horizontally at phone width. --}}
<div class="p-6 space-y-6 overflow-x-auto" data-testid="doctor-panel-page">
    <header class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <h1 class="text-xl font-semibold text-[var(--color-text)]">{{ Lang::get('dev::doctor.heading') }}</h1>
            <p class="text-sm text-[var(--color-text-muted)]">
                {{ Lang::get('dev::doctor.subtitle') }}
            </p>
        </div>
        <x-core::neutral-button
            class="disabled:cursor-not-allowed disabled:opacity-50"
            data-testid="doctor-rerun-button"
            x-data="{ running: false }"
            x-on:click="
                running = true;
                fetch('/dev/artisan/spawn', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=&quot;csrf-token&quot;]').getAttribute('content')
                    },
                    body: JSON.stringify({ command: '{{ $commandName }}', args: {} })
                }).then(r => r.json()).then(d => {
                    if (d.run_id) {
                        const es = new EventSource('/dev/artisan/stream/' + d.run_id);
                        es.addEventListener('done', () => { es.close(); window.location.reload(); });
                        es.onerror = () => { es.close(); window.location.reload(); };
                    } else {
                        running = false;
                        if (d.message) {
                            window.dispatchEvent(new CustomEvent('toast', { detail: { message: d.message } }));
                        }
                    }
                }).catch(() => { running = false; });
            "
            x-bind:disabled="running"
        >
            <span x-show="!running">{{ Lang::get('dev::doctor.rerun') }}</span>
            <span x-show="running" x-cloak>{{ Lang::get('dev::doctor.running') }}</span>
        </x-core::neutral-button>
    </header>

    @if ($probeRows === [])
        <div class="card p-6 text-center" data-testid="doctor-empty-state">
            <p class="text-sm text-[var(--color-text-muted)]">
                {{ Lang::get('dev::doctor.empty_prefix') }}
                <span class="font-semibold">{{ Lang::get('dev::doctor.empty_rerun') }}</span>
                {{ Lang::get('dev::doctor.empty_suffix') }} <code class="font-mono">{{ $commandName }}</code>.
            </p>
        </div>
    @else
        <div class="card p-4" data-testid="doctor-results-card">
            <div class="flex flex-wrap items-center justify-between mb-3">
                <h2 class="text-sm font-semibold">{{ Lang::get('dev::doctor.latest_output') }}</h2>
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
                                <span role="img" class="text-emerald-600" aria-label="{{ Lang::get('dev::doctor.aria_pass') }}">✓</span>
                                @break
                            @case('warn')
                                <span role="img" class="text-amber-500" aria-label="{{ Lang::get('dev::doctor.aria_warning') }}">⚠</span>
                                @break
                            @case('fail')
                                <span role="img" class="text-rose-600" aria-label="{{ Lang::get('dev::doctor.aria_fail') }}">✗</span>
                                @break
                            @default
                                <span role="img" class="text-slate-500" aria-label="{{ Lang::get('dev::doctor.aria_info') }}">ℹ</span>
                        @endswitch
                        <span class="font-mono text-xs w-48 truncate">{{ $row['label'] }}</span>
                        <span class="text-[var(--color-text-muted)] flex-1">{{ $row['detail'] }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($exitCode !== null && $exitCode !== 0)
                <p class="mt-4 text-xs text-rose-600">
                    {{ Lang::get('dev::doctor.exit_code', ['code' => $exitCode]) }}
                </p>
            @endif
        </div>
    @endif
</div>
