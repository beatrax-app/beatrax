<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * One spawn-then-tail run the Dev Console tracks.
 *
 * Persisted in the cache under `dev_mode.run.{runId}` for 24 hours
 * so a page refresh during a running command reconnects to the live
 * SSE stream rather than dropping the run. The SSE controller
 * resolves a RunRecord via {@see RunRegistry::find()} and tails the
 * `outPath` tmp file via `fseek` + `clearstatcache`.
 *
 * Cross-user inspection is rejected at the controller layer by
 * comparing `callerUserId` against the requesting developer's id —
 * defense-in-depth even though both must be developers to clear
 * EnsureDeveloperMode.
 *
 * `status` is one of:
 *   - `running`   — spawn POST returned, process still alive.
 *   - `done`      — process exited (exitCode populated, finishedAt set).
 *   - `cancelled` — operator hit the cancel endpoint; finishedAt set
 *                   when the SSE liveness check observed the gone pid.
 *   - `failed`    — reserved for explicit failure states the audit
 *                   pipeline cannot otherwise express.
 *
 * @param  array<string, mixed>  $args
 */
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
        /** 'safe' | 'destructive' */
        public readonly string $tier,
        /** 'running' | 'done' | 'cancelled' | 'failed' */
        public readonly string $status,
        public readonly string $outPath,
        public readonly ?int $exitCode = null,
        public readonly ?CarbonInterface $finishedAt = null,
    ) {}
}
