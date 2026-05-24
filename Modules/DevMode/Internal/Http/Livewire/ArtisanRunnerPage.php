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
        // Belt-and-braces reset: ResetAdvancedToggleOnLogin clears the
        // session key on every Login event; this clears it on first
        // dev-console load per session in case the Login event was
        // missed (rehydrated tab, long-lived sticky session, etc.).
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
