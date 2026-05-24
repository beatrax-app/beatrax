<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Modules\Core\Public\Contracts\Clock;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;

/**
 * Hook 16-04's ArtisanStreamController invokes on the `done` branch:
 * read the per-run tmp file, cap + redact it, and write the audit row
 * via AuditWriter (SpatieAuditWriter at runtime; NullAuditWriter under
 * the 16-03 contract default — which would be a no-op).
 *
 * The stream controller never knew how to "finalize" a run — it just
 * marked the run done in the RunRegistry. This hook closes the gap so
 * every SAFE-tier run that exits cleanly leaves an audit trail.
 *
 * DESTRUCTIVE runs flow through the dedicated DestructiveSpawnController
 * (also in this plan); the stream controller still tails them and still
 * calls this hook the same way — the per-run tmp file shape is
 * tier-agnostic.
 *
 * Stdout/stderr split (D-24 row shape): the spawner uses a single tmp
 * file with `> file 2>&1` redirection, so stdout and stderr are merged
 * on disk. This hook treats the entire content as `stdout_excerpt` and
 * leaves `error_excerpt` empty. Splitting them requires separate tmp
 * files (architecture change) and is deferred to v2.
 *
 * 16-05 upgrade path: when 16-05 lands the full OAuthScrubSet, the
 * RedactionExcerptCap (consumed by SpatieAuditWriter) picks it up via
 * constructor DI. This hook is unchanged across the upgrade.
 */
final readonly class FinalizeRunAudit
{
    /** Read up to 32 KiB of the tmp file so the 8 KiB cap has headroom
     *  after redaction (a Bearer-header replacement can leave the
     *  resulting text shorter than the input; reading more guarantees
     *  the cap consumes meaningful content). */
    private const READ_BYTES = 32_768;

    public function __construct(
        private AuditWriter $audit,
        private RunRegistry $registry,
        private Clock $clock,
    ) {}

    public function __invoke(string $runId, ?int $exitCode, bool $cancelled): void
    {
        $record = $this->registry->find($runId);
        if ($record === null) {
            // The cache TTL elapsed (>24h) or the runId was never
            // stored. Nothing meaningful to audit.
            return;
        }

        $excerpt = $this->readExcerpt($record->outPath);

        $finishedAt = $record->finishedAt ?? $this->clock->now();

        // Cancelled runs surface in `properties.cancelled=true` (and
        // a negative exit_code if no real exit was recorded) so the
        // audit row is a single canonical row per run, not two rows.
        // The audit-log table's `description` stays
        // AuditEvent::CommandExecuted regardless of cancel — the
        // CommandCancelled enum case exists for future per-cancel
        // bookkeeping but not for this row.
        $effectiveExit = $exitCode;
        if ($cancelled && $effectiveExit === null) {
            $effectiveExit = -15; // negative SIGTERM number convention
        }

        $this->audit->recordCommandRun(
            command: $record->command,
            args: array_merge($record->args, $cancelled ? ['__cancelled' => true] : []),
            tier: $record->tier,
            callerUserId: $record->callerUserId,
            startedAt: $record->startedAt,
            finishedAt: $finishedAt,
            exitCode: $effectiveExit,
            stdoutExcerpt: $excerpt,
            errorExcerpt: '',
        );
    }

    /**
     * Read up to READ_BYTES from the per-run tmp file. Returns empty
     * string if the file vanished between spawn + finalize (cleanup,
     * disk pressure, etc.) — the audit row still goes out so the
     * fact-of-run is captured.
     */
    private function readExcerpt(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $chunk = (string) @fread($handle, self::READ_BYTES);
        @fclose($handle);

        return $chunk;
    }
}
