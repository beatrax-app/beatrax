@use('Modules\Core\Public\Support\Lang')
{{-- UI-SPEC §19: overflow-x-auto wrapper ensures the artisan runner
     timeline and run cards scroll horizontally at phone width. --}}
<div class="p-8 space-y-6 overflow-x-auto" data-testid="artisan-runner-page">
    <header class="flex items-center justify-between gap-4">
        <div class="space-y-1">
            <h1 class="text-xl font-semibold text-[var(--color-text)]">{{ Lang::get('dev::runner.heading') }}</h1>
            <p class="text-sm text-[var(--color-text-muted)]">{{ Lang::get('dev::runner.subtitle') }}</p>
        </div>
        <flux:button
            type="button"
            x-data
            x-on:click="$dispatch('palette:open')"
            class="inline-flex items-center"
        >
            <kbd class="mr-2 text-xs">⌘K</kbd>
            {{ Lang::get('dev::runner.run_a_command') }}
        </flux:button>
    </header>

    <div class="flex items-center gap-4">
        {{-- Filter chips --}}
        <div class="flex items-center gap-1" role="tablist" aria-label="{{ Lang::get('dev::runner.filter_aria') }}">
            @foreach ([
                'all' => Lang::get('dev::runner.filter.all'),
                'running' => Lang::get('dev::runner.filter.running'),
                'failed' => Lang::get('dev::runner.filter.failed'),
                'destructive' => Lang::get('dev::runner.filter.destructive'),
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
                <x-dev::status-pill variant="ok" :label="Lang::get('dev::runner.worker_running')" />
            @else
                <x-dev::status-pill variant="muted" :label="Lang::get('dev::runner.worker_not_running')" />
            @endif
        </div>
    </div>

    {{-- Day-section timeline of run-cards. Each card renders a
         finished run from the audit log; live in-flight cards
         stream stdout via the SSE pipeline and finalize through
         the FinalizeRunAudit hook. --}}
    @if ($runs->isEmpty())
        <div class="card p-4">
            <p class="text-sm text-[var(--color-text-muted)]">
                {{ Lang::get('dev::runner.no_runs') }}
            </p>
        </div>
    @else
        <section class="space-y-3" aria-label="{{ Lang::get('dev::runner.recent_runs_aria') }}">
            @foreach ($runs as $run)
                <x-dev::run-card :run="$run" />
            @endforeach
        </section>
    @endif

    {{--
        Fallback Flux modal — SAFE-tier commands ONLY. DESTRUCTIVE
        commands are deliberately omitted from this surface to
        prevent muscle-memory disasters; the palette excludes them
        for the same reason. First-time DESTRUCTIVE runs reach the
        surface via `php artisan` on the CLI; subsequent runs reach
        it via the timeline's per-row Re-run affordance, which
        routes through TripleGateModal.

        The exclusion is load-bearing — do not add destructive
        commands to this modal. ArtisanRunnerSafeTierTest asserts
        no destructive command name appears in the modal's HTML.
    --}}
    <flux:modal name="run-command" :dismissible="true">
        <div class="space-y-4">
            <flux:heading size="lg">{{ Lang::get('dev::runner.modal_heading') }}</flux:heading>
            <p class="text-sm text-slate-700 dark:text-slate-300">
                {{ Lang::get('dev::runner.modal_intro') }}
            </p>
            <div class="space-y-1">
                @foreach ($safeCommands as $spec)
                    @php
                        $hasArgs = count($spec->argsSchema) > 0;
                    @endphp
                    {{-- Rows for commands WITH args open the arg-prompt
                         modal so the operator can fill the form before
                         the spawn fires. No-arg rows still call
                         spawn() directly — the pre-spawn required-arg
                         guard in spawn() remains the third line of
                         defense for any path that bypasses the form.  --}}
                    <button
                        type="button"
                        @if ($hasArgs)
                            x-data
                            x-on:click="$dispatch('command-args:prompt', { name: '{{ $spec->name }}', tier: 'safe', prefill: {} })"
                        @else
                            wire:click="spawn('{{ $spec->name }}', [])"
                        @endif
                        class="block w-full rounded border border-slate-200 bg-white px-3 py-2 text-left hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:hover:bg-slate-800"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <code class="font-mono text-sm text-slate-900 dark:text-slate-100">{{ $spec->name }}</code>
                                <x-dev::tier-chip tier="safe" />
                                @if ($hasArgs)
                                    <span
                                        class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400"
                                        title="{{ Lang::get('dev::runner.args_badge_title') }}"
                                    >{{ Lang::get('dev::runner.args_badge') }}</span>
                                @endif
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
