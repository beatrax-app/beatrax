<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Carbon\CarbonInterface;

/**
 * Cross-module audit-write seam for the Dev Console.
 *
 * Every Dev Console action that crosses an operational trust
 * boundary (artisan run, destructive queue action, SELECT-only SQL
 * query) writes one row through this interface. The concrete
 * SpatieAuditWriter routes the rows through
 * spatie/laravel-activitylog into the dev_mode_audit table.
 * NullAuditWriter is a no-op fallback so consumer code can resolve
 * the contract without bound() guards.
 */
interface AuditWriter
{
    /**
     * Record a SAFE or DESTRUCTIVE artisan command run.
     *
     * `stdoutExcerpt` and `errorExcerpt` are bounded by the calling
     * pipeline — every byte stored inside the audit row has already
     * passed RedactionExcerptCap's OAuth scrub-set + Bearer + JWT
     * redaction sweep and the 8 KiB byte cap.
     *
     * `runId` is the spawn-time identifier; when provided it lands in
     * the row's properties so {@see finalizeCommandRun()} can locate
     * and update the same row when the run completes. Callers that
     * always have a finishedAt + exitCode (one-shot writes from
     * legacy paths) may pass null.
     *
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

    /**
     * Finalize a previously-eager-written command run by `runId`.
     * Returns true when the row was located and updated, false when
     * no matching row existed (callers fall back to a fresh
     * recordCommandRun() in that case so the audit trail still
     * captures the fact-of-run).
     *
     * `stdoutExcerpt` is bounded by the same redaction + byte-cap
     * pipeline as recordCommandRun().
     */
    public function finalizeCommandRun(
        string $runId,
        CarbonInterface $finishedAt,
        ?int $exitCode,
        string $stdoutExcerpt,
        string $errorExcerpt,
        bool $cancelled,
    ): bool;

    /**
     * Record a destructive queue action (retry-failed, flush-failed,
     * kill-batch). `context` is a free-form metadata bag the queue
     * inspector populates with the action's specifics (the job id, the
     * batch id, the failure reason, etc.).
     *
     * @param  array<string, mixed>  $context
     */
    public function recordDestructiveQueueAction(
        string $action,
        array $context,
        int $callerUserId,
    ): void;

    /**
     * Record a SELECT-only SQL query executed through the Read-only
     * SQL panel. `query` is the verbatim SQL the user typed (post
     * `SelectOnlyValidator` acceptance), `rowcount` is the row count
     * returned, `durationMs` is the wall-clock duration measured
     * around the query call.
     */
    public function recordSelectQuery(
        string $query,
        int $rowcount,
        int $durationMs,
        int $callerUserId,
    ): void;
}
