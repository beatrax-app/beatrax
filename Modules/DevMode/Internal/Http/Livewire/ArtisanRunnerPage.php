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
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Listeners\WriteWorkerHeartbeat;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;

/**
 * `/dev/artisan` runner page (CONTEXT D-25).
 *
 * Page composition (UI-SPEC § Artisan timeline):
 *   - Header: "Artisan runner" title + primary "⌘K Run a command"
 *     CTA (placeholder — 16-08 wires the palette).
 *   - Filter chips row: All | Running | Failed | Destructive
 *     (persisted via #[Url] query string for back-button + bookmarks).
 *   - Worker pre-flight pill: reads cache `dev_mode.queue_worker_heartbeat`
 *     and shows green when fresh (< now()-60s), muted otherwise.
 *   - Day-section timeline of run-cards.
 *   - Fallback Flux modal — SAFE-tier commands ONLY (B-2 fix; the
 *     DESTRUCTIVE commands are deliberately NOT exposed here per
 *     D-41 to prevent muscle-memory disasters; first-time DESTRUCTIVE
 *     runs reach the surface via the palette or `php artisan` CLI;
 *     subsequent runs via the timeline's per-row Re-run affordance
 *     which routes through the triple-gate).
 *
 * Method-DI on render() per PATTERN B.
 *
 * mount() resets `dev_mode.advanced` on first-load-per-session as a
 * belt-and-braces (ResetAdvancedToggleOnLogin already covers the Login
 * event itself; this guards a long-lived browser tab that resumed an
 * old session without re-firing Login).
 */
#[Layout('dev::layouts.dev-shell')]
final class ArtisanRunnerPage extends Component
{
    /** Filter chip: all | running | failed | destructive. */
    #[Url(as: 'filter', except: 'all')]
    public string $filter = 'all';

    public function mount(Session $session): void
    {
        // Belt-and-braces Advanced-toggle reset on first dev-console
        // load per session.
        //
        // The ResetAdvancedToggleOnLogin listener clears the toggle on
        // every Illuminate\Auth\Events\Login event. Login fires on
        // explicit credential auth AND on remember-me cookie hydration
        // (SessionGuard::user() fires Login from the recaller branch),
        // but does NOT fire on session-resume where the user id is
        // already in the session — that branch fires only the
        // Authenticated event. A long-lived sticky session that
        // resumed without re-firing Login can therefore carry an
        // Advanced=true value from a previous request unless something
        // else clears it. This mount() reset closes that gap on the
        // first /dev/* navigation per session.
        if (! $session->has('dev_mode.advanced_session_seen')) {
            $session->forget('dev_mode.advanced');
            $session->put('dev_mode.advanced_session_seen', true);
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
            // Unknown command — surface a toast but never spawn.
            $this->dispatch('toast', message: 'Unknown command: '.$command);

            return;
        }

        if ($spec->tier !== 'safe') {
            $this->dispatch('triple-gate:open', command: $command, args: $args);

            return;
        }

        $runId = $spawner->start($command, $args, $user->id(), 'safe');
        $this->dispatch('toast', message: 'Started '.$command.' (run '.$runId.')');
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
    ): View {
        $userId = $user->id();

        // Use the raw query builder via DatabaseManager — this
        // sidesteps the Eloquent\Builder __call → Query\Builder
        // forwarding that triggers larastan-strict
        // `staticMethod.dynamicCall` flags on `limit()` / `whereIn()`.
        // Equivalent semantics; same dev_mode_audit table (named
        // explicitly here since the custom Activity model is the only
        // place that knows the table name override).
        $audit = $db->connection()->table('dev_mode_audit')
            ->where('log_name', 'dev_mode')
            ->where('causer_id', $userId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $runs = $audit->map(function (object $row): array {
            $propertiesRaw = is_string($row->properties) ? json_decode($row->properties, true) : null;
            $properties = is_array($propertiesRaw) ? $propertiesRaw : [];

            $command = is_string($properties['command'] ?? null) ? $properties['command'] : '';
            $tier = is_string($properties['tier'] ?? null) ? $properties['tier'] : 'safe';
            $exitCode = is_int($properties['exit_code'] ?? null) ? $properties['exit_code'] : null;
            $argsRaw = $properties['args'] ?? null;
            $args = is_array($argsRaw) ? $argsRaw : [];
            $cancelled = ($args['__cancelled'] ?? false) === true;
            $excerpt = is_string($properties['stdout_excerpt'] ?? null) ? $properties['stdout_excerpt'] : null;
            unset($args['__cancelled']);

            $status = $cancelled ? 'cancelled' : 'done';

            $createdAt = is_string($row->created_at)
                ? CarbonImmutable::parse($row->created_at)->toIso8601String()
                : null;

            $idRaw = $row->id;
            $runId = match (true) {
                is_int($idRaw) => (string) $idRaw,
                is_string($idRaw) => $idRaw,
                default => '',
            };

            return [
                'runId' => $runId,
                'command' => $command,
                'args' => $args,
                'tier' => $tier,
                'status' => $status,
                'startedAt' => $createdAt,
                'exitCode' => $exitCode,
                'excerpt' => $excerpt,
            ];
        });

        // Apply filter chip. Note: audit-row replays cannot be in the
        // `running` state (the audit row is written only on the done
        // branch); the `running` chip is reserved for the live-stream
        // cards a future 16-04b/16-05 iteration may surface.
        $filtered = match ($this->filter) {
            'running' => $runs->where('status', 'running')->values(),
            'failed' => $runs->filter(fn (array $r): bool => is_int($r['exitCode'] ?? null) && $r['exitCode'] !== 0)->values(),
            'destructive' => $runs->where('tier', 'destructive')->values(),
            default => $runs,
        };

        // Pre-flight heartbeat pill.
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
}
