<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Modules\Core\Public\Contracts\Clock;
use Modules\DevMode\Internal\Process\RunExitCodeFile;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Dto\CommandRunAudit;

final readonly class FinalizeRunAudit
{
    // Read up to 32 KiB so the 8 KiB cap has headroom after redaction —
    // a Bearer-header replacement can leave the text shorter than the
    // input, so reading more guarantees the cap consumes real content.
    private const int READ_BYTES = 32_768;

    private const int CANCELLED_SIGTERM_EXIT_CODE = -15;

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

        // A cancel is a property on the same row, not a second event, so a
        // run has exactly one canonical audit row either way.
        $effectiveExit = $exitCode;
        if ($cancelled && $effectiveExit === null) {
            $effectiveExit = self::CANCELLED_SIGTERM_EXIT_CODE;
        }

        // The caller has no exit code for a detached run: the PID vanishing is
        // all it saw. The watcher subshell wrote the real one beside the
        // output file, and the run card's Failed state is keyed on it.
        $effectiveExit ??= RunExitCodeFile::read($record->outPath);

        // Merges onto the spawner's eager row via run_id, falling back to an
        // append-only write so the fact of the run is never lost.
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

        $this->audit->recordCommandRun(new CommandRunAudit(
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
        ));
    }

    // A vanished file yields '' rather than an error: the audit row goes out
    // either way.
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
