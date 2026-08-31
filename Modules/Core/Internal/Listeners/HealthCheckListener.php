<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Query\Builder;
use Modules\Core\Internal\Console\Probes\BootProbeState;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\Instant;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\StoredCopy;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class HealthCheckListener
{
    public function __construct(
        private BootProbeState $state,
        private Clock $clock,
        private LoggerInterface $logger,
        private DatabaseManager $db,
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
                SafeExceptionContext::describe($e),
            );

            return;
        }

        // Every writer of system_alerts.created_at goes through SystemAlert,
        // whose $timestamps stamp it off the app clock rather than letting the
        // schema's CURRENT_TIMESTAMP default write UTC. The dedup cutoff is
        // built in that same frame.
        $cutoff = Instant::appLocal($this->clock->now()->subHour());

        if ($journalMode !== 'wal') {
            $this->recordDriftAlert(
                kind: 'wal_mode_missing',
                line: CopyLine::of('core::alerts.messages.wal_mode_missing', ['mode' => $journalMode]),
                logMessage: sprintf("SQLite is not in WAL mode (currently '%s').", $journalMode),
                metadata: ['current_mode' => $journalMode],
                cutoff: $cutoff,
            );
        }

        if ($synchronousLevel !== 1) {
            $this->recordDriftAlert(
                kind: 'synchronous_misconfigured',
                line: CopyLine::of('core::alerts.messages.synchronous_misconfigured', ['level' => $synchronousLevel]),
                logMessage: sprintf('SQLite synchronous level is %d (expected NORMAL/1).', $synchronousLevel),
                metadata: ['current_level' => $synchronousLevel],
                cutoff: $cutoff,
            );
        }

        $this->state->booted = true;
    }

    // Two sentences for one fault, because they are read in two places: the
    // log line is English for whoever greps it, and the stored line follows
    // whichever household member opens the banner months later.
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    private function recordDriftAlert(
        string $kind,
        CopyLine $line,
        string $logMessage,
        array $metadata,
        string $cutoff,
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
                'severity' => SystemAlertSeverity::Warning->value,
                'message' => $line->sentence(),
                'metadata' => StoredCopy::inParams($line) + $metadata,
            ]);

            $this->logger->warning('HealthCheckListener: '.$logMessage, $metadata);
        } catch (Throwable $e) {
            $this->logger->warning(
                'HealthCheckListener: failed to write '.$kind.' alert; continuing.',
                SafeExceptionContext::describe($e),
            );
        }
    }
}
