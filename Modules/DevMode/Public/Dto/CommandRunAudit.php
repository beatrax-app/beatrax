<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

// One command-run audit fact, passed whole to AuditWriter::recordCommandRun
// so the seam takes a single argument instead of ten positional ones. The
// eager spawn-time write leaves finishedAt/exitCode null and the excerpts
// empty; the finalize pass re-records with the run's outcome filled in.
final class CommandRunAudit extends Data
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(
        public readonly string $command,
        public readonly array $args,
        public readonly string $tier,
        public readonly int $callerUserId,
        public readonly CarbonInterface $startedAt,
        public readonly ?CarbonInterface $finishedAt,
        public readonly ?int $exitCode,
        public readonly string $stdoutExcerpt,
        public readonly string $errorExcerpt,
        public readonly ?string $runId = null,
    ) {}
}
