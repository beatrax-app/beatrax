<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Carbon\CarbonInterface;
use Modules\DevMode\Public\Contracts\AuditWriter;

// Every method is a no-op fallback for callers that instantiate the
// contract outside the full container (e.g. ad-hoc unit tests); the
// runtime binding routes to SpatieAuditWriter instead.
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
