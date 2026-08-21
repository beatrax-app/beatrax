<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Carbon\CarbonInterface;
use Modules\DevMode\Public\Dto\CommandRunAudit;

// Every Dev Console action that crosses an operational trust boundary writes
// one row through here.
interface AuditWriter
{
    // Implementations do not scrub: the excerpts must already have been
    // through RedactionExcerptCap. A non-null $runId is what lets
    // finalizeCommandRun() find this row again.
    public function recordCommandRun(CommandRunAudit $run): void;

    // False means no matching row, and callers answer that with a fresh
    // recordCommandRun() so the run is never silently unaudited.
    public function finalizeCommandRun(
        string $runId,
        CarbonInterface $finishedAt,
        ?int $exitCode,
        string $stdoutExcerpt,
        string $errorExcerpt,
        bool $cancelled,
    ): bool;

    // `context` is a free-form bag — job id, batch id, whatever the action has.
    /**
     * @param  array<string, mixed>  $context
     */
    public function recordDestructiveQueueAction(
        string $action,
        array $context,
        int $callerUserId,
    ): void;

    // `query` is the verbatim SQL the operator typed, not a normalised form.
    public function recordSelectQuery(
        string $query,
        int $rowcount,
        int $durationMs,
        int $callerUserId,
    ): void;
}
