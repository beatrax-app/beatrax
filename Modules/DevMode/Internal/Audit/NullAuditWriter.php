<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Carbon\CarbonInterface;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Dto\CommandRunAudit;

// Every method is a no-op fallback for callers that instantiate the
// contract outside the full container (e.g. ad-hoc unit tests); the
// runtime binding routes to SpatieAuditWriter instead.
final class NullAuditWriter implements AuditWriter
{
    public function recordCommandRun(CommandRunAudit $run): void
    {
        // Null-object no-op: ad-hoc callers resolving the contract outside
        // the full container get a writer that silently discards the run
        // rather than touching dev_mode_audit. The real SpatieAuditWriter
        // binding persists it in every wired-up environment.
    }

    public function finalizeCommandRun(
        string $runId,
        CarbonInterface $finishedAt,
        ?int $exitCode,
        string $stdoutExcerpt,
        string $errorExcerpt,
        bool $cancelled,
    ): bool {
        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordDestructiveQueueAction(
        string $action,
        array $context,
        int $callerUserId,
    ): void {
        // Null-object no-op — see recordCommandRun(). The queue action is
        // discarded rather than written when the null writer is in scope.
    }

    public function recordSelectQuery(
        string $query,
        int $rowcount,
        int $durationMs,
        int $callerUserId,
    ): void {
        // Null-object no-op — see recordCommandRun(). The SELECT audit is
        // discarded rather than written when the null writer is in scope.
    }
}
