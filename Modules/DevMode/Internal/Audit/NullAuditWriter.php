<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Carbon\CarbonInterface;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Dto\CommandRunAudit;

// Null object for callers that construct the contract outside the container;
// the runtime binding is SpatieAuditWriter.
final class NullAuditWriter implements AuditWriter
{
    public function recordCommandRun(CommandRunAudit $run): void
    {
        // Storing nothing is what makes finalizeCommandRun()'s false honest.
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
    ): void {}

    public function recordSelectQuery(
        string $query,
        int $rowcount,
        int $durationMs,
        int $callerUserId,
    ): void {
        // The verbatim SQL is the sensitive part, so dropping it is the safe
        // default when no audit store has been wired up.
    }
}
