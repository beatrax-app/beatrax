<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Carbon\CarbonInterface;

// Cross-module audit-write seam: every Dev Console action crossing an
// operational trust boundary (artisan run, destructive queue action,
// SELECT-only SQL query) writes one row here. SpatieAuditWriter routes
// rows into dev_mode_audit; NullAuditWriter is the ad-hoc-test fallback.
interface AuditWriter
{
    // stdoutExcerpt/errorExcerpt are bounded by the calling pipeline —
    // already passed through RedactionExcerptCap's scrub + 8 KiB cap.
    // runId, when provided, lets finalizeCommandRun() locate and update
    // the same row later; one-shot legacy callers may pass null.
    /**
     * @param  array<string, mixed>  $args
     */
    public function recordCommandRun(
        string $command,
        array $args,
        string $tier,
        int $callerUserId,
        CarbonInterface $startedAt,
        ?CarbonInterface $finishedAt,
        ?int $exitCode,
        string $stdoutExcerpt,
        string $errorExcerpt,
        ?string $runId = null,
    ): void;

    // Returns true when the row was located and updated, false when no
    // matching row existed (callers fall back to a fresh
    // recordCommandRun() so the audit trail still captures the run).
    public function finalizeCommandRun(
        string $runId,
        CarbonInterface $finishedAt,
        ?int $exitCode,
        string $stdoutExcerpt,
        string $errorExcerpt,
        bool $cancelled,
    ): bool;

    // `context` is a free-form metadata bag the queue inspector
    // populates with the action's specifics (job id, batch id, etc.).
    /**
     * @param  array<string, mixed>  $context
     */
    public function recordDestructiveQueueAction(
        string $action,
        array $context,
        int $callerUserId,
    ): void;

    // `query` is the verbatim SQL the user typed (post
    // SelectOnlyValidator acceptance).
    public function recordSelectQuery(
        string $query,
        int $rowcount,
        int $durationMs,
        int $callerUserId,
    ): void;
}
