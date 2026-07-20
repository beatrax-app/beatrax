<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Carbon\CarbonInterface;
use Modules\DevMode\Public\Contracts\AuditWriter;

/**
 * Null-shape AuditWriter concrete. Every method is a no-op.
 *
 * Exists so consumer code can resolve the AuditWriter contract from
 * the container without `bound()` guards or null checks at every
 * call site. The runtime binding in DevModeServiceProvider routes
 * the contract to SpatieAuditWriter, which persists rows through
 * spatie/laravel-activitylog into the dev_mode_audit table; this
 * null shape is the fallback when a consumer instantiates the
 * contract outside the full container (e.g. ad-hoc unit tests).
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
        ?string $runId = null,
    ): void {}

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
    ): void {}
}
