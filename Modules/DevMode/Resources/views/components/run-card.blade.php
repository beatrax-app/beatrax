{{-- Run card primitive. One per row in the timeline.

     Props:
       :run  — array-shape with {runId, command, args, tier,
               status (running|done|cancelled|failed), startedAt,
               exitCode (?int), excerpt (?string)}

     States per UI-SPEC § Artisan timeline:
       running   — status-pill[variant=warn] + Cancel button
       done      — status-pill[variant=ok]   + Re-run button + show-output toggle
       cancelled — status-pill[variant=muted] + Re-run button
       failed    — status-pill[variant=fail] + Re-run button + show-output toggle
--}}
@use('Modules\Core\Public\Support\Lang')
@use('Modules\DevMode\Internal\Enums\CommandTier')
@props(['run'])
@php
    $runId = $run['runId'] ?? '';
    $command = (string) ($run['command'] ?? '');
    $tier = $run['tier'] ?? CommandTier::Safe;
    $status = $run['status'] ?? 'done';
    $args = $run['args'] ?? [];
    $exitCode = $run['exitCode'] ?? null;
    $excerpt = $run['excerpt'] ?? null;
    $startedAt = $run['startedAt'] ?? null;

    $statusVariant = match ($status) {
        'running' => 'warn',
        'done' => 'ok',
        'cancelled' => 'muted',
        'failed' => 'fail',
        default => 'muted',
    };
    $statusLabel = match ($status) {
        'running' => Lang::get('dev::runner.status.running'),
        'done' => Lang::get('dev::runner.status.done'),
        'cancelled' => Lang::get('dev::runner.status.cancelled'),
        'failed' => Lang::get('dev::runner.status.failed'),
        default => ucfirst($status),
    };
@endphp
{{-- wire:key, because these cards are a filtered list: without one the morph
     matches them by position, so setFilter/rerun/spawn can lay another run's
     card over this one and hand the live <pre> a different run's id. --}}
<article
    wire:key="run-card-{{ $runId }}"
    class="card p-3 space-y-2"
    data-run-id="{{ $runId }}"
    data-run-tier="{{ $tier->value }}"
    data-run-status="{{ $status }}"
>
    <header class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-2 min-w-0">
            <x-dev::status-pill :variant="$statusVariant" :label="$statusLabel" />
            <code class="font-mono text-sm truncate text-slate-900 dark:text-slate-100">
                php artisan {{ $command }}
                @foreach ($args as $key => $value)
                    @if (str_starts_with((string) $key, '--'))
                        {{ $key }}={{ $value }}
                    @else
                        {{ $value }}
                    @endif
                @endforeach
            </code>
            <x-dev::tier-chip :tier="$tier" />
        </div>
        <div class="flex items-center gap-2 shrink-0">
            @if ($status === 'running')
                <x-core::secondary-button
                    size="sm"
                    wire:click="cancel('{{ $runId }}')"
                >{{ Lang::get('dev::runner.cancel') }}</x-core::secondary-button>
            @else
                @if ($tier === CommandTier::Destructive)
                    <button
                        type="button"
                        x-data
                        x-on:click="$dispatch('triple-gate:open', { command: @js($command), args: @js($args) })"
                        class="inline-flex items-center rounded border border-rose-300 bg-white px-3 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50 dark:bg-slate-900 dark:border-rose-700 dark:text-rose-300 dark:hover:bg-rose-900/30"
                    >{{ Lang::get('dev::runner.rerun') }}</button>
                @else
                    <x-core::secondary-button
                        size="sm"
                        wire:click="rerun('{{ $runId }}')"
                    >{{ Lang::get('dev::runner.rerun') }}</x-core::secondary-button>
                @endif
            @endif
        </div>
    </header>

    <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
        <div class="tabular-nums">
            @if ($startedAt)
                {{ Lang::get('dev::runner.started', ['when' => \Carbon\CarbonImmutable::parse($startedAt)->diffForHumans()]) }}
            @endif
        </div>
        <div class="tabular-nums">
            @if ($status === 'done' || $status === 'failed')
                {{ Lang::get('dev::runner.exit') }}
                <span class="@if ($exitCode !== 0) text-rose-600 dark:text-rose-400 @endif">{{ $exitCode ?? '?' }}</span>
            @endif
        </div>
    </div>

    @if ($excerpt !== null && $excerpt !== '')
        <pre class="overflow-auto rounded bg-slate-950 px-3 py-2 text-[11px] font-mono text-slate-200 max-h-44">{{ $excerpt }}</pre>
    @elseif ($status === 'running')
        {{-- close() on all three endings, not just on `done`. A stream that ends
             without one — a spawn crash, a PHP timeout, a restarted server —
             leaves EventSource reconnecting for the life of the document, and a
             few of those exhaust what one origin is allowed to hold open. --}}
        <pre
            class="overflow-auto rounded bg-slate-950 px-3 py-2 text-[11px] font-mono text-slate-200 max-h-44"
            x-data='{
                lines: "",
                stream: null,
                init() {
                    this.stream = new EventSource("/dev/artisan/stream/" + this.$el.dataset.runId);
                    this.stream.addEventListener("message", (ev) => {
                        try { const d = JSON.parse(ev.data); this.lines += (d.line || ""); } catch (e) { this.lines += ev.data; }
                    });
                    this.stream.addEventListener("done", () => this.close());
                    this.stream.onerror = () => this.close();
                },
                destroy() { this.close(); },
                close() {
                    if (this.stream) { this.stream.close(); this.stream = null; }
                }
            }'
            data-run-id="{{ $runId }}"
            x-text="lines"
        ></pre>
    @endif
</article>
