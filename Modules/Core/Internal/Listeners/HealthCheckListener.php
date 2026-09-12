<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Query\Builder;
use Modules\Core\Internal\Backup\BackupFreshness;
use Modules\Core\Internal\Console\Probes\BootProbeState;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Services\SchemaShapeHealthCheck;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\Instant;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\StoredCopy;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class HealthCheckListener
{
    // Spelled once because raising and withdrawing have to agree: a withdrawal
    // that names a kind nothing raised closes nothing and reports success.
    private const string WAL_ALERT_KIND = 'wal_mode_missing';

    private const string SYNCHRONOUS_ALERT_KIND = 'synchronous_misconfigured';

    private const string SCHEMA_ALERT_KIND = 'schema_shape_drifted';

    public function __construct(
        private BootProbeState $state,
        private Clock $clock,
        private LoggerInterface $logger,
        private DatabaseManager $db,
        private SystemAlertWriter $alerts,
        private BackupFreshness $freshness,
        private SchemaShapeHealthCheck $schema,
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

        $hour = SystemAlertWriter::hourWindow($this->clock->now());

        if ($journalMode !== 'wal') {
            $this->recordDriftAlert(
                kind: self::WAL_ALERT_KIND,
                line: CopyLine::of('core::alerts.messages.wal_mode_missing', ['mode' => $journalMode]),
                logMessage: sprintf("SQLite is not in WAL mode (currently '%s').", $journalMode),
                metadata: ['current_mode' => $journalMode],
                cutoff: $cutoff,
                hour: $hour,
            );
        } else {
            $this->withdrawDriftAlert(self::WAL_ALERT_KIND);
        }

        if ($synchronousLevel !== 1) {
            $this->recordDriftAlert(
                kind: self::SYNCHRONOUS_ALERT_KIND,
                line: CopyLine::of('core::alerts.messages.synchronous_misconfigured', ['level' => $synchronousLevel]),
                logMessage: sprintf('SQLite synchronous level is %d (expected NORMAL/1).', $synchronousLevel),
                metadata: ['current_level' => $synchronousLevel],
                cutoff: $cutoff,
                hour: $hour,
            );
        } else {
            $this->withdrawDriftAlert(self::SYNCHRONOUS_ALERT_KIND);
        }

        $this->raiseSchemaShapeAlert($cutoff, $hour);

        $this->raiseOverdueBackupAlert();

        $this->state->booted = true;
    }

    // A schema is not what its `migrations` rows say it is. Nothing else ever
    // compares the two, so a drifted install runs indefinitely with no signal --
    // and the faults it carries are both silent by construction, which is why
    // boot is the only place a reader could hear about them.
    /**
     * @link ../../../../.docs/features/core/a-schema-the-migrations-table-vouched-for.md
     */
    private function raiseSchemaShapeAlert(string $cutoff, int $hour): void
    {
        try {
            $drift = $this->schema->drift();
        } catch (Throwable $e) {
            $this->logger->warning(
                'HealthCheckListener: sqlite_master unreadable; skipping the schema-shape check.',
                SafeExceptionContext::describe($e),
            );

            return;
        }

        $cascading = count($drift['cascading']);
        $triggers = count($drift['triggers']);

        if ($cascading === 0 && $triggers === 0) {
            $this->withdrawDriftAlert(self::SCHEMA_ALERT_KIND);

            return;
        }

        $this->recordDriftAlert(
            kind: self::SCHEMA_ALERT_KIND,
            line: CopyLine::of('core::alerts.messages.schema_shape_drifted'),
            logMessage: sprintf(
                '%d table(s) still cascade on delete and %d enum guard trigger(s) are absent.',
                $cascading,
                $triggers,
            ),
            metadata: ['cascading_tables' => $cascading, 'missing_triggers' => $triggers],
            cutoff: $cutoff,
            hour: $hour,
        );
    }

    // The daily run writes the backup; only `beatrax:doctor` ever said it had
    // stopped, and neither shipped bundle carries a terminal to run that in. The
    // copy the banner was written around -- "the app wasn't open when the daily
    // run came round" -- is earned by opening the app, so it is read here.
    /**
     * @link ../../../../.docs/features/core/architecture.md
     */
    private function raiseOverdueBackupAlert(): void
    {
        try {
            $newest = $this->freshness->newestVerifiedAt();
        } catch (Throwable $e) {
            $this->logger->warning(
                'HealthCheckListener: backups directory unreadable; skipping freshness check.',
                SafeExceptionContext::describe($e),
            );

            return;
        }

        // An install that has never backed up is not the same fault, and boot
        // cannot tell a first run from a broken one without a second fact. The
        // doctor still reports it, where a human is already looking.
        if ($newest === null) {
            return;
        }

        $hoursOld = $this->freshness->hoursSince($newest);

        if ($this->freshness->isStale($hoursOld)) {
            $this->freshness->raiseOverdue($hoursOld);
        }
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
        int $hour,
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

            $raised = $this->alerts->raiseOnceSystemWide(
                kind: $kind,
                severity: SystemAlertSeverity::Warning->value,
                message: $line->sentence(),
                metadata: StoredCopy::inParams($line) + $metadata,
                window: $hour,
            );

            if ($raised === null) {
                return;
            }

            $this->logger->warning('HealthCheckListener: '.$logMessage, $metadata);
        } catch (Throwable $e) {
            $this->logger->warning(
                'HealthCheckListener: failed to write '.$kind.' alert; continuing.',
                SafeExceptionContext::describe($e),
            );
        }
    }

    // The pass that raises is the pass that takes it down. Both lines promise
    // the reader a restart usually clears the drift, and the restart that
    // cleared the pragma has to clear the banner with it -- a banner naming a
    // remedy nothing performs teaches them to stop reading the next one.
    private function withdrawDriftAlert(string $kind): void
    {
        try {
            $closed = $this->alerts->withdrawSystemWide($kind, $this->clock->now());

            if ($closed > 0) {
                $this->logger->info(
                    'HealthCheckListener: withdrew '.$kind.'; the pragma is back at its documented default.',
                    ['closed' => $closed],
                );
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                'HealthCheckListener: failed to withdraw '.$kind.' alert; continuing.',
                SafeExceptionContext::describe($e),
            );
        }
    }
}
