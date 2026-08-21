<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Carbon\CarbonInterface;
use Modules\DevMode\Internal\Enums\CommandTier;
use Spatie\LaravelData\Data;

// The eager spawn-time write leaves finishedAt, exitCode and the excerpts
// empty; the finalize pass fills in the outcome on the same row.
final class CommandRunAudit extends Data
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(
        public readonly string $command,
        public readonly array $args,
        public readonly CommandTier $tier,
        public readonly int $callerUserId,
        public readonly CarbonInterface $startedAt,
        public readonly ?CarbonInterface $finishedAt,
        public readonly ?int $exitCode,
        public readonly string $stdoutExcerpt,
        public readonly string $errorExcerpt,
        public readonly ?string $runId = null,
    ) {}
}
