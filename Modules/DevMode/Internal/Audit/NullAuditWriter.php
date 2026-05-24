<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Carbon\CarbonInterface;
use Modules\DevMode\Public\Contracts\AuditWriter;

/**
 * Default AuditWriter concrete bound by DevModeServiceProvider.
 *
 * Every method is a no-op. The 16-04 audit-pipeline plan replaces
 * this binding with `SpatieAuditWriter`, which routes rows through
 * spatie/laravel-activitylog into the `dev_mode_audit` table. The
 * Null shape exists so consumer Livewire pages can constructor-inject
 * (or method-inject) the AuditWriter contract from day one without
 * `app()->bound(...)` guards or null-checks at every call site.
 */
final class NullAuditWriter implements AuditWriter
{
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
    ): void {
        // no-op until SpatieAuditWriter binds in 16-04.
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordDestructiveQueueAction(
        string $action,
        array $context,
        int $callerUserId,
    ): void {
        // no-op until SpatieAuditWriter binds in 16-04.
    }

    public function recordSelectQuery(
        string $query,
        int $rowcount,
        int $durationMs,
        int $callerUserId,
    ): void {
        // no-op until SpatieAuditWriter binds in 16-04.
    }
}
