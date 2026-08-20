<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Exceptions;

use RuntimeException;

// A spawn endpoint asked RunRegistry for the record it had just written
// and got nothing back — the cache entry vanished between start()
// persisting it and the controller re-reading it. A genuine invariant
// break (not a routine TTL miss), so it is raised distinctly.
final class SpawnedRunVanishedException extends RuntimeException
{
    public static function immediatelyAfterSpawn(string $entryPoint, string $runId): self
    {
        return new self("{$entryPoint}: RunRegistry lost record for run {$runId} immediately after spawn.");
    }
}
