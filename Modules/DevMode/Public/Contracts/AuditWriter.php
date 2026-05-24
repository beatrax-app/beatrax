<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Carbon\CarbonInterface;

/**
 * Cross-module audit-write seam for the Dev Console.
 *
 * Every Dev Console action that crosses an operational trust boundary
 * (artisan run, destructive queue action, SELECT-only SQL query) writes
 * one row through this interface. The concrete `SpatieAuditWriter`
 * (lands in 16-04 — audit-pipeline plan) routes the rows through
 * spatie/laravel-activitylog into the renamed `dev_mode_audit` table
 * per CONTEXT D-23 / D-24. This module's `NullAuditWriter` is a no-op
 * so the binding is non-null from day one (so consumer Livewire pages
 * can resolve the contract without `app()->bound(...)` guards).
 */
interface AuditWriter
{
    /**
     * Record a SAFE or DESTRUCTIVE artisan command run.
     *
     * `stdoutExcerpt` and `errorExcerpt` are bounded by the calling
     * pipeline (RedactionExcerptCap in 16-05) — every byte stored
     * inside the audit row has already passed the OAuth-scrub-set
     * redaction sweep.
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
    ): void;

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
