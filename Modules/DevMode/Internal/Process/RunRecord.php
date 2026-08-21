<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

// callerUserId is what the stream and cancel controllers compare against the
// requesting developer to refuse cross-user inspection.
final class RunRecord extends Data
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(
        public readonly string $runId,
        public readonly int $pid,
        public readonly string $command,
        public readonly array $args,
        public readonly CarbonInterface $startedAt,
        public readonly int $callerUserId,
        public readonly string $tier,
        public readonly string $status,
        public readonly string $outPath,
        public readonly ?int $exitCode = null,
        public readonly ?CarbonInterface $finishedAt = null,
    ) {}
}
