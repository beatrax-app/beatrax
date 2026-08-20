<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\DevMode\Internal\Audit\FinalizeRunAudit;
use Modules\DevMode\Internal\Listeners\WriteWorkerHeartbeat;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\ProcessLiveness;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;

#[Layout('dev::layouts.dev-shell')]
final class ArtisanRunnerPage extends Component
{
    use DispatchesToast;

    #[Url(as: 'filter', except: 'all')]
    public string $filter = 'all';

    public function mount(
        Session $session,
        CommandSpawner $spawner,
        CurrentUser $user,
        DevCommandRegistry $registry,
        ?string $spawn = null,
    ): void {
        // Belt-and-braces reset: ResetAdvancedToggleOnLogin clears the
        // toggle on Login, but session-resume (no Login refire) can
        // carry a stale Advanced=true across requests. This closes
        // that gap on the first /dev/* navigation per session.
        if (! $session->has('dev_mode.advanced_session_seen')) {
            $session->forget('dev_mode.advanced');
            $session->put('dev_mode.advanced_session_seen', true);
        }

        // Carry across a palette-picked spawn: ?spawn=<name> fires the
        // same spawn() guard immediately so a pick made off-page
        // produces the same outcome as one made on /dev/artisan.
        if (is_string($spawn) && $spawn !== '') {
            $this->spawn($spawn, [], $spawner, $user, $registry);
        }
    }

    public function setFilter(string $filter): void
    {
        if (! in_array($filter, ['all', 'running', 'failed', 'destructive'], true)) {
            return;
        }
        $this->filter = $filter;
    }

    // Looks the command up in the registry, routes DESTRUCTIVE rows to
    // the triple-gate (defense-in-depth — a hostile client could still
    // POST a destructive name even though the fallback modal excludes
    // them), and otherwise spawns the SAFE command directly.
    /**
     * @param  array<string, mixed>  $args
     */
    public function spawn(
        string $command,
        array $args,
        CommandSpawner $spawner,
        CurrentUser $user,
        DevCommandRegistry $registry,
    ): void {
        try {
            $spec = $registry->find($command);
        } catch (\InvalidArgumentException) {
            $this->toast(Lang::get('dev::runner.toast.unknown_command', ['command' => $command]));

            return;
        }

        if ($spec->tier !== 'safe') {
            $this->dispatch('triple-gate:open', command: $command, args: $args);

            return;
        }

        // Some SAFE-tier registry entries declare REQUIRED args (e.g.
        // `config:show {config}`); a palette pick dispatches `args: []`,
        // so without this guard Symfony Console aborts with no surface
        // visible to the operator. Refuse + toast the missing key(s).
        $missing = $this->missingRequiredArgs($spec->argsSchema, $args);
        if ($missing !== []) {
            $this->toast(
                Lang::get('dev::runner.toast.missing_args', [
                    'command' => $command,
                    'noun' => count($missing) === 1
                        ? Lang::get('dev::runner.toast.arg_singular')
                        : Lang::get('dev::runner.toast.arg_plural'),
                    'list' => implode(', ', $missing),
                ]),
            );

            return;
        }

        $runId = $spawner->start($command, $args, $user->id(), 'safe');
        $this->toast(Lang::get('dev::runner.toast.started', ['command' => $command, 'runId' => $runId]));
    }

    /**
     * @param  list<ArgSpec>  $schema
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    private function missingRequiredArgs(array $schema, array $args): array
    {
        $missing = [];
        foreach ($schema as $arg) {
            if (! in_array('required', $arg->rules, true)) {
                continue;
            }
            $value = $args[$arg->name] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $arg->label !== '' ? $arg->label : $arg->name;
            }
        }

        return $missing;
    }

    // Browser-event sink for palette-driven spawns while already on
    // /dev/artisan (the off-page path routes through mount()'s
    // ?spawn=<name> instead). Routes through spawn() so the
    // SAFE-vs-DESTRUCTIVE registry check stays in one place.
    /**
     * @param  array<string, mixed>  $args
     */
    #[On('spawn-command')]
    public function onSpawnCommand(
        string $name,
        array $args,
        string $tier,
        CommandSpawner $spawner,
        CurrentUser $user,
        DevCommandRegistry $registry,
    ): void {
        // `$tier` is informational only — the authoritative tier
        // lookup happens inside spawn() via the registry, so a
        // hostile dispatch that claims `tier: 'safe'` for a
        // destructive command still routes through the triple-gate.
        unset($tier);

        $this->spawn($name, $args, $spawner, $user, $registry);
    }

