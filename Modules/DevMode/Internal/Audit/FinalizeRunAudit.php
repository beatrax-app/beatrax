<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Modules\Core\Public\Contracts\Clock;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;

/**
 * @link ../../../../.docs/features/dev-mode/architecture.md
 */
final readonly class FinalizeRunAudit
{
    // Read up to 32 KiB so the 8 KiB cap has headroom after redaction —
    // a Bearer-header replacement can leave the text shorter than the
    // input, so reading more guarantees the cap consumes real content.
    private const READ_BYTES = 32_768;

    private const CANCELLED_SIGTERM_EXIT_CODE = -15;

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

        // Cancelled runs surface via `properties.cancelled=true` (and
        // a negative exit_code when none was recorded), keeping one
        // canonical audit row per run. The audit description stays
        // AuditEvent::CommandExecuted regardless of cancel status.
        $effectiveExit = $exitCode;
        if ($cancelled && $effectiveExit === null) {
            $effectiveExit = self::CANCELLED_SIGTERM_EXIT_CODE;
        }

        // CommandSpawner writes an eager audit row with exit_code=null
        // at spawn time; finalize merges properties onto that row via
        // run_id. Falls through to the legacy append-only path when no
        // eager row exists, so the fact-of-run is never silently lost.
        $updated = $this->audit->finalizeCommandRun(
            runId: $runId,
            finishedAt: $finishedAt,
            exitCode: $effectiveExit,
            stdoutExcerpt: $excerpt,
            errorExcerpt: '',
            cancelled: $cancelled,
        );

        if ($updated) {
            return;
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
            runId: $runId,
        );
    }

    // Returns empty string if the file vanished between spawn and
    // finalize — the audit row still goes out so the fact-of-run
    // is captured regardless.
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
