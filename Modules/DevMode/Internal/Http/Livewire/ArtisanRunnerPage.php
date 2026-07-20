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
use Modules\DevMode\Internal\Audit\FinalizeRunAudit;
use Modules\DevMode\Internal\Listeners\WriteWorkerHeartbeat;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\ProcessLiveness;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;

/**
 * `/dev/artisan` runner page.
 *
 * Page composition:
 *   - Header: "Artisan runner" title + primary "⌘K Run a command"
 *     CTA (dispatches `palette:open`).
 *   - Filter chips row: All | Running | Failed | Destructive
 *     (persisted via #[Url] query string for back-button +
 *     bookmarks).
 *   - Worker pre-flight pill: reads cache
 *     `dev_mode.queue_worker_heartbeat` and shows green when fresh
 *     (< now()-60s), muted otherwise.
 *   - Day-section timeline of run-cards.
 *   - Fallback Flux modal exposing SAFE-tier commands ONLY.
 *     DESTRUCTIVE commands are deliberately NOT exposed here to
 *     prevent muscle-memory disasters; first-time DESTRUCTIVE runs
 *     reach the surface via the palette or `php artisan` CLI,
 *     subsequent runs via the timeline's per-row Re-run affordance
 *     which routes through the triple-gate.
 *
 * Method-DI on render() and on the spawn() / rerun() action
 * methods — Livewire components never receive constructor DI per
 * the project's larastan-strict-rules profile.
 *
 * mount() resets `dev_mode.advanced` on first-load-per-session as
 * a belt-and-braces alongside ResetAdvancedToggleOnLogin (see
 * mount()'s inline docblock for the precise gap it covers).
 */
#[Layout('dev::layouts.dev-shell')]
final class ArtisanRunnerPage extends Component
{
    /** Filter chip: all | running | failed | destructive. */
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

