<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Internal\Console\Probes\BootProbeState;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Boot-time SQLite PRAGMA verifier. Listens to Laravel's
 * `ConnectionEstablished` event and, on the FIRST `sqlite` connection
 * opened in the process, reads `PRAGMA journal_mode` + `PRAGMA
 * synchronous`. If either value drifts from the documented defaults
 * (WAL active + synchronous = 1 / NORMAL), the listener writes a
 * system-wide `system_alerts(wal_mode_missing | synchronous_misconfigured,
 * warning)` row AND emits a structured log warning.
 *
 * The listener NEVER halts boot: every IO/SQL touchpoint sits inside a
 * try/catch and failures are logged + swallowed. A misconfigured
 * PRAGMA must not lock the user out of the app — the persistent banner
 * (Phase 11-05) is the user-visible recovery path.
 *
 * De-duplication is two-layer:
 *  1. `BootProbeState` (container singleton) gates re-firing within the
 *     same process — once the listener has done its work, the flag
 *     suppresses additional ConnectionEstablished events.
 *  2. `SystemAlert::query()->...->where('created_at', '>=', $clock->now()->subHour())`
 *     suppresses cross-process duplicates so booting the app 100x in
 *     an hour produces at most one row per kind.
 *
 * The provider is registered through `CoreServiceProvider::register()`
 * alongside the existing `SqliteOptimizationsProvider`. The `boot()`
 * signature accepts `Dispatcher` + `BootProbeState` via Laravel's
 * method-arg resolution; the listener body captures the state object
 * by reference so it survives across multiple events.
 */
final class HealthCheckServiceProvider extends ServiceProvider
{
    public function boot(Dispatcher $events, BootProbeState $state): void
    {
        $app = $this->app;

        $provider = $this;
        $events->listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use ($state, $app, $provider): void {
            $connection = $event->connection;

            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }

            if ($state->booted) {
                return;
            }

            try {
                $journalRaw = $connection->scalar('PRAGMA journal_mode');
                $journalMode = is_string($journalRaw) ? strtolower($journalRaw) : '';
                $synchronousRaw = $connection->scalar('PRAGMA synchronous');
                $synchronousLevel = is_numeric($synchronousRaw) ? (int) $synchronousRaw : -1;
            } catch (Throwable $e) {
                // Reading the PRAGMA failed — log + bail without
                // halting boot or marking $state->booted (so a healthy
                // subsequent connection still has a chance to run the
                // check).
                $app->make(LoggerInterface::class)->warning(
                    'HealthCheckServiceProvider: PRAGMA read failed; skipping drift check.',
                    ['exception' => $e::class, 'message' => $e->getMessage()],
                );

                return;
            }

            $clock = $app->make(Clock::class);
            $logger = $app->make(LoggerInterface::class);
            $cutoff = $clock->now()->subHour();

            $db = $app->make(DatabaseManager::class);

            if ($journalMode !== 'wal') {
                $provider->recordDriftAlert(
                    db: $db,
                    kind: 'wal_mode_missing',
                    message: sprintf("SQLite is not in WAL mode (currently '%s').", $journalMode),
                    metadata: ['current_mode' => $journalMode],
                    cutoff: $cutoff,
                    logger: $logger,
                );
            }

            if ($synchronousLevel !== 1) {
                $provider->recordDriftAlert(
                    db: $db,
                    kind: 'synchronous_misconfigured',
                    message: sprintf('SQLite synchronous level is %d (expected NORMAL/1).', $synchronousLevel),
                    metadata: ['current_level' => $synchronousLevel],
                    cutoff: $cutoff,
                    logger: $logger,
                );
            }

            $state->booted = true;
        });
    }

    /**
     * Writes a `system_alerts` row at warning severity IF no
     * unacknowledged row of the same kind already exists within the
     * cutoff window. The recency-check query uses raw `DatabaseManager`
     * Query Builder rather than Eloquent — the project's
     * larastan-strict-rules profile rejects chained Eloquent\Builder
     * calls (whereNull / where / exists) after Model::query(). The
     * write itself uses Eloquent so the timestamp casts + fillable
     * filtering apply.
     *
     * Every IO touchpoint is wrapped in try/catch so a write failure
     * logs + continues instead of halting boot.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    private function recordDriftAlert(
        DatabaseManager $db,
        string $kind,
        string $message,
        array $metadata,
        CarbonImmutable $cutoff,
        LoggerInterface $logger,
    ): void {
        try {
            $recentExists = $db->connection()->table('system_alerts')
                ->where('kind', $kind)
                ->where(static function (Builder $q): void {
                    $q->whereNull('acknowledged_at');
                })
                ->where('created_at', '>=', $cutoff)
                ->exists();

            if ($recentExists) {
                return;
            }

            SystemAlert::create([
                'user_id' => null,
                'kind' => $kind,
                'severity' => 'warning',
                'message' => $message,
                'metadata' => $metadata,
            ]);

            $logger->warning('HealthCheckServiceProvider: '.$message, $metadata);
        } catch (Throwable $e) {
            $logger->warning(
                'HealthCheckServiceProvider: failed to write '.$kind.' alert; continuing.',
                ['exception' => $e::class, 'message' => $e->getMessage()],
            );
        }
    }
}
