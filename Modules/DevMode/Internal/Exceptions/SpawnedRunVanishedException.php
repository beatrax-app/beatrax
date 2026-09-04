<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Exceptions;

use RuntimeException;

// The record start() had just written was gone when the spawn action re-read
// it. An invariant break, not a routine TTL miss, hence its own type.
final class SpawnedRunVanishedException extends RuntimeException
{
    public static function immediatelyAfterSpawn(string $runId): self
    {
        return new self("SpawnDevCommand: RunRegistry lost record for run {$runId} immediately after spawn.");
    }
}