    /**
     * Fallback-modal SAFE-tier spawn entry point.
     *
     * The Blade emits `wire:click="spawn('cache:clear', {})"` for each
     * SAFE-tier row. This method looks the command up in the registry,
     * routes DESTRUCTIVE rows to the triple-gate (defense-in-depth —
     * the registry filter already excludes them from the fallback
     * modal, but a hostile client could still POST a destructive name),
     * and otherwise spawns the SAFE command directly.
     *
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
            $this->dispatch('toast', message: 'Unknown command: '.$command);

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
            $this->dispatch(
                'toast',
                message: sprintf(
                    "Can't run %s — needs %s: %s",
                    $command,
                    count($missing) === 1 ? 'argument' : 'arguments',
                    implode(', ', $missing),
                ),
            );

            return;
        }

        $runId = $spawner->start($command, $args, $user->id(), 'safe');
        $this->dispatch('toast', message: 'Started '.$command.' (run '.$runId.')');
    }

    /**
     * Return the list of arg labels whose `rules` contain 'required'
     * and whose value is missing or empty in the supplied args map.
     *
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

    /**
     * Browser-event sink for palette-driven spawns.
     *
     * When the operator is already on /dev/artisan and picks a
     * SAFE-tier command from the command palette, the client-side
     * `palette()` Alpine factory in `resources/js/palette.js`
     * dispatches the `spawn-command` Livewire event with the
     * resolved name + args + tier. Before this listener existed,
     * that dispatch had no sink — the palette modal closed and
     * nothing happened (the user-visible symptom: "executing an
     * artisan command does nothing, just quits the modal"). The
     * off-page fallback path (window.location.href to
     * /dev/artisan?spawn=<name>) routed through mount(), which is
     * why running the same command from the doctor screen or a
     * cold-load to /dev/artisan worked while on-page picks did
     * not.
     *
     * Routes through spawn() so the SAFE-vs-DESTRUCTIVE registry
     * check + the toast-on-unknown path stay in one place. The
     * payload's `args` and `tier` are reserved for future palette
     * shapes that pre-fill an arg form — today the palette only
     * dispatches SAFE-tier names with no args.
     *
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

    /**
     * Per-row Re-run entry point.
     *
     * The audit-log row's id maps 1:1 to a RunRegistry record. A
     * destructive re-run pops the triple-gate carrying the original
     * command + args so the operator confirms again; a safe re-run
     * spawns immediately. Unknown / expired records (24h cache TTL)
     * silently no-op — the row is still visible on the timeline as a
     * historical entry but the cache no longer has the spawn payload.
     */
    public function rerun(
        string $runId,
        RunRegistry $registry,
        CommandSpawner $spawner,
        CurrentUser $user,
    ): void {
        $record = $registry->find($runId);
        if ($record === null) {
            $this->dispatch('toast', message: 'Run record expired — cannot re-run.');

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
        $this->dispatch('toast', message: 'Re-ran '.$record->command.' (run '.$newRunId.')');
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

        $runs = $audit->map(function (object $row): array {
            $vars = get_object_vars($row);
            $propertiesRaw = $vars['properties'] ?? null;
            $decoded = is_string($propertiesRaw) ? json_decode($propertiesRaw, true) : null;
            $properties = is_array($decoded) ? $decoded : [];

            $command = is_string($properties['command'] ?? null) ? $properties['command'] : '';
            $tier = is_string($properties['tier'] ?? null) ? $properties['tier'] : 'safe';
            $exitCode = is_int($properties['exit_code'] ?? null) ? $properties['exit_code'] : null;
            $finishedAt = is_string($properties['finished_at'] ?? null) ? $properties['finished_at'] : null;
            $argsRaw = $properties['args'] ?? null;
            $args = is_array($argsRaw) ? $argsRaw : [];
            $cancelled = ($args['__cancelled'] ?? false) === true;
            $excerpt = is_string($properties['stdout_excerpt'] ?? null) ? $properties['stdout_excerpt'] : null;
            unset($args['__cancelled']);

            // Pending eager-write (no exit, no finish) → 'running' so
            // the RunCard opens the SSE live tail; anything else with
            // a finish marker → 'done'.
            $status = match (true) {
                $cancelled => 'cancelled',
                $exitCode === null && $finishedAt === null => 'running',
                default => 'done',
            };

            // Prefer the spawn-time UUID so data-run-id matches the SSE
            // route argument; fall back to the audit row id for
            // pre-eager-write rows (already finalized, excerpt inline).
            $runIdFromProps = is_string($properties['run_id'] ?? null) ? $properties['run_id'] : '';
            $idRaw = $vars['id'] ?? null;
            $runId = $runIdFromProps !== '' ? $runIdFromProps : match (true) {
                is_int($idRaw) => (string) $idRaw,
                is_string($idRaw) => $idRaw,
                default => '',
            };

            // startedAt prefers the spawn-time clock value baked
            // into properties; falls back to row->created_at.
            $startedAtIso = is_string($properties['started_at'] ?? null)
                ? $properties['started_at']
                : null;
            if ($startedAtIso === null) {
                $createdAtRaw = $vars['created_at'] ?? null;
                $startedAtIso = is_string($createdAtRaw)
                    ? CarbonImmutable::parse($createdAtRaw)->toIso8601String()
                    : null;
            }

            return [
                'runId' => $runId,
                'command' => $command,
                'args' => $args,
                'tier' => $tier,
                'status' => $status,
                'startedAt' => $startedAtIso,
                'exitCode' => $exitCode,
                'excerpt' => $excerpt,
            ];
        });

        // Apply filter chip. `running` now genuinely matches in-flight
        // rows (eager audit + sweep keeps them current).
        $filtered = match ($this->filter) {
            'running' => $runs->where('status', 'running')->values(),
            'failed' => $runs->filter(fn (array $r): bool => is_int($r['exitCode'] ?? null) && $r['exitCode'] !== 0)->values(),
            'destructive' => $runs->where('tier', 'destructive')->values(),
            default => $runs,
        };

        $heartbeatTs = $cache->get(WriteWorkerHeartbeat::CACHE_KEY);
        $heartbeatTs = is_int($heartbeatTs) ? $heartbeatTs : null;
        $workerAlive = $heartbeatTs !== null
            && $heartbeatTs > ($clock->now()->getTimestamp() - WriteWorkerHeartbeat::TTL_SECONDS);

        return $views->make('dev::livewire.artisan-runner-page', [
            'runs' => $filtered,
            'safeCommands' => $registry->safe(),
            'workerAlive' => $workerAlive,
            'filter' => $this->filter,
        ]);
    }

    /**
     * Walk pending audit rows (eager-write rows whose finish columns
     * are still empty) for the current user and finalize any whose
     * underlying child process has already exited. Caps at 25 rows
     * per render so a pathological state never blows the budget.
     *
     * The lookup matches by `properties.run_id` (the UUID
     * CommandSpawner generates), which RunRegistry stores under the
     * same key. A row whose RunRegistry entry has TTL'd (>24h)
     * stays pending in the timeline — the sweep cannot finalize what
     * it has no PID for. That degenerates gracefully into "running"
     * UI for a stale row, which is preferable to silently writing a
     * finish marker we can't justify.
     */
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
