<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

// One spawn-then-tail run the Dev Console tracks, persisted in cache
// under dev_mode.run.{runId} for 24 hours (RunRegistry::find()). Cross-
// user inspection is rejected at the controller layer by comparing
// callerUserId against the requesting developer's id.
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