    // A destructive re-run pops the triple-gate carrying the original
    // command + args; a safe re-run spawns immediately. Unknown/expired
    // records (24h cache TTL) silently no-op — the row stays visible as
    // history but the cache no longer has the spawn payload.
    public function rerun(
        string $runId,
        RunRegistry $registry,
        CommandSpawner $spawner,
        CurrentUser $user,
    ): void {
        $record = $registry->find($runId);
        if ($record === null) {
            $this->toast(Lang::get('dev::runner.toast.run_expired'));

            return;
        }

        if ($record->tier === 'destructive') {
            $this->dispatch(
                'triple-gate:open',
                command: $record->command,
                args: $record->args,
            );

            return;
        }

        $newRunId = $spawner->start($record->command, $record->args, $user->id(), 'safe');
        $this->toast(Lang::get('dev::runner.toast.reran', ['command' => $record->command, 'runId' => $newRunId]));
    }

    public function render(
        ViewFactory $views,
        CurrentUser $user,
        DevCommandRegistry $registry,
        CacheRepository $cache,
        Clock $clock,
        DatabaseManager $db,
        RunRegistry $runRegistry,
        ProcessLiveness $liveness,
        FinalizeRunAudit $finalize,
    ): View {
        $userId = $user->id();

        // Every spawn writes an eager audit row with exit_code=null;
        // the SSE done branch updates it in place, but a row stays
        // "running" forever if the operator never opens the stream.
        // Sweep once per render and finalize any PID that already exited.
        $this->sweepPendingRuns($db, $userId, $runRegistry, $liveness, $finalize);

        // Raw query builder via DatabaseManager sidesteps the
        // Eloquent\Builder __call forwarding that triggers
        // larastan-strict staticMethod.dynamicCall on limit()/whereIn();
        // same dev_mode_audit table the custom Activity model targets.
        $audit = $db->connection()->table('dev_mode_audit')
            ->where('log_name', 'dev_mode')
            ->where('causer_id', $userId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $runs = $audit->map(fn (object $row): array => $this->mapAuditRow($row));

        // Apply the filter chip. `running` now genuinely matches in-flight
        // rows (the eager audit write plus the per-render sweep keep them
        // current); `failed` keeps only non-zero exit codes.
        $filtered = match ($this->filter) {
            'running' => $runs->where('status', 'running')->values(),
            'failed' => $runs->filter(fn (array $r): bool => is_int($r['exitCode'] ?? null) && $r['exitCode'] !== 0)->values(),
            'destructive' => $runs->where('tier', 'destructive')->values(),
            default => $runs,
        };

        return $views->make('dev::livewire.artisan-runner-page', [
            'runs' => $filtered,
            'safeCommands' => $registry->safe(),
            'workerAlive' => $this->workerIsAlive($cache, $clock),
            'filter' => $this->filter,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAuditRow(object $row): array
    {
        $vars = get_object_vars($row);
        $properties = $this->decodeProperties($vars['properties'] ?? null);

        $exitCode = is_int($properties['exit_code'] ?? null) ? $properties['exit_code'] : null;
        $finishedAt = is_string($properties['finished_at'] ?? null) ? $properties['finished_at'] : null;
        $argsRaw = $properties['args'] ?? null;
        $args = is_array($argsRaw) ? $argsRaw : [];
        $cancelled = ($args['__cancelled'] ?? false) === true;
        unset($args['__cancelled']);

        return [
            'runId' => $this->resolveRunId($properties, $vars),
            'command' => is_string($properties['command'] ?? null) ? $properties['command'] : '',
            'args' => $args,
            'tier' => is_string($properties['tier'] ?? null) ? $properties['tier'] : 'safe',
            'status' => $this->runStatus($cancelled, $exitCode, $finishedAt),
            'startedAt' => $this->resolveStartedAt($properties, $vars),
            'exitCode' => $exitCode,
            'excerpt' => is_string($properties['stdout_excerpt'] ?? null) ? $properties['stdout_excerpt'] : null,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeProperties(mixed $rawProperties): array
    {
        $decoded = is_string($rawProperties) ? json_decode($rawProperties, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    // Pending eager-write (no exit, no finish) → 'running' so the RunCard
    // opens the SSE live tail; a cancel marker wins outright, and anything
    // else carrying a finish marker → 'done'.
    private function runStatus(bool $cancelled, ?int $exitCode, ?string $finishedAt): string
    {
        return match (true) {
            $cancelled => 'cancelled',
            $exitCode === null && $finishedAt === null => 'running',
            default => 'done',
        };
    }

    // Prefer the spawn-time UUID so data-run-id matches the SSE route
    // argument; fall back to the audit row id for pre-eager-write rows
    // (already finalized, excerpt inline).
    /**
     * @param  array<array-key, mixed>  $properties
     * @param  array<array-key, mixed>  $vars
     */
    private function resolveRunId(array $properties, array $vars): string
    {
        $runIdFromProps = is_string($properties['run_id'] ?? null) ? $properties['run_id'] : '';
        if ($runIdFromProps !== '') {
            return $runIdFromProps;
        }

        $idRaw = $vars['id'] ?? null;

        return match (true) {
            is_int($idRaw) => (string) $idRaw,
            is_string($idRaw) => $idRaw,
            default => '',
        };
    }

    // startedAt prefers the spawn-time clock value baked into properties;
    // falls back to row->created_at when the eager write predates it.
    /**
     * @param  array<array-key, mixed>  $properties
     * @param  array<array-key, mixed>  $vars
     */
    private function resolveStartedAt(array $properties, array $vars): ?string
    {
        $startedAtIso = is_string($properties['started_at'] ?? null) ? $properties['started_at'] : null;
        if ($startedAtIso !== null) {
            return $startedAtIso;
        }

        $createdAtRaw = $vars['created_at'] ?? null;

        return is_string($createdAtRaw)
            ? CarbonImmutable::parse($createdAtRaw)->toIso8601String()
            : null;
    }

    private function workerIsAlive(CacheRepository $cache, Clock $clock): bool
    {
        $heartbeatTs = $cache->get(WriteWorkerHeartbeat::CACHE_KEY);
        $heartbeatTs = is_int($heartbeatTs) ? $heartbeatTs : null;

        return $heartbeatTs !== null
            && $heartbeatTs > ($clock->now()->getTimestamp() - WriteWorkerHeartbeat::TTL_SECONDS);
    }

    // Caps at 25 rows per render. A row whose RunRegistry entry has
    // TTL'd (>24h) stays pending in the timeline — the sweep cannot
    // finalize what it has no PID for, so it degenerates gracefully
    // into "running" UI rather than writing an unjustified finish marker.
    private function sweepPendingRuns(
        DatabaseManager $db,
        int $userId,
        RunRegistry $runRegistry,
        ProcessLiveness $liveness,
        FinalizeRunAudit $finalize,
    ): void {
        $pending = $db->connection()->table('dev_mode_audit')
            ->where('log_name', 'dev_mode')
            ->where('causer_id', $userId)
            ->whereNull('properties->finished_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        foreach ($pending as $row) {
            $vars = get_object_vars($row);
            $propertiesRaw = $vars['properties'] ?? null;
            if (! is_string($propertiesRaw)) {
                continue;
            }
            $decoded = json_decode($propertiesRaw, true);
            if (! is_array($decoded)) {
                continue;
            }

            $runIdRaw = $decoded['run_id'] ?? null;
            if (! is_string($runIdRaw) || $runIdRaw === '') {
                continue;
            }

            $record = $runRegistry->find($runIdRaw);
            if ($record === null) {
                continue;
            }
            if ($liveness->isAlive($record->pid)) {
                continue;
            }

            // PID is dead — finalize on the operator's behalf so the
            // row stops being a forever-running phantom. Pass
            // exitCode=null because the bash detach lost it; the
            // run-card renders "exit ?" for that case.
            ($finalize)($runIdRaw, null, false);
        }
    }
}
