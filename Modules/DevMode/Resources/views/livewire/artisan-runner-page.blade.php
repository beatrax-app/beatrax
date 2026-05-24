<div class="p-8 space-y-6" data-testid="artisan-runner-page">
    <header class="flex items-center justify-between gap-4">
        <div class="space-y-1">
            <h1 class="text-xl font-semibold text-[var(--color-text)]">Artisan runner</h1>
            <p class="text-sm text-[var(--color-text-muted)]">Run SAFE commands one-click; DESTRUCTIVE commands behind the triple-gate.</p>
        </div>
        <flux:button
            type="button"
            x-data
            x-on:click="$dispatch('palette:open')"
            class="inline-flex items-center"
        >
            <kbd class="mr-2 text-xs">⌘K</kbd>
            Run a command
        </flux:button>
    </header>

    <div class="flex items-center gap-4">
        {{-- Filter chips --}}
        <div class="flex items-center gap-1" role="tablist" aria-label="Run filter">
            @foreach ([
                'all' => 'All',
                'running' => 'Running',
                'failed' => 'Failed',
                'destructive' => 'Destructive',
            ] as $key => $label)
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $filter === $key ? 'true' : 'false' }}"
                    wire:click="setFilter('{{ $key }}')"
                    class="inline-flex items-center rounded border px-3 py-1 text-xs font-medium {{ $filter === $key ? 'border-slate-900 bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900 dark:border-slate-100' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' }}"
                >{{ $label }}</button>
            @endforeach
        </div>

        {{-- Worker pre-flight pill --}}
        <div class="ml-auto">
            @if ($workerAlive)
                <x-dev::status-pill variant="ok" label="Queue worker: RUNNING" />
            @else
                <x-dev::status-pill variant="muted" label="Queue worker: NOT RUNNING" />
            @endif
        </div>
    </div>

    {{-- Day-section timeline of run-cards.
         B-5: this plan ships the rendering shell + the SAFE/DESTRUCTIVE
         re-run wiring; live in-flight cards stream via 16-04's SSE
         pipeline + finalize through 16-04b's FinalizeRunAudit hook. --}}
    @if ($runs->isEmpty())
        <div class="card p-4">
            <p class="text-sm text-[var(--color-text-muted)]">
                No runs yet. Click "Run a command" or use the command palette (⌘K).
            </p>
        </div>
    @else
        <section class="space-y-3" aria-label="Recent runs">
            @foreach ($runs as $run)
                <x-dev::run-card :run="$run" />
            @endforeach
        </section>
    @endif

    {{--
        Fallback Flux modal — B-2 fix critical: SAFE-tier commands ONLY.
        DESTRUCTIVE commands are deliberately omitted from this surface
        per D-41 (palette excludes DESTRUCTIVE to prevent muscle-memory
        disasters). First-time DESTRUCTIVE runs are reachable via the
        palette (16-08) or `php artisan` CLI; subsequent DESTRUCTIVE
        runs are reachable via the timeline's per-row Re-run affordance
        which routes through TripleGateModal.

        The comment above is load-bearing — keeps future maintainers
        from "fixing" the perceived gap by adding destructive commands
        to this modal. Test 3 (ArtisanRunnerSafeTierTest) asserts no
        destructive command name appears in the modal's HTML.
    --}}
    <flux:modal name="run-command" :dismissible="true">
        <div class="space-y-4">
            <flux:heading size="lg">Run a SAFE command</flux:heading>
            <p class="text-sm text-slate-700 dark:text-slate-300">
                Pick a SAFE-tier command to run immediately. DESTRUCTIVE commands are not listed here — use the timeline's Re-run affordance or the ⌘K palette.
            </p>
            <div class="space-y-1">
                @foreach ($safeCommands as $spec)
                    <button
                        type="button"
                        wire:click="spawn('{{ $spec->name }}', [])"
                        class="block w-full rounded border border-slate-200 bg-white px-3 py-2 text-left hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:hover:bg-slate-800"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <code class="font-mono text-sm text-slate-900 dark:text-slate-100">{{ $spec->name }}</code>
                                <x-dev::tier-chip tier="safe" />
                            </div>
                            <span class="text-xs text-slate-500 dark:text-slate-400">{{ $spec->label }}</span>
                        </div>
                        @if ($spec->description !== null && $spec->description !== '')
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $spec->description }}</p>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    </flux:modal>
</div>
