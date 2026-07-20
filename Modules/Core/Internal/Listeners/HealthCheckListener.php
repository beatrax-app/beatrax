<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Query\Builder;
use Modules\Core\Internal\Console\Probes\BootProbeState;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Boot-time SQLite PRAGMA drift listener. On the FIRST `sqlite`
 * `ConnectionEstablished` event in the process, reads
 * `PRAGMA journal_mode` and `PRAGMA synchronous`. If either value
 * drifts from the documented defaults (WAL active + synchronous=1
 * NORMAL), writes a system-wide `system_alerts` row at warning
 * severity AND emits a structured log warning. The persistent
 * banner picks the row up on the next dashboard render.
 *
 * Two-layer de-duplication:
 *  1. `BootProbeState` (container singleton) gates re-firing within
 *     the same process — once the listener has done its work, the
 *     flag suppresses additional ConnectionEstablished events.
 *  2. 1-hour recency window against existing unacknowledged rows of
 *     the same kind suppresses cross-process duplicates so booting
 *     the app 100x in an hour produces at most one row per kind.
 *
 * The listener NEVER throws — every IO/SQL touchpoint sits inside a
 * try/catch. A misconfigured PRAGMA must not lock the user out of
 * the app; the persistent banner is the recovery path.
 *
 * Lives as a dedicated invokable class so the
 * `HealthCheckServiceProvider::boot()` body shrinks to a single
 * `$events->listen()` call and the listener body is testable in
 * isolation via constructor DI rather than reflection-on-closure.
 */
final class HealthCheckListener
{
    public function __construct(
        private readonly BootProbeState $state,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(ConnectionEstablished $event): void
    {
        $connection = $event->connection;

        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        if ($this->state->booted) {
            return;
        }

        try {
            $journalRaw = $connection->scalar('PRAGMA journal_mode');
            $journalMode = is_string($journalRaw) ? strtolower($journalRaw) : '';
            $synchronousRaw = $connection->scalar('PRAGMA synchronous');
            $synchronousLevel = is_numeric($synchronousRaw) ? (int) $synchronousRaw : -1;
        } catch (Throwable $e) {
            // PRAGMA read failed — log + bail without halting boot or
            // marking $state->booted (so a healthy subsequent connection
            // still has a chance to run the check).
            $this->logger->warning(
                'HealthCheckListener: PRAGMA read failed; skipping drift check.',
                ['exception' => $e::class, 'message' => $e->getMessage()],
            );

            return;
        }

        // SQLite's `useCurrent()` / CURRENT_TIMESTAMP writes the column in
        // UTC, while CarbonImmutable::now() returns the app's configured
        // timezone. Convert the cutoff to UTC before serialising into the
        // recency query so the comparison uses the same wall-clock frame.
        $cutoff = $this->clock->now()->subHour()->setTimezone('UTC');

        if ($journalMode !== 'wal') {
            $this->recordDriftAlert(
                kind: 'wal_mode_missing',
                message: sprintf("SQLite is not in WAL mode (currently '%s').", $journalMode),
                metadata: ['current_mode' => $journalMode],
                cutoff: $cutoff,
            );
        }

        if ($synchronousLevel !== 1) {
            $this->recordDriftAlert(
                kind: 'synchronous_misconfigured',
                message: sprintf('SQLite synchronous level is %d (expected NORMAL/1).', $synchronousLevel),
                metadata: ['current_level' => $synchronousLevel],
                cutoff: $cutoff,
            );
        }

        $this->state->booted = true;
    }

    /**
     * Writes a `system_alerts` row at warning severity IF no
     * unacknowledged row of the same kind already exists within the
     * cutoff window. The recency-check query uses the raw Query
     * Builder rather than Eloquent — the project's
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
        string $kind,
        string $message,
        array $metadata,
        CarbonImmutable $cutoff,
    ): void {
        try {
            $recentExists = $this->db->connection()->table('system_alerts')
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

            $this->logger->warning('HealthCheckListener: '.$message, $metadata);
        } catch (Throwable $e) {
            $this->logger->warning(
                'HealthCheckListener: failed to write '.$kind.' alert; continuing.',
                ['exception' => $e::class, 'message' => $e->getMessage()],
            );
        }
    }
}
